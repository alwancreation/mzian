<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Catalog\Entity\Solution;
use App\Tests\Support\CatalogFixtureTrait;
use App\Tests\Support\WebTestCaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

final class CatalogPagesTest extends WebTestCase
{
    use CatalogFixtureTrait;
    use WebTestCaseTrait;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->importCatalog();
    }

    public function testSeoSolutionPagesExistForTheRequiredSlugs(): void
    {
        foreach (['/fr/solutions/site-vitrine', '/fr/solutions/location-voiture', '/fr/solutions/restaurant', '/fr/solutions/reservation', '/fr/solutions/mini-crm'] as $url) {
            $crawler = $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
            self::assertCount(1, $crawler->filter('h1'));
            self::assertStringContainsString('"@type":"Service"', $crawler->filter('script[type="application/ld+json"]')->text());
            self::assertStringEndsWith($url, (string) $crawler->filter('link[rel="canonical"]')->attr('href'));
        }
    }

    public function testLocalizedSlugsAndRedirects(): void
    {
        $crawler = $this->client->request('GET', '/en/solutions/car-rental-website');
        self::assertResponseIsSuccessful();
        self::assertStringEndsWith('/fr/solutions/location-voiture', (string) $crawler->filter('link[hreflang="fr"]')->attr('href'));

        $this->client->request('GET', '/fr/solutions/car-rental-website');
        self::assertResponseRedirects('/fr/solutions/location-voiture', 301);

        $this->client->request('GET', '/fr/solutions/does-not-exist');
        self::assertResponseStatusCodeSame(404);
    }

    public function testIndexesHomeIndustriesSitemapAndRobots(): void
    {
        foreach (['/fr/', '/en/solutions', '/ar/solutions', '/fr/secteurs', '/en/industries/car-rental'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }

        $this->client->request('GET', '/sitemap.xml');
        self::assertResponseIsSuccessful();
        $xml = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('/fr/solutions/location-voiture</loc>', $xml);
        self::assertStringContainsString('hreflang="ar"', $xml);

        $this->client->request('GET', '/robots.txt');
        self::assertStringContainsString('Disallow: /admin', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('Sitemap: http://localhost/sitemap.xml', (string) $this->client->getResponse()->getContent());
    }

    public function testDisabledSolutionsDisappearFromThePublicSite(): void
    {
        $this->loginAs($this->factory()->admin());
        $solution = $this->em()->getRepository(Solution::class)->findOneBy(['code' => 'mini_crm']);

        $crawler = $this->client->request('GET', '/admin/catalog/solutions/'.$solution->getId());
        $form = $crawler->selectButton('Save solution')->form();
        $enabled = $form['form[enabled]'];
        self::assertInstanceOf(ChoiceFormField::class, $enabled);
        $enabled->untick();
        $form['form[basePrice]'] = '275.50';
        $this->client->submit($form);
        self::assertResponseRedirects();

        $solution = $this->em()->getRepository(Solution::class)->findOneBy(['code' => 'mini_crm']);
        self::assertSame(27550, $solution->getBasePrice());
        self::assertFalse($solution->isEnabled());

        $this->client->request('GET', '/fr/solutions/mini-crm');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAdminCanAddAConditionalQuestionWithoutCode(): void
    {
        $this->loginAs($this->factory()->admin());
        $crawler = $this->client->request('GET', '/admin/catalog/questions/new?sector=restaurant');
        $form = $crawler->selectButton('Save')->form([
            'form[code]' => 'terrace',
            'form[label][fr]' => 'Avez-vous une terrasse ?',
            'form[condition]' => "answers['table_booking'] == 'yes'",
            'form[position]' => '25',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/catalog?tab=questions');

        $crawler = $this->client->request('GET', '/admin/catalog/questions/new');
        $this->client->submit($crawler->selectButton('Save')->form([
            'form[code]' => 'broken',
            'form[label][fr]' => 'Broken',
            'form[condition]' => "system('ls')",
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-errors', 'Invalid expression');
    }
}
