<?php

declare(strict_types=1);

namespace App\Testing\Check;

use App\Testing\Enum\TestSeverity as S;
use App\Testing\Enum\TestStatus;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Quality assurance of a DEPLOYED application, over HTTP: availability, speed,
 * content, SEO, pages, assets, administration, forms, security headers, mobile,
 * HTTPS certificate and DNS. Nothing is assumed: a check that cannot run is
 * reported as skipped with its reason (e.g. HTTPS/DNS on a simulated deployment).
 */
final class QaChecker
{
    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    /**
     * @param array<string, mixed> $manifest content of mzian.json
     *
     * @return list<CheckResult>
     */
    public function run(string $publicUrl, string $internalUrl, array $manifest, string $businessName, bool $simulated, ?string $domain): array
    {
        $checks = new CheckList();
        // Redirects are checked, never followed (e.g. the administration must redirect to its login page).
        $http = ($this->http ?? HttpClient::create())->withOptions(['timeout' => 15, 'max_redirects' => 0, 'no_proxy' => '*', 'headers' => ['User-Agent' => 'MzianQA/1.0']]);
        $base = rtrim($internalUrl, '/').'/';

        $start = microtime(true);
        try {
            $home = $http->request('GET', $base);
            $status = $home->getStatusCode();
            $html = $home->getContent(false);
            $headers = $home->getHeaders(false);
        } catch (\Throwable $e) {
            $checks->add('qa.availability', 'Website online', 'availability', S::Critical, TestStatus::Failed, 'The website does not answer: '.$e->getMessage());

            return $checks->all();
        }
        $elapsed = (int) round((microtime(true) - $start) * 1000);
        if (!$checks->assert(200 === $status, 'qa.availability', 'Website online', 'availability', S::Critical, 'The home page answers 200.', 'The home page answers '.$status.'.')) {
            return $checks->all();
        }
        $checks->add('qa.performance', 'Response time', 'performance', S::Minor, $elapsed < 1000 ? TestStatus::Passed : ($elapsed < 3000 ? TestStatus::Warning : TestStatus::Failed), $elapsed.' ms for the home page.', ['ms' => $elapsed]);
        $checks->assert(str_contains($html, htmlspecialchars($businessName, \ENT_QUOTES)), 'qa.content', 'Business content', 'content', S::Critical, 'The home page presents '.$businessName.'.', 'The business name is not on the home page.');

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html, \LIBXML_NOERROR | \LIBXML_NOWARNING);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);
        $missing = [];
        foreach (['title' => '//title', 'meta description' => '//meta[@name="description"]/@content', 'canonical' => '//link[@rel="canonical"]/@href', 'language' => '//html/@lang', 'Open Graph' => '//meta[@property="og:title"]/@content', 'structured data' => '//script[@type="application/ld+json"]'] as $label => $query) {
            if ('' === trim((string) $xpath->evaluate('string('.$query.')'))) {
                $missing[] = $label;
            }
        }
        $checks->assert([] === $missing, 'qa.seo', 'SEO', 'seo', S::Major, 'Title, description, canonical, language, Open Graph and structured data present.', 'Missing: '.implode(', ', $missing));

        $viewport = (string) $xpath->evaluate('string(//meta[@name="viewport"]/@content)');
        $css = $http->request('GET', $base.'assets/style.css');
        $responsive = str_contains($viewport, 'width=device-width') && 200 === $css->getStatusCode() && str_contains($css->getContent(false), '@media');
        $checks->assert($responsive, 'qa.mobile', 'Mobile friendly', 'mobile', S::Major, 'Viewport and responsive styles present.', 'The site is not mobile friendly (viewport or responsive CSS missing).');

        $failed = [];
        foreach ((array) ($manifest['pages'] ?? []) as $page) {
            $code = $http->request('GET', $base.$page)->getStatusCode();
            if (200 !== $code) {
                $failed[] = $page.' ('.$code.')';
            }
        }
        $checks->assert([] === $failed, 'qa.pages', 'All pages online', 'availability', S::Major, \count((array) ($manifest['pages'] ?? [])).' pages answer 200.', 'Unavailable: '.implode(', ', $failed));

        $assets = [];
        foreach (['assets/app.js', 'assets/favicon.svg', 'robots.txt', 'sitemap.xml'] as $asset) {
            if (200 !== $http->request('GET', $base.$asset)->getStatusCode()) {
                $assets[] = $asset;
            }
        }
        $checks->assert([] === $assets, 'qa.assets', 'Assets, robots.txt and sitemap', 'availability', S::Major, 'Scripts, icon, robots.txt and sitemap.xml are served.', 'Missing: '.implode(', ', $assets));

        $admin = $http->request('GET', $base.'admin/');
        $login = $http->request('GET', $base.'admin/index.php?action=login');
        $checks->assert(302 === $admin->getStatusCode() && 200 === $login->getStatusCode() && str_contains($login->getContent(false), 'type="password"'), 'qa.admin', 'Administration online and protected', 'application', S::Critical, 'The administration is online and asks for a login.', 'Administration: '.$admin->getStatusCode().' / login page: '.$login->getStatusCode().'.');

        $contact = $http->request('GET', $base.'contact.html')->getContent(false);
        $checks->assert(str_contains($contact, 'public=submit') && str_contains($contact, 'name="website"'), 'qa.forms', 'Contact form', 'content', S::Major, 'The contact form is present (with anti-spam protection).', 'No working contact form on the contact page.');

        $nosniff = strtolower((string) ($headers['x-content-type-options'][0] ?? ''));
        $checks->assert('nosniff' === $nosniff, 'qa.headers', 'Security headers', 'security', S::Minor, 'X-Content-Type-Options: nosniff.', 'Security headers missing (X-Content-Type-Options).', [], true);

        if ($simulated) {
            $checks->skip('qa.https', 'HTTPS certificate', 'security', S::Critical, 'Simulated deployment (preview server): no public certificate to verify. Verified on real hosting.');
            $checks->skip('qa.dns', 'Domain DNS', 'availability', S::Major, 'Simulated domain: DNS not published.');
        } else {
            try {
                $secure = str_starts_with($publicUrl, 'https://') && 200 === HttpClient::create(['timeout' => 15])->request('GET', $publicUrl)->getStatusCode();
                $checks->assert($secure, 'qa.https', 'HTTPS certificate', 'security', S::Critical, 'The site is served over HTTPS with a valid certificate.', 'The site is not served over valid HTTPS.');
            } catch (\Throwable $e) {
                $checks->add('qa.https', 'HTTPS certificate', 'security', S::Critical, TestStatus::Failed, 'HTTPS check failed: '.$e->getMessage());
            }
            if (null === $domain) {
                $checks->skip('qa.dns', 'Domain DNS', 'availability', S::Major, 'No custom domain for this project.');
            } else {
                $records = @dns_get_record($domain, \DNS_A | \DNS_AAAA | \DNS_CNAME) ?: [];
                $checks->assert([] !== $records, 'qa.dns', 'Domain DNS', 'availability', S::Major, $domain.' resolves.', $domain.' does not resolve (DNS not propagated or not configured).');
            }
        }

        return $checks->all();
    }
}
