<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Shared\Settings\SettingsService;
use App\Tests\Support\PlatformFixtureTrait;
use App\Tests\Support\WebTestCaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PricingTest extends WebTestCase
{
    use PlatformFixtureTrait;
    use WebTestCaseTrait;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->setUpPlatform();
    }

    public function testPublicPricingPageInEveryLanguage(): void
    {
        $crawler = $this->client->request('GET', '/fr/tarifs');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Tarifs transparents');
        self::assertCount(11, $crawler->filter('main ul.divide-y > li'));
        self::assertSelectorTextContains('main', 'À partir de');
        self::assertSelectorTextContains('main', 'Mzian Business');
        self::assertStringContainsString('"@type":"OfferCatalog"', $crawler->filter('script[type="application/ld+json"]')->last()->text());

        $this->client->request('GET', '/en/pricing');
        self::assertSelectorTextContains('h1', 'Transparent pricing');
        $this->client->request('GET', '/ar/pricing');
        self::assertSelectorTextContains('h1', 'أسعار شفافة');
    }

    public function testCatalogCardsAndSolutionPageShowComputedStartingPrices(): void
    {
        $crawler = $this->client->request('GET', '/en/solutions/car-rental-management');
        self::assertResponseIsSuccessful();
        // 320 dev + 72 hosting + 12 domain + 10 infra + 3 AI = 417, + margin 80 + 0.30, / (1 - 2.9 %) = 512.15 → 513.
        self::assertSelectorTextContains('main', 'From');
        self::assertStringContainsString('$513', $crawler->filter('main')->text());

        $this->client->request('GET', '/en/solutions');
        self::assertSelectorTextContains('main', '$513');
    }

    public function testPricesFollowThePolicyChangedByAnAdministrator(): void
    {
        $settings = static::getContainer()->get(SettingsService::class);
        $settings->set('pricing', ['minimum_margin' => 150, 'target_margin' => 150] + $settings->get('pricing'));

        $crawler = $this->client->request('GET', '/en/solutions/car-rental-management');
        self::assertStringContainsString('$585', $crawler->filter('main')->text());
    }

    public function testAdminSeesCostsMarginsAndSimulator(): void
    {
        $this->loginAs($this->factory()->admin());

        $crawler = $this->client->request('GET', '/admin/pricing?solution=car_rental_management&complexity=medium&options[]=pdf_contracts&options[]=statistics&domain=12');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.badge-gray', 'read only');
        self::assertSelectorTextContains('[data-testid="sim-price"]', '$690.00');
        self::assertSelectorTextContains('[data-testid="sim-margin"]', '$80.69');
        self::assertCount(0, $crawler->selectButton('Save policy'));

        // A regular administrator cannot change the margin rules.
        $this->client->request('POST', '/admin/pricing', ['form' => ['minimum_margin' => '1']]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testSuperAdminUpdatesThePolicyWithValidation(): void
    {
        $this->loginAs($this->factory()->admin('owner@example.com', super: true));
        $crawler = $this->client->request('GET', '/admin/pricing');
        $form = $crawler->selectButton('Save policy')->form();

        $this->client->submit($form, ['form[minimum_margin]' => '100', 'form[target_margin]' => '60']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'cannot be lower than the minimum margin');

        $this->client->submit($form, ['form[minimum_margin]' => '100', 'form[target_margin]' => '120', 'form[payment_fee_percent]' => '3.5']);
        self::assertResponseRedirects('/admin/pricing');
        $policy = static::getContainer()->get(SettingsService::class)->get('pricing');
        self::assertEquals(100, $policy['minimum_margin']);
        self::assertEquals(120, $policy['target_margin']);
        self::assertEquals(3.5, $policy['payment_fee_percent']);
        self::assertSame('USD', $policy['currency'], 'The currency is not editable here.');
    }
}
