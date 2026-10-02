<?php

declare(strict_types=1);

namespace App\Tests\Integration\Development;

use App\Development\Generator\ApplicationGenerator;
use App\Development\Generator\GenerationRequest;
use App\Development\Generator\TemplateCatalog;
use App\Testing\Check\CheckResult;
use App\Testing\Check\SmokeTester;
use App\Testing\Check\StaticApplicationChecks;
use App\Testing\Enum\TestStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Every template generates an application that passes the real checks
 * (static analysis + running it and driving it over HTTP).
 */
final class ApplicationGeneratorTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    private string $output;

    protected function setUp(): void
    {
        $this->output = sys_get_temp_dir().'/mzian-gen-test-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->output);
    }

    private function generator(): ApplicationGenerator
    {
        return new ApplicationGenerator(new TemplateCatalog(self::ROOT.'/resources/application-templates'));
    }

    /**
     * @param list<string> $features
     */
    private function request(string $template, array $features, string $locale = 'fr'): GenerationRequest
    {
        $content = ['tagline' => 'Atlas, votre partenaire', 'about' => 'Atlas vous accueille depuis 2010 avec une équipe expérimentée.', 'services' => [['title' => 'Service', 'description' => 'Un service de qualité.']], 'call_to_action' => 'Contactez-nous', 'seo_title' => 'Atlas — Marrakech', 'seo_description' => 'Atlas à Marrakech : services de qualité et réservation en ligne.'];

        return new GenerationRequest($template, 'atlas-'.$template, 'Atlas & Co', 'Marrakech', $locale, $features, [$locale => $content], 'https://atlas.example', 'MAD', '+212 600 00 00 00', 'contact@atlas.example');
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function templates(): iterable
    {
        yield 'car rental management' => ['car-rental', ['vehicle_management', 'customer_management', 'online_reservations', 'contracts', 'pdf_contracts', 'statistics']];
        yield 'restaurant' => ['restaurant', ['reviews', 'whatsapp_button']];
        yield 'booking' => ['booking', ['rooms', 'online_booking', 'email_confirmations', 'appointments', 'services_catalog', 'staff_schedule']];
        yield 'mini crm' => ['mini-crm', ['sales_pipeline', 'invoicing']];
        yield 'e-commerce' => ['ecommerce', ['newsletter']];
        yield 'website' => ['website-starter', ['gallery', 'blog', 'events', 'quote_requests', 'property_listings']];
        yield 'custom' => ['custom', ['customer_management', 'tasks']];
    }

    /**
     * @param list<string> $features
     */
    #[DataProvider('templates')]
    public function testGeneratedApplicationPassesStaticAndRuntimeChecks(string $template, array $features): void
    {
        $result = $this->generator()->generate($this->request($template, $features), $this->output);

        $checks = [...(new StaticApplicationChecks())->run($this->output), ...(new SmokeTester(self::ROOT.'/docker/apps/router.php'))->run($this->output, 'atlas-'.$template)];
        $failed = array_filter($checks, static fn (CheckResult $c) => TestStatus::Failed === $c->status);
        self::assertSame([], array_map(static fn (CheckResult $c) => $c->code.': '.$c->message, array_values($failed)));
        self::assertGreaterThan(15, \count($checks));
        self::assertContains('messages', $result->entities, 'Every application stores contact requests.');
        self::assertSame([], $result->coverage['custom'], 'All requested features of this test are implemented.');
    }

    public function testQaOfADeployedApplication(): void
    {
        $result = $this->generator()->generate($this->request('car-rental', ['online_reservations']), $this->output);
        $deployments = $this->output.'-deployments';
        $fs = new Filesystem();
        $fs->mirror($this->output, $deployments.'/atlas-qa/releases/v1');
        $fs->dumpFile($deployments.'/atlas-qa/current', 'v1');
        $fs->dumpFile($deployments.'/atlas-qa/releases/v1/app/config.php', "<?php return ['admin_email' => 'a@b.c', 'admin_password_hash' => '', 'data_dir' => '../../../data'];");
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $server = new \Symfony\Component\Process\Process([\PHP_BINARY, '-S', $name, self::ROOT.'/docker/apps/router.php'], null, ['MZIAN_DEPLOYMENTS_DIR' => $deployments]);
        $server->start();
        usleep(400_000);
        try {
            $checks = (new \App\Testing\Check\QaChecker())->run('http://'.$name.'/atlas-qa/', 'http://'.$name.'/atlas-qa/', $result->manifest, 'Atlas & Co', true, 'atlas.example');
        } finally {
            $server->stop(1);
            $fs->remove($deployments);
        }
        $statuses = [];
        foreach ($checks as $check) {
            $statuses[$check->code] = $check->status;
        }

        self::assertNotContains(TestStatus::Failed, $statuses, (string) json_encode(array_map(static fn (CheckResult $c) => $c->message, $checks)));
        self::assertSame(TestStatus::Skipped, $statuses['qa.https'], 'Never pretend HTTPS was verified on a simulated deployment.');
        self::assertSame(TestStatus::Skipped, $statuses['qa.dns']);
        self::assertSame(TestStatus::Passed, $statuses['qa.admin']);
    }

    public function testQaFailsWhenTheSiteIsDown(): void
    {
        $checks = (new \App\Testing\Check\QaChecker())->run('http://127.0.0.1:9/x/', 'http://127.0.0.1:9/x/', ['pages' => []], 'X', true, null);

        self::assertCount(1, $checks);
        self::assertTrue($checks[0]->isCriticalFailure());
    }

    public function testUnsupportedFeaturesAreListedNeverFaked(): void
    {
        $result = $this->generator()->generate($this->request('car-rental', ['e_signature', 'vehicle_tracking', 'online_payments']), $this->output);

        self::assertSame(['e_signature', 'vehicle_tracking'], $result->coverage['custom']);
        self::assertArrayHasKey('online_payments', $result->coverage['configuration']);
        $readme = (string) file_get_contents($this->output.'/README.md');
        self::assertStringContainsString('🛠️ e_signature — not generated automatically', $readme);
        $manifest = json_decode((string) file_get_contents($this->output.'/mzian.json'), true);
        self::assertSame(['e_signature', 'vehicle_tracking'], $manifest['features']['custom']);
    }

    public function testMultilingualSitesAreGeneratedInThreeLanguagesWithRtlArabic(): void
    {
        $result = $this->generator()->generate($this->request('restaurant', ['multilingual'], 'en'), $this->output);

        self::assertSame(['en', 'fr', 'ar'], $result->languages);
        self::assertContains('ar/menu.html', $result->pages);
        $arabic = (string) file_get_contents($this->output.'/public/ar/index.html');
        self::assertStringContainsString('<html lang="ar" dir="rtl">', $arabic);
        self::assertStringContainsString('href="../assets/style.css"', $arabic);
        self::assertStringContainsString('hreflang="fr" href="https://atlas.example/fr/"', (string) file_get_contents($this->output.'/public/index.html'));
        $schema = json_decode((string) file_get_contents($this->output.'/app/schema.json'), true);
        self::assertSame('Menu', $schema['entities']['menu_items']['label'], 'The administration uses the project language.');
    }

    public function testUserDataIsEscaped(): void
    {
        $request = new GenerationRequest('website-starter', 'xss-test', '<script>alert(1)</script> & Co', '"Fès"', 'fr', [], ['fr' => ['tagline' => '<img src=x onerror=alert(1)>', 'about' => 'About us text long enough.', 'services' => [], 'call_to_action' => 'Go', 'seo_title' => 'Title of the site', 'seo_description' => 'Description of the website for search engines.']], 'https://x.example', 'MAD');
        $this->generator()->generate($request, $this->output);

        $html = (string) file_get_contents($this->output.'/public/index.html');
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testChecksCatchLeakedSecretsBrokenLinksAndBrokenCode(): void
    {
        $this->generator()->generate($this->request('website-starter', []), $this->output);
        file_put_contents($this->output.'/public/about.html', str_replace('</main>', '<a href="missing.html">x</a></main>', (string) file_get_contents($this->output.'/public/about.html')));
        file_put_contents($this->output.'/public/assets/config.js', 'const key = "sk_live_'.str_repeat('a', 24).'";');
        file_put_contents($this->output.'/app/Broken.php', '<?php function (');

        $results = [];
        foreach ((new StaticApplicationChecks())->run($this->output) as $check) {
            $results[$check->code] = $check->status;
        }

        self::assertSame(TestStatus::Failed, $results['security.secrets']);
        self::assertSame(TestStatus::Failed, $results['pages.links']);
        self::assertSame(TestStatus::Failed, $results['code.php_syntax']);
        self::assertSame(TestStatus::Passed, $results['seo.basics']);
    }

    public function testRegenerationIsDeterministic(): void
    {
        $this->generator()->generate($this->request('booking', ['rooms']), $this->output);
        $first = md5_file($this->output.'/public/rooms.html').md5_file($this->output.'/app/schema.json');
        $this->generator()->generate($this->request('booking', ['rooms']), $this->output);

        self::assertSame($first, md5_file($this->output.'/public/rooms.html').md5_file($this->output.'/app/schema.json'));
    }
}
