<?php

declare(strict_types=1);

namespace App\Tests\Functional\Order;

use App\Billing\Repository\InvoiceRepository;
use App\Order\Enum\OrderStatus;
use App\Order\Repository\OrderRepository;
use App\Project\Entity\Project;
use App\Project\Enum\ProjectStatus;
use App\Requirement\Repository\RequirementRepository;
use App\Tests\Support\CommerceFixtureTrait;
use App\Tests\Support\PlatformFixtureTrait;
use App\Tests\Support\WebTestCaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;

/**
 * Visitor → questionnaire → AI analysis → quote → account → checkout → payment
 * (signed mock webhook) → order paid → project waiting for the admin approval.
 */
final class CheckoutFlowTest extends WebTestCase
{
    use CommerceFixtureTrait;
    use PlatformFixtureTrait;
    use WebTestCaseTrait;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->setUpPlatform();
    }

    private function follow(): Crawler
    {
        $crawler = $this->client->followRedirect();
        while ($this->client->getResponse()->isRedirect()) {
            $crawler = $this->client->followRedirect();
        }

        return $crawler;
    }

    private function choice(Form $form, string $name): ChoiceFormField
    {
        $field = $form[$name];
        self::assertInstanceOf(ChoiceFormField::class, $field);

        return $field;
    }

    /**
     * Answers every questionnaire question with a neutral answer, fills the project details and the contact form.
     */
    private function requestAProposal(string $businessName, string $email): Crawler
    {
        $crawler = $this->client->request('GET', '/fr/demarrer');
        $this->client->submit($crawler->selectButton('Continuer →')->form(['sector' => 'car_rental']));
        $crawler = $this->follow();
        for ($i = 0; $i < 40 && $crawler->filter('input[name="question"]')->count() > 0; ++$i) {
            $form = $crawler->filter('form.card')->form();
            $values = $form->getPhpValues();
            $radio = $crawler->filter('form.card input[type="radio"][name="answer"]');
            $checkbox = $crawler->filter('form.card input[type="checkbox"][name="answer[]"]');
            $values['answer'] = match (true) {
                $radio->count() > 0 => 'has_domain' === $values['question'] ? 'no' : $radio->first()->attr('value'),
                $checkbox->count() > 0 => [$checkbox->first()->attr('value')],
                $crawler->filter('form.card input[type="number"]')->count() > 0 => '12',
                default => 'Réponse',
            };
            $this->client->request('POST', $form->getUri(), $values);
            $crawler = $this->follow();
        }
        $this->client->submit($crawler->selectButton('Continuer →')->form(['details_form[businessName]' => $businessName, 'details_form[city]' => 'Fès']));
        $crawler = $this->follow();
        $this->client->submit($crawler->selectButton('Voir le récapitulatif →')->form([
            'lead_form[fullName]' => 'Nadia Bennani',
            'lead_form[email]' => $email,
            'lead_form[acceptPrivacy]' => '1',
        ]));
        $crawler = $this->follow();
        $this->client->submit($crawler->selectButton('🤖 Analyser mon besoin')->form());

        return $this->follow();
    }

    public function testVisitorOrdersAndPaysThroughTheMockGateway(): void
    {
        $crawler = $this->requestAProposal('Fès Cars', 'nadia@fes-cars.ma');
        self::assertSelectorExists('[data-testid="proposal-total"]');
        self::assertSelectorTextContains('main', 'fes-cars.com');

        // Ordering requires an account: the visitor is sent to the registration form, pre-filled.
        $this->client->click($crawler->filter('[data-testid="order-button"]')->link());
        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/fr/inscription', $location);
        self::assertStringContainsString('email=nadia@fes-cars.ma', urldecode($location));
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Créer mon compte')->form([
            'registration_form[firstName]' => 'Nadia',
            'registration_form[plainPassword]' => 'a-very-long-password',
            'registration_form[acceptTerms]' => '1',
        ]));
        self::assertResponseRedirects();
        self::assertStringContainsString('/fr/commande/', (string) $this->client->getResponse()->headers->get('Location'));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Finaliser ma commande');

        // Terms are mandatory.
        $form = $crawler->selectButton('Payer')->form();
        $this->choice($form, 'accept_terms')->untick();
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert-error', 'conditions générales');

        $crawler = $this->client->request('GET', $this->client->getRequest()->getUri());
        $form = $crawler->selectButton('Payer')->form();
        $this->choice($form, 'subscription')->select('starter');
        $this->choice($form, 'payment_provider')->select('mock_payment');
        $this->choice($form, 'accept_terms')->tick();
        $this->client->submit($form);
        self::assertResponseRedirects();
        self::assertStringContainsString('/fr/paiement-simule/pay-ORD-', (string) $this->client->getResponse()->headers->get('Location'));

        $requirement = static::getContainer()->get(RequirementRepository::class)->findOneBy(['businessName' => 'Fès Cars']);
        $order = static::getContainer()->get(OrderRepository::class)->findOneBy(['project' => $this->em()->getRepository(Project::class)->findOneBy(['requirement' => $requirement])]);
        self::assertSame(OrderStatus::PendingPayment, $order->getStatus());
        self::assertSame(ProjectStatus::Ordered, $order->getProject()->getStatus());
        self::assertSame(1500, $order->getRecurringMonthly());

        // Simulated gateway → signed webhook → paid.
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('[data-testid="mock-amount"]', number_format($order->getTotal() / 100, 2, ',', "\u{202F}"));
        $this->client->submit($crawler->selectButton('Simuler un paiement réussi')->form());
        self::assertResponseRedirects('/fr/compte/commandes/'.$order->getNumber());
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-testid="order-paid"]', 'en cours de validation');

        $order = static::getContainer()->get(OrderRepository::class)->findOneBy(['number' => $order->getNumber()]);
        self::assertSame(OrderStatus::Paid, $order->getStatus());
        self::assertSame(ProjectStatus::PendingAdminApproval, $order->getProject()->getStatus());
        $invoice = static::getContainer()->get(InvoiceRepository::class)->findOneBy(['order' => $order]);
        $this->client->request('GET', '/fr/compte/factures/'.$invoice->getNumber());
        self::assertResponseIsSuccessful();

        // The request is locked once ordered.
        $this->client->request('GET', '/fr/demarrer/'.$requirement->getToken().'/projet');
        self::assertResponseRedirects('/fr/demarrer/'.$requirement->getToken().'/solution');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', $order->getNumber());
        self::assertCount(0, $crawler->filter('[data-testid="order-button"]'));
    }

    public function testCheckoutAndOrdersArePrivate(): void
    {
        $owner = $this->factory()->customer('owner@example.com');
        $quote = $this->issueQuote($owner);

        $other = $this->factory()->customer('other@example.com');
        $this->loginAs($other->getUser());
        $this->client->request('GET', '/fr/commande/'.$quote->getToken());
        self::assertResponseStatusCodeSame(404);

        $order = $this->placeOrder($owner);
        $this->client->request('GET', '/fr/compte/commandes/'.$order->getNumber());
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/fr/compte/commandes/'.$order->getNumber().'/paiement', ['payment_provider' => 'mock_payment', '_token' => 'x']);
        self::assertResponseStatusCodeSame(403);

        $this->loginAs($owner->getUser());
        $this->client->request('GET', '/fr/compte/commandes/'.$order->getNumber());
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/fr/compte/commandes/'.$order->getNumber().'/paiement', ['payment_provider' => 'mock_payment', '_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testPaymentWebhookEndpointRejectsUnsignedRequests(): void
    {
        $this->client->request('POST', '/webhooks/payment/mock_payment', server: ['CONTENT_TYPE' => 'application/json'], content: '{"id":"evt_x","type":"payment.succeeded","data":{}}');
        self::assertResponseStatusCodeSame(401);
        self::assertSame('{"result":"invalid_signature"}', $this->client->getResponse()->getContent());

        $this->client->request('POST', '/webhooks/payment/nope', content: '{}');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/webhooks/payment/mock_payment');
        self::assertResponseStatusCodeSame(405);
    }
}
