<?php

declare(strict_types=1);

namespace App\Testing\Check;

use App\Testing\Enum\TestSeverity as S;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Runs the generated application for real, in an isolated PHP built-in server
 * (same router as the "apps" server), with a throw-away database, and drives
 * it over HTTP: pages, assets, JSON feeds, admin login (CSRF), CRUD on every
 * entity, every public form, and the protection of secrets.
 */
final class SmokeTester
{
    private const ADMIN_EMAIL = 'qa@mzian.test';

    public function __construct(
        #[Autowire('%kernel.project_dir%/docker/apps/router.php')]
        private readonly string $router,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * @return list<CheckResult>
     */
    public function run(string $dir, string $slug): array
    {
        $checks = new CheckList();
        $manifest = json_decode((string) @file_get_contents($dir.'/mzian.json'), true);
        $schema = json_decode((string) @file_get_contents($dir.'/app/schema.json'), true);
        if (!\is_array($manifest) || !\is_array($schema)) {
            $checks->add('smoke.setup', 'Smoke test setup', 'runtime', S::Critical, \App\Testing\Enum\TestStatus::Failed, 'Manifest or schema missing: the application cannot be started.');

            return $checks->all();
        }

        $sandbox = sys_get_temp_dir().'/mzian-smoke-'.bin2hex(random_bytes(5));
        $password = bin2hex(random_bytes(12));
        $server = null;
        try {
            $release = $sandbox.'/'.$slug.'/releases/test';
            $this->filesystem->mirror($dir, $release);
            $this->filesystem->remove($release.'/.git');
            $this->filesystem->dumpFile($sandbox.'/'.$slug.'/current', 'test');
            $this->filesystem->dumpFile($release.'/app/config.php', '<?php return '.var_export([
                'admin_email' => self::ADMIN_EMAIL,
                'admin_password_hash' => password_hash($password, \PASSWORD_DEFAULT),
                'notify_email' => '',
                'timezone' => 'UTC',
                'data_dir' => '../../../data',
            ], true).";\n");

            $port = $this->freePort();
            $server = new Process([(new PhpExecutableFinder())->find(false) ?: 'php', '-S', '127.0.0.1:'.$port, $this->router], null, ['MZIAN_DEPLOYMENTS_DIR' => $sandbox]);
            $server->start();
            $http = HttpClient::create(['max_redirects' => 0, 'timeout' => 10, 'no_proxy' => '*']);
            $base = 'http://127.0.0.1:'.$port.'/'.$slug.'/';
            if (!$checks->assert($this->waitUntilUp($http, 'http://127.0.0.1:'.$port.'/healthz'), 'smoke.start', 'Application starts', 'runtime', S::Critical, 'The application server started.', 'The application server did not start: '.trim($server->getErrorOutput()))) {
                return $checks->all();
            }

            $this->publicSite($http, $base, (array) $manifest, $checks);
            $session = $this->admin($http, $base, $password, $checks);
            if (null !== $session) {
                $this->entities($http, $base, $session, (array) $schema['entities'], $checks);
                $this->publicForms($http, $base, $session, (array) $schema['entities'], $checks);
            }
            $this->security($http, $base, $checks);

            $log = $server->getErrorOutput();
            $phpErrors = preg_match_all('/PHP (Warning|Fatal error|Parse error|Deprecated|Notice)[^\n]*/', $log, $m) ? array_values(array_unique($m[0])) : [];
            $checks->assert([] === $phpErrors, 'runtime.php_errors', 'No PHP error at runtime', 'runtime', S::Major, 'No PHP warning or error during the tests.', implode('; ', \array_slice($phpErrors, 0, 5)), ['errors' => $phpErrors]);
        } finally {
            $server?->stop(2);
            $this->filesystem->remove($sandbox);
        }

        return $checks->all();
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function publicSite(HttpClientInterface $http, string $base, array $manifest, CheckList $checks): void
    {
        $home = $http->request('GET', $base);
        $body = $home->getContent(false);
        $checks->assert(200 === $home->getStatusCode() && str_contains($body, htmlspecialchars((string) $manifest['name'], \ENT_QUOTES)), 'site.home', 'Home page', 'website', S::Critical, 'Home page served with the business name.', 'Home page returned '.$home->getStatusCode().' or does not show the business name.');

        $failed = [];
        foreach ((array) $manifest['pages'] as $page) {
            $status = $http->request('GET', $base.$page)->getStatusCode();
            if (200 !== $status) {
                $failed[] = $page.' ('.$status.')';
            }
        }
        foreach (['assets/style.css', 'assets/app.js', 'robots.txt', 'sitemap.xml', 'site.webmanifest'] as $asset) {
            if (200 !== $http->request('GET', $base.$asset)->getStatusCode()) {
                $failed[] = $asset;
            }
        }
        $checks->assert([] === $failed, 'site.pages', 'Pages and assets served', 'website', S::Critical, \count((array) $manifest['pages']).' pages and assets answer 200.', 'Not served: '.implode(', ', $failed));
        $checks->assert(404 === $http->request('GET', $base.'does-not-exist.html')->getStatusCode(), 'site.not_found', 'Unknown pages return 404', 'website', S::Minor, 'Unknown URLs answer 404.', 'Unknown URLs do not answer 404.');
    }

    private function admin(HttpClientInterface $http, string $base, string $password, CheckList $checks): ?string
    {
        $login = $http->request('GET', $base.'admin/index.php?action=login');
        $html = $login->getContent(false);
        $cookie = $this->cookie($login);
        $csrf = $this->csrf($html);
        if (!$checks->assert(200 === $login->getStatusCode() && null !== $csrf && null !== $cookie, 'admin.login_page', 'Administration login page', 'application', S::Critical, 'Login page served with a CSRF token.', 'Login page unavailable ('.$login->getStatusCode().').')) {
            return null;
        }

        $noCsrf = $http->request('POST', $base.'admin/index.php?action=login', ['headers' => ['Cookie' => $cookie], 'body' => ['email' => self::ADMIN_EMAIL, 'password' => $password]]);
        $checks->assert(403 === $noCsrf->getStatusCode(), 'admin.csrf', 'Login requires a CSRF token', 'security', S::Major, 'Forms without CSRF token are refused.', 'A login without CSRF token was not refused ('.$noCsrf->getStatusCode().').');
        $wrong = $http->request('POST', $base.'admin/index.php?action=login', ['headers' => ['Cookie' => $cookie], 'body' => ['_csrf' => $csrf, 'email' => self::ADMIN_EMAIL, 'password' => 'wrong-password']]);
        $checks->assert(422 === $wrong->getStatusCode(), 'admin.wrong_password', 'Wrong password refused', 'security', S::Critical, 'A wrong password is refused.', 'A wrong password was not refused ('.$wrong->getStatusCode().').');

        $ok = $http->request('POST', $base.'admin/index.php?action=login', ['headers' => ['Cookie' => $cookie], 'body' => ['_csrf' => $csrf, 'email' => self::ADMIN_EMAIL, 'password' => $password]]);
        $session = $this->cookie($ok) ?? $cookie;
        $dashboard = $http->request('GET', $base.'admin/', ['headers' => ['Cookie' => $session]]);
        if (!$checks->assert(302 === $ok->getStatusCode() && 200 === $dashboard->getStatusCode() && str_contains($dashboard->getContent(false), 'action=logout'), 'admin.login', 'Administrator login', 'application', S::Critical, 'The administrator logs in and sees the dashboard.', 'Login failed (status '.$ok->getStatusCode().', dashboard '.$dashboard->getStatusCode().').')) {
            return null;
        }

        return $session;
    }

    /**
     * @param array<string, array<string, mixed>> $entities
     */
    private function entities(HttpClientInterface $http, string $base, string $session, array $entities, CheckList $checks): void
    {
        $failed = [];
        foreach ($entities as $code => $entity) {
            $form = $http->request('GET', $base.'admin/index.php?entity='.$code.'&action=new', ['headers' => ['Cookie' => $session]]);
            $csrf = $this->csrf($form->getContent(false));
            $values = ['_csrf' => (string) $csrf];
            $marker = 'QA-'.strtoupper(substr($code, 0, 3)).'-'.random_int(1000, 9999);
            foreach ((array) $entity['fields'] as $field) {
                $values[$field['name']] = $this->sample($field, $marker);
            }
            $create = $http->request('POST', $base.'admin/index.php?entity='.$code.'&action=new', ['headers' => ['Cookie' => $session], 'body' => $values]);
            $list = $http->request('GET', $base.'admin/index.php?entity='.$code.'&q='.rawurlencode($marker), ['headers' => ['Cookie' => $session]]);
            if (302 !== $create->getStatusCode() || !str_contains($list->getContent(false), $marker)) {
                $failed[] = $code.' (create '.$create->getStatusCode().')';
            }
            if (true === ($entity['printable'] ?? false) && 200 !== $http->request('GET', $base.'admin/index.php?entity='.$code.'&action=print&id=1', ['headers' => ['Cookie' => $session]])->getStatusCode()) {
                $failed[] = $code.' (print)';
            }
            if (null !== ($entity['calendar'] ?? null) && 200 !== $http->request('GET', $base.'admin/index.php?entity='.$code.'&action=calendar', ['headers' => ['Cookie' => $session]])->getStatusCode()) {
                $failed[] = $code.' (calendar)';
            }
            if (true === ($entity['public_list'] ?? false)) {
                $feed = $http->request('GET', $base.'admin/index.php?public=feed&entity='.$code);
                if (200 !== $feed->getStatusCode() || !\is_array(json_decode($feed->getContent(false), true)['items'] ?? null)) {
                    $failed[] = $code.' (public feed)';
                }
            }
        }
        $checks->assert([] === $failed, 'admin.crud', 'Management features work', 'application', S::Critical, \count($entities).' entities: create, list, search (and print, calendar, feeds) work.', 'Failing: '.implode(', ', $failed), ['failed' => $failed]);
    }

    /**
     * @param array<string, array<string, mixed>> $entities
     */
    private function publicForms(HttpClientInterface $http, string $base, string $session, array $entities, CheckList $checks): void
    {
        $failed = [];
        $forms = 0;
        foreach ($entities as $code => $entity) {
            if (true !== ($entity['public_form'] ?? false)) {
                continue;
            }
            ++$forms;
            $marker = 'WEB-'.strtoupper(substr($code, 0, 3)).'-'.random_int(1000, 9999);
            $values = ['_return' => '../index.html', 'website' => ''];
            foreach ((array) $entity['fields'] as $field) {
                if (\in_array($field['name'], (array) $entity['form_fields'], true)) {
                    $values[$field['name']] = $this->sample($field, $marker);
                }
            }
            $submit = $http->request('POST', $base.'admin/index.php?public=submit&entity='.$code, ['body' => $values]);
            $location = (string) ($submit->getHeaders(false)['location'][0] ?? '');
            $stored = str_contains($http->request('GET', $base.'admin/index.php?entity='.$code.'&q='.rawurlencode($marker), ['headers' => ['Cookie' => $session]])->getContent(false), $marker);
            if (302 !== $submit->getStatusCode() || !str_contains($location, 'sent=1') || !$stored) {
                $failed[] = $code;
            }
        }
        if (0 === $forms) {
            $checks->skip('site.forms', 'Public forms', 'website', S::Critical, 'No public form in this application.');

            return;
        }
        $checks->assert([] === $failed, 'site.forms', 'Public forms', 'website', S::Critical, $forms.' public form(s) store the requests in the administration.', 'Not working: '.implode(', ', $failed));
    }

    private function security(HttpClientInterface $http, string $base, CheckList $checks): void
    {
        $exposed = [];
        foreach (['app/config.php', 'app/schema.json', 'app/MzianApp.php', 'mzian.json', 'admin/index.php.bak', '../data/app.sqlite'] as $path) {
            $response = $http->request('GET', $base.$path);
            if (200 === $response->getStatusCode()) {
                $exposed[] = $path;
            }
        }
        $checks->assert([] === $exposed, 'security.exposure', 'Secrets and data not exposed', 'security', S::Critical, 'Configuration, code and database are not reachable over HTTP.', 'Exposed: '.implode(', ', $exposed));
        $anonymous = $http->request('GET', $base.'admin/index.php?entity=messages');
        $checks->assert(302 === $anonymous->getStatusCode(), 'security.admin_auth', 'Administration requires a login', 'security', S::Critical, 'Anonymous visitors are redirected to the login page.', 'The administration answered '.$anonymous->getStatusCode().' to an anonymous visitor.');
    }

    /**
     * @param array<string, mixed> $field
     */
    private function sample(array $field, string $marker): string
    {
        return match ($field['type']) {
            'email' => 'qa@example.com',
            'tel' => '+212600000000',
            'number' => '2',
            'money' => '10.50',
            'date' => date('Y-m-d', strtotime('+3 days')),
            'time' => '10:30',
            'boolean' => '1',
            'select' => (string) array_key_first((array) $field['options']),
            'textarea' => $marker.' automated test',
            default => $marker,
        };
    }

    private function csrf(string $html): ?string
    {
        return preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $html, $m) ? $m[1] : null;
    }

    private function cookie(ResponseInterface $response): ?string
    {
        foreach ($response->getHeaders(false)['set-cookie'] ?? [] as $header) {
            if (preg_match('/^(MZIANADMIN=[^;]+)/', $header, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    private function waitUntilUp(HttpClientInterface $http, string $url): bool
    {
        for ($i = 0; $i < 50; ++$i) {
            try {
                if (200 === $http->request('GET', $url, ['timeout' => 1])->getStatusCode()) {
                    return true;
                }
            } catch (\Throwable) {
            }
            usleep(100_000);
        }

        return false;
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (false === $socket) {
            throw new \RuntimeException('No free port: '.$error);
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
