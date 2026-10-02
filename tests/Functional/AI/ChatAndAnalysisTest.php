<?php

declare(strict_types=1);

namespace App\Tests\Functional\AI;

use App\Requirement\Enum\RequirementStatus;
use App\Requirement\Repository\RequirementRepository;
use App\Tests\Support\PlatformFixtureTrait;
use App\Tests\Support\WebTestCaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ChatAndAnalysisTest extends WebTestCase
{
    use PlatformFixtureTrait;
    use WebTestCaseTrait;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->setUpPlatform();
    }

    public function testChatThenProposalThroughTheFunnel(): void
    {
        $this->client->request('GET', '/fr/chat');
        self::assertResponseRedirects('/fr/chat');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.chat-bubble-assistant', 'assistant Mzian');
        self::assertSelectorExists('.badge-amber', 'Demo mode is disclosed with the mock AI provider');

        // JSON (fetch) submission.
        $form = $crawler->filter('form[data-chat-form]')->form();
        $this->client->request('POST', $form->getUri(), ['_token' => $form['_token']->getValue(), 'message' => "J'ai une agence de location de voitures à Agadir avec 12 voitures, je veux des réservations en ligne."], server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        $json = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertStringContainsString('Agadir', $json['reply']);

        $this->client->request('GET', $json['summaryHtmlUrl']);
        self::assertSelectorTextContains('dd', 'Location de voitures');

        // Classic (no-JS) submission.
        $this->client->submit($form, ['message' => 'Je veux aussi gérer les contrats.']);
        self::assertResponseRedirects('/fr/chat');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('contrats PDF', $crawler->filter('.chat-bubble-assistant')->last()->text());

        // Proposal -> project details -> contact -> summary -> analysis.
        $this->client->submit($crawler->filter('form[data-chat-ready] button')->form());
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Parlez-nous de votre projet');
        $this->client->submit($crawler->selectButton('Continuer →')->form(['details_form[businessName]' => 'Souss Cars']));
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Voir le récapitulatif →')->form([
            'lead_form[fullName]' => 'Omar', 'lead_form[email]' => 'omar@example.com', 'lead_form[acceptPrivacy]' => '1',
        ]));
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('🤖 Analyser mon besoin')->form());
        self::assertResponseRedirects();
        $this->client->followRedirect();

        self::assertSelectorTextContains('h1', 'Gestion de location de voitures');
        self::assertSelectorTextContains('#proposal-price', 'Votre prix');
        self::assertSelectorTextContains('main', 'souss-cars.com — disponible, réservé pour vous');
        self::assertSelectorTextContains('main', 'Aucun travail ne démarre sans la validation');
        $requirement = static::getContainer()->get(RequirementRepository::class)->findOneBy(['businessName' => 'Souss Cars']);
        self::assertSame(RequirementStatus::Analyzed, $requirement->getStatus());
        self::assertSame('car_rental_management', $requirement->getRecommendedSolution()?->getCode());
        self::assertContains('contracts', $requirement->getAnalysis()['features']);
    }

    public function testChatMessagesRequireCsrfAndOwnership(): void
    {
        $this->client->request('GET', '/fr/chat');
        $crawler = $this->client->followRedirect();
        $url = $crawler->filter('form[data-chat-form]')->attr('action');

        $this->client->request('POST', (string) $url, ['_token' => 'forged', 'message' => 'hello']);
        self::assertResponseStatusCodeSame(403);

        $this->client->getCookieJar()->clear();
        $this->client->request('POST', (string) $url, ['_token' => 'whatever', 'message' => 'hello']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testApiAnalyze(): void
    {
        $this->client->request('POST', '/api/v1/ai/analyze', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'business_type' => 'car_rental',
            'answers' => ['contracts' => 'yes', 'pdf_contracts' => 'yes', 'fleet_size' => 20, 'unknown_question' => 'x'],
            'description' => 'Agence à Marrakech, 20 voitures',
            'locale' => 'en',
        ]));
        self::assertResponseIsSuccessful();
        $json = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('car_rental_management', $json['solution']);
        self::assertSame('web_application', $json['solution_type']);
        self::assertContains('pdf_contracts', $json['features']);
        self::assertArrayHasKey('estimated_development_days', $json);
        self::assertArrayHasKey('hosting_requirements', $json);
        self::assertIsBool($json['domain_required']);
        self::assertNotEmpty($json['recommendation']);
    }

    public function testApiAnalyzeValidation(): void
    {
        $this->client->request('POST', '/api/v1/ai/analyze', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['business_type' => 'casino']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(422, json_decode((string) $this->client->getResponse()->getContent(), true)['error']['code']);

        $this->client->request('POST', '/api/v1/ai/analyze', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['locale' => 'de']));
        self::assertResponseStatusCodeSame(422);
        $json = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertNotEmpty($json['error']['violations']);

        $this->client->request('POST', '/api/v1/ai/analyze', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['business_type' => 'car_rental', 'answers' => ['fleet_size' => -3]]));
        self::assertResponseStatusCodeSame(422);
    }

    public function testApiConversationAndSolutions(): void
    {
        $this->client->request('POST', '/api/v1/conversations', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['locale' => 'en', 'message' => 'I own a hair salon in Rabat, customers want to book appointments online']));
        self::assertResponseStatusCodeSame(201);
        $conversation = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('beauty', $conversation['requirement']['sector']);
        self::assertCount(3, $conversation['messages']);

        $this->client->request('POST', '/api/v1/conversations/'.$conversation['token'].'/messages', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['content' => 'Yes']));
        self::assertResponseIsSuccessful();
        $turn = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('reply', $turn);

        $this->client->request('GET', '/api/v1/conversations/'.$conversation['token']);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/v1/conversations/'.str_repeat('0', 32));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/api/v1/solutions?locale=en&sector=car_rental');
        self::assertResponseIsSuccessful();
        $codes = array_column(json_decode((string) $this->client->getResponse()->getContent(), true)['data'], 'code');
        self::assertEqualsCanonicalizing(['car_rental_website', 'car_rental_management'], $codes);
    }
}
