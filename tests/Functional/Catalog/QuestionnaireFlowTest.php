<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Lead\Repository\LeadRepository;
use App\Requirement\Enum\RequirementStatus;
use App\Requirement\Repository\RequirementRepository;
use App\Tests\Support\CatalogFixtureTrait;
use App\Tests\Support\WebTestCaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class QuestionnaireFlowTest extends WebTestCase
{
    use CatalogFixtureTrait;
    use WebTestCaseTrait;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->importCatalog();
    }

    /**
     * @param string|list<string> $value
     */
    private function answer(Crawler $crawler, string|array $value): Crawler
    {
        $form = $crawler->filter('form.card')->form();
        $values = $form->getPhpValues();
        $values['answer'] = $value;
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        while ($this->client->getResponse()->isRedirect()) {
            $crawler = $this->client->followRedirect();
        }

        return $crawler;
    }

    private function currentQuestion(Crawler $crawler): string
    {
        return (string) $crawler->filter('input[name="question"]')->attr('value');
    }

    public function testCarRentalQuestionnaireCapturesALeadAndAStructuredRequirement(): void
    {
        $crawler = $this->client->request('GET', '/fr/demarrer');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Quel est votre secteur ?');

        $this->client->submit($crawler->selectButton('Continuer →')->form(['sector' => 'car_rental']));
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();

        $answers = [
            'goal' => ['receive_bookings', 'manage_customers'],
            'fleet_size' => '20',
            'show_vehicles' => 'yes',
            'online_reservations' => 'yes',
            'contracts' => 'yes',
            'pdf_contracts' => 'yes',
            'customer_management' => 'yes',
            'payments' => 'no',
            'vehicle_tracking' => 'no',
            'statistics' => 'yes',
            'languages' => 'several',
            'whatsapp' => 'yes',
            'has_domain' => 'no',
        ];
        $asked = [];
        for ($i = 0; $i < 30 && $crawler->filter('input[name="question"]')->count() > 0; ++$i) {
            $code = $this->currentQuestion($crawler);
            $asked[] = $code;
            self::assertArrayHasKey($code, $answers, 'Unexpected question '.$code);
            $crawler = $this->answer($crawler, $answers[$code]);
        }
        self::assertContains('pdf_contracts', $asked, 'The conditional question is asked after "contracts = yes".');
        self::assertNotContains('domain_name', $asked, 'No domain question when the customer has none.');

        // Project details.
        self::assertSelectorTextContains('h1', 'Parlez-nous de votre projet');
        $this->client->submit($crawler->selectButton('Continuer →')->form([
            'details_form[businessName]' => 'Atlas Cars',
            'details_form[city]' => 'Marrakech',
            'details_form[description]' => "J'ai une agence de location de voitures à Marrakech avec 20 voitures.",
            'details_form[desiredDomain]' => 'https://www.Atlas-Cars.ma/',
        ]));
        $crawler = $this->client->followRedirect();

        // Lead capture (before any payment).
        $this->client->submit($crawler->selectButton('Voir le récapitulatif →')->form([
            'lead_form[fullName]' => 'Karim Alaoui',
            'lead_form[email]' => 'karim@atlas-cars.ma',
            'lead_form[phone]' => '+212 600 11 22 33',
            'lead_form[acceptPrivacy]' => '1',
        ]));
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Récapitulatif');
        self::assertSelectorTextContains('main', 'Atlas Cars');

        $lead = static::getContainer()->get(LeadRepository::class)->findOneBy(['email' => 'karim@atlas-cars.ma']);
        self::assertNotNull($lead);
        self::assertSame('car_rental', $lead->getSector());

        $requirement = static::getContainer()->get(RequirementRepository::class)->findOneBy(['lead' => $lead]);
        self::assertSame(RequirementStatus::Submitted, $requirement->getStatus());
        self::assertSame(20, $requirement->getAnswers()['fleet_size']);
        self::assertSame('atlas-cars.ma', $requirement->getItem('desired_domain')?->getValue());
        self::assertSame('Voulez-vous gérer les contrats ?', $requirement->getItem('contracts')?->getLabel());
    }

    public function testInvalidAnswerIsRejectedWithA422(): void
    {
        $crawler = $this->client->request('GET', '/en/start');
        $this->client->submit($crawler->selectButton('Continue →')->form(['sector' => 'car_rental']));
        $crawler = $this->client->followRedirect();
        $crawler = $this->answer($crawler, ['present']); // goal

        self::assertSame('fleet_size', $this->currentQuestion($crawler));
        $form = $crawler->filter('form.card')->form();
        $values = $form->getPhpValues();
        $values['answer'] = '-5';
        $this->client->request('POST', $form->getUri(), $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert-error', 'valid number');
    }

    public function testARequirementIsNotReachableFromAnotherSession(): void
    {
        $crawler = $this->client->request('GET', '/fr/demarrer');
        $this->client->submit($crawler->selectButton('Continuer →')->form(['sector' => 'restaurant']));
        $url = (string) $this->client->getResponse()->headers->get('Location');

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
    }

    public function testQuestionnaireRequiresCsrfToken(): void
    {
        $this->client->request('POST', '/fr/demarrer', ['sector' => 'restaurant', '_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
    }
}
