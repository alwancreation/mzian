<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicPagesTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function pages(): iterable
    {
        foreach (['/fr/', '/en/', '/ar/', '/fr/comment-ca-marche', '/en/how-it-works', '/ar/how-it-works', '/fr/faq', '/en/faq', '/ar/faq', '/fr/contact', '/en/contact', '/ar/contact', '/fr/mentions-legales', '/en/privacy', '/fr/connexion', '/en/register'] as $url) {
            yield $url => [$url];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function testPageRendersWithSeoMetadata(string $url): void
    {
        $client = static::createClient();
        $client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('title');
        self::assertSelectorExists('meta[name="description"]');
        self::assertSelectorExists('meta[property="og:title"]');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
    }

    public function testRootRedirectsToThePreferredLanguage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'ar-MA,ar;q=0.9']);
        self::assertResponseRedirects('/ar/');

        $client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'de-DE']);
        self::assertResponseRedirects('/fr/');
    }

    public function testArabicIsRenderedRightToLeftWithHreflangAlternates(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/ar/faq');

        self::assertSame('rtl', $crawler->filter('html')->attr('dir'));
        self::assertSame('ar', $crawler->filter('html')->attr('lang'));
        self::assertCount(3, $crawler->filter('link[rel="alternate"][hreflang]:not([hreflang="x-default"])'));
        self::assertStringEndsWith('/fr/faq', (string) $crawler->filter('link[hreflang="fr"]')->attr('href'));
    }

    public function testFaqPublishesStructuredData(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/en/faq');

        $json = json_decode($crawler->filter('script[type="application/ld+json"]')->text(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('FAQPage', $json['@type']);
        self::assertNotEmpty($json['mainEntity']);
    }

    public function testContactFormCapturesALeadAndIgnoresHoneypotBots(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/contact');
        $client->submit($crawler->selectButton('Envoyer le message')->form([
            'contact_form[name]' => 'Hassan',
            'contact_form[email]' => 'hassan@example.com',
            'contact_form[message]' => 'Je voudrais un site pour mon riad à Fès.',
        ]));
        self::assertResponseRedirects('/fr/contact');

        $crawler = $client->request('GET', '/fr/contact');
        $client->submit($crawler->selectButton('Envoyer le message')->form([
            'contact_form[name]' => 'Bot',
            'contact_form[email]' => 'bot@example.com',
            'contact_form[message]' => 'Buy cheap things right now please',
            'contact_form[website]' => 'http://spam.example',
        ]));
        self::assertResponseStatusCodeSame(422);

        $leads = static::getContainer()->get(\App\Lead\Repository\LeadRepository::class)->findAll();
        self::assertCount(1, $leads);
        self::assertSame('hassan@example.com', $leads[0]->getEmail());
    }

    public function testLogoIconsAndSharingImageExist(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/');

        $urls = [
            $crawler->filter('header img[alt="Mzian.net"]')->attr('src'),
            $crawler->filter('footer img[alt="Mzian.net"]')->attr('src'),
            $crawler->filter('meta[property="og:image"]')->attr('content'),
            ...$crawler->filter('link[rel="icon"], link[rel="apple-touch-icon"]')->each(static fn ($link) => $link->attr('href')),
        ];
        $json = json_decode($crawler->filter('script[type="application/ld+json"]')->text(), true, flags: \JSON_THROW_ON_ERROR);
        $urls[] = $json[0]['logo'];

        self::assertCount(7, $urls);
        $public = static::getContainer()->getParameter('kernel.project_dir').'/public';
        foreach ($urls as $url) {
            self::assertFileExists($public.parse_url((string) $url, \PHP_URL_PATH), (string) $url);
        }
    }

    public function testUserInputIsEscapedInPages(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/inscription?email=%3Cscript%3Ealert(1)%3C/script%3E');

        self::assertStringNotContainsString('<script>alert(1)</script>', (string) $client->getResponse()->getContent());
        self::assertSame('<script>alert(1)</script>', $crawler->filter('#registration_form_email')->attr('value'));
    }
}
