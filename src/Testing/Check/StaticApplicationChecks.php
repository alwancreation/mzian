<?php

declare(strict_types=1);

namespace App\Testing\Check;

use App\Testing\Enum\TestSeverity as S;
use App\Testing\Enum\TestStatus;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Checks run on the generated sources, without a server: structure, HTML/SEO
 * basics, internal links, sitemap, PHP syntax, leaked secrets, size, feature coverage.
 */
final class StaticApplicationChecks
{
    private const MAX_PUBLIC_BYTES = 5_000_000;

    /** Patterns of credentials that must never be in a repository. */
    private const SECRET_PATTERNS = [
        'private key' => '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        'Stripe secret key' => '/\b(sk|rk)_(live|test)_[0-9a-zA-Z]{10,}/',
        'GitHub token' => '/\b(ghp|gho|ghs|github_pat)_[0-9A-Za-z_]{20,}/',
        'AWS access key' => '/\bAKIA[0-9A-Z]{16}\b/',
        'OpenAI key' => '/\bsk-(proj-)?[A-Za-z0-9]{20,}/',
        'Anthropic key' => '/\bsk-ant-[A-Za-z0-9-]{20,}/',
        'password hash' => '/\$2y\$1\d\$[.\/A-Za-z0-9]{53}|\$argon2id?\$/',
    ];

    /**
     * @return list<CheckResult>
     */
    public function run(string $dir): array
    {
        $checks = new CheckList();
        $manifest = $this->json($dir.'/mzian.json');
        $schema = $this->json($dir.'/app/schema.json');
        if (!$checks->assert(null !== $manifest && null !== $schema, 'structure.manifest', 'Manifest and schema', 'structure', S::Critical, 'mzian.json and app/schema.json are valid JSON.', 'mzian.json or app/schema.json is missing or invalid.')) {
            return $checks->all();
        }
        $required = ['public/index.html', 'public/robots.txt', 'public/sitemap.xml', 'public/assets/style.css', 'public/assets/app.js', 'public/admin/index.php', 'app/MzianApp.php', 'app/bootstrap.php', 'README.md'];
        $missing = array_values(array_filter($required, static fn ($f) => !is_file($dir.'/'.$f)));
        $checks->assert([] === $missing, 'structure.files', 'Required files', 'structure', S::Critical, \count($required).' required files present.', 'Missing: '.implode(', ', $missing), ['missing' => $missing]);
        $checks->assert(!is_file($dir.'/app/config.php'), 'security.no_config', 'No runtime configuration in the sources', 'security', S::Critical, 'app/config.php is not part of the sources (written at deployment).', 'app/config.php must never be committed.');

        $this->pages($dir, (array) $manifest['pages'], $checks);
        $this->sitemap($dir, (array) $manifest['pages'], $checks);
        $this->php($dir, $checks);
        $this->secrets($dir, $checks);
        $this->schema($schema, $manifest, $dir, $checks);

        $size = 0;
        foreach ((new Finder())->files()->in($dir.'/public') as $file) {
            $size += $file->getSize();
        }
        $checks->assert($size <= self::MAX_PUBLIC_BYTES, 'performance.weight', 'Website weight', 'performance', S::Minor, \sprintf('%d KB published.', intdiv($size, 1024)), \sprintf('%d KB published: too heavy for mobile visitors.', intdiv($size, 1024)), ['bytes' => $size]);

        $features = (array) ($manifest['features'] ?? []);
        $configuration = (array) ($features['configuration'] ?? []);
        $custom = (array) ($features['custom'] ?? []);
        $checks->add('features.coverage', 'Feature coverage', 'features', S::Minor, [] === $custom && [] === $configuration ? TestStatus::Passed : TestStatus::Warning,
            \sprintf('%d implemented, %d to configure, %d for the Mzian developers.', \count((array) ($features['implemented'] ?? [])), \count($configuration), \count($custom)),
            ['configuration' => array_keys($configuration), 'custom' => $custom]);

        return $checks->all();
    }

    /**
     * @param list<string> $pages
     */
    private function pages(string $dir, array $pages, CheckList $checks): void
    {
        $missing = [];
        $seo = [];
        $links = [];
        $a11y = [];
        foreach ($pages as $page) {
            $file = $dir.'/public/'.$page;
            if (!is_file($file)) {
                $missing[] = $page;
                continue;
            }
            $dom = new \DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML((string) file_get_contents($file), \LIBXML_NOERROR | \LIBXML_NOWARNING);
            libxml_clear_errors();
            $xpath = new \DOMXPath($dom);
            if ('' === trim((string) $xpath->evaluate('string(//title)'))) {
                $seo[] = $page.': no <title>';
            }
            if ('' === trim((string) $xpath->evaluate('string(//meta[@name="description"]/@content)'))) {
                $seo[] = $page.': no meta description';
            }
            if ('' === (string) $xpath->evaluate('string(//meta[@name="viewport"]/@content)')) {
                $seo[] = $page.': no viewport (not mobile friendly)';
            }
            if ('' === (string) $xpath->evaluate('string(//html/@lang)')) {
                $a11y[] = $page.': no lang attribute';
            }
            if (0 === $xpath->query('//h1')?->length) {
                $a11y[] = $page.': no <h1>';
            }
            foreach ($xpath->query('//img[not(@alt) or @alt=""]') ?: [] as $img) {
                $a11y[] = $page.': image without alt text';
            }
            foreach ($xpath->query('//a/@href | //link/@href | //script/@src | //img/@src') ?: [] as $attribute) {
                $target = (string) $attribute->nodeValue;
                if ('' === $target || preg_match('#^([a-z]+:|//|\#)#i', $target) || str_contains($target, 'admin/')) {
                    continue;
                }
                $path = \dirname($file).'/'.explode('?', explode('#', $target)[0])[0];
                if (str_ends_with($path, '/')) {
                    $path .= 'index.html';
                }
                if (!file_exists($path)) {
                    $links[] = $page.' → '.$target;
                }
            }
        }
        $checks->assert([] === $missing, 'pages.exist', 'Pages generated', 'structure', S::Critical, \count($pages).' pages generated.', 'Missing pages: '.implode(', ', $missing));
        $checks->assert([] === $seo, 'seo.basics', 'SEO and mobile basics', 'seo', S::Major, 'Every page has a title, a description and a viewport.', implode('; ', \array_slice($seo, 0, 10)), ['issues' => $seo]);
        $checks->assert([] === array_unique($links), 'pages.links', 'Internal links', 'structure', S::Major, 'All internal links and assets resolve.', 'Broken: '.implode(', ', \array_slice(array_unique($links), 0, 10)), ['broken' => array_values(array_unique($links))]);
        $checks->assert([] === $a11y, 'a11y.basics', 'Accessibility basics', 'accessibility', S::Minor, 'Language, headings and image alternatives present.', implode('; ', \array_slice($a11y, 0, 10)), ['issues' => $a11y], true);
    }

    /**
     * @param list<string> $pages
     */
    private function sitemap(string $dir, array $pages, CheckList $checks): void
    {
        $xml = @simplexml_load_file($dir.'/public/sitemap.xml');
        $count = false !== $xml ? \count($xml->children()) : 0;
        $expected = \count(array_filter($pages, static fn ($p) => !str_ends_with($p, 'checkout.html')));
        $checks->assert(false !== $xml && $count === $expected, 'seo.sitemap', 'Sitemap', 'seo', S::Minor, $count.' URLs in sitemap.xml.', \sprintf('sitemap.xml is invalid or lists %d URLs instead of %d.', $count, $expected));
    }

    private function php(string $dir, CheckList $checks): void
    {
        $php = (new PhpExecutableFinder())->find(false) ?: 'php';
        $errors = [];
        $count = 0;
        foreach ((new Finder())->files()->in($dir)->name('*.php')->ignoreDotFiles(false) as $file) {
            ++$count;
            $process = new Process([$php, '-l', $file->getPathname()]);
            $process->setTimeout(30);
            $process->run();
            if (!$process->isSuccessful()) {
                $errors[] = $file->getRelativePathname().': '.trim($process->getOutput().$process->getErrorOutput());
            }
        }
        $checks->assert($count > 0 && [] === $errors, 'code.php_syntax', 'PHP syntax', 'code', S::Critical, $count.' PHP files compile.', [] === $errors ? 'No PHP file found.' : implode('; ', $errors), ['errors' => $errors]);
    }

    private function secrets(string $dir, CheckList $checks): void
    {
        $found = [];
        foreach ((new Finder())->files()->in($dir)->ignoreDotFiles(false)->notPath('#^\.git/#')->size('< 2M') as $file) {
            $content = $file->getContents();
            foreach (self::SECRET_PATTERNS as $label => $pattern) {
                if (preg_match($pattern, $content)) {
                    $found[] = $file->getRelativePathname().' ('.$label.')';
                }
            }
        }
        $checks->assert([] === $found, 'security.secrets', 'No secret in the sources', 'security', S::Critical, 'No credential, key or password hash found in the sources.', 'Secrets found: '.implode(', ', $found), ['files' => $found]);
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $manifest
     */
    private function schema(array $schema, array $manifest, string $dir, CheckList $checks): void
    {
        $issues = [];
        foreach ((array) ($schema['entities'] ?? []) as $code => $entity) {
            if ([] === (array) ($entity['fields'] ?? [])) {
                $issues[] = $code.': no field';
            }
            foreach ((array) ($entity['list'] ?? []) as $column) {
                if (!\in_array($column, array_column((array) $entity['fields'], 'name'), true)) {
                    $issues[] = $code.': unknown list column '.$column;
                }
            }
        }
        $index = (string) @file_get_contents($dir.'/public/contact.html');
        if (!str_contains($index, 'entity=messages')) {
            $issues[] = 'contact page: no contact form';
        }
        $checks->assert([] === $issues && [] !== (array) ($schema['entities'] ?? []), 'app.schema', 'Management application schema', 'application', S::Critical, \count((array) $schema['entities']).' entities: '.implode(', ', array_keys((array) $schema['entities'])).'.', implode('; ', $issues) ?: 'No entity.', ['entities' => array_keys((array) ($schema['entities'] ?? [])), 'manifest_entities' => $manifest['entities'] ?? []]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function json(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);

        return \is_array($data) ? $data : null;
    }
}
