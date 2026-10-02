<?php

declare(strict_types=1);

namespace App\Tests\Integration\Billing;

use App\Billing\Entity\Payment;
use App\Billing\Payment\Dto\CheckoutUrls;
use App\Billing\Payment\Dto\PaymentEvent;
use App\Billing\Payment\Provider\StripePaymentProvider;
use App\Provider\CredentialVault;
use App\Provider\Entity\Provider;
use App\Provider\Enum\ProviderType;
use App\Provider\Enum\ProvisioningMethod;
use App\Provider\Exception\ProviderException;
use App\Shared\Webhook\InvalidWebhookException;
use App\Shared\Webhook\WebhookSignature;
use App\Tests\Support\CommerceFixtureTrait;
use App\Tests\Support\Factory;
use App\Tests\Support\PlatformFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class StripePaymentProviderTest extends KernelTestCase
{
    use CommerceFixtureTrait;
    use PlatformFixtureTrait;

    private Provider $provider;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->setUpPlatform();
        $_SERVER['MZIAN_TEST_STRIPE_KEY'] = 'sk_test_123';
        $this->provider = new Provider('stripe_test', ProviderType::Payment, 'stripe', 'Stripe', ProvisioningMethod::Api);
        $this->provider->setSettings(['env' => ['api_key' => 'MZIAN_TEST_STRIPE_KEY', 'webhook_secret' => 'STRIPE_WEBHOOK_SECRET']]);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['MZIAN_TEST_STRIPE_KEY']);
        parent::tearDown();
    }

    private function payment(): Payment
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $factory = new Factory($em, static::getContainer()->get(UserPasswordHasherInterface::class));
        $order = $this->placeOrder($factory->customer());

        return new Payment($order, 'stripe_test', 'pay-'.$order->getNumber().'-1');
    }

    private function driver(MockHttpClient $http): StripePaymentProvider
    {
        return new StripePaymentProvider($http, static::getContainer()->get(CredentialVault::class));
    }

    public function testCheckoutSessionIsIdempotentAndNeverContainsCardData(): void
    {
        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            $captured = ['url' => $url, 'headers' => $options['headers'], 'body' => $options['body']];

            return new MockResponse((string) json_encode(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1']));
        });
        $payment = $this->payment();

        $session = $this->driver($http)->createCheckout($this->provider, $payment, new CheckoutUrls('https://mzian.test/ok', 'https://mzian.test/ko'));

        self::assertSame('https://checkout.stripe.com/c/pay/cs_test_1', $session->redirectUrl);
        self::assertSame('cs_test_1', $session->providerReference);
        self::assertSame('https://api.stripe.com/v1/checkout/sessions', $captured['url']);
        self::assertContains('Authorization: Bearer sk_test_123', $captured['headers']);
        self::assertContains('Idempotency-Key: '.$payment->getIdempotencyKey(), $captured['headers']);
        parse_str($captured['body'], $body);
        self::assertSame('payment', $body['mode']);
        self::assertSame((string) $payment->getAmount(), $body['line_items'][0]['price_data']['unit_amount']);
        self::assertSame('usd', $body['line_items'][0]['price_data']['currency']);
        self::assertSame($payment->getIdempotencyKey(), $body['metadata']['payment_key']);
    }

    public function testStripeErrorsAreClassified(): void
    {
        $driver = $this->driver(new MockHttpClient([
            new MockResponse('{"error":{"message":"Invalid currency"}}', ['http_code' => 400]),
            new MockResponse('{"error":{"message":"Overloaded"}}', ['http_code' => 503]),
        ]));
        $payment = $this->payment();
        $urls = new CheckoutUrls('https://mzian.test/ok', 'https://mzian.test/ko');

        foreach ([false, true] as $retryable) {
            try {
                $driver->createCheckout($this->provider, $payment, $urls);
                self::fail('Expected a provider exception.');
            } catch (ProviderException $e) {
                self::assertSame($retryable, $e->retryable);
            }
        }
    }

    public function testWebhookSignatureAndEventMapping(): void
    {
        $driver = $this->driver(new MockHttpClient());
        $payload = (string) json_encode([
            'id' => 'evt_123',
            'type' => 'checkout.session.completed',
            'livemode' => false,
            'data' => ['object' => ['id' => 'cs_test_1', 'payment_status' => 'paid', 'amount_total' => 63800, 'currency' => 'usd', 'payment_intent' => 'pi_1', 'metadata' => ['payment_key' => 'pay-ORD-2610-ABCDEF-1']]],
        ]);
        $request = Request::create('/webhooks/payment/stripe', 'POST', server: ['HTTP_STRIPE_SIGNATURE' => WebhookSignature::sign($payload, 'whsec_test_secret')], content: $payload);

        $event = $driver->parseWebhook($this->provider, $request);

        self::assertSame(PaymentEvent::SUCCEEDED, $event->type);
        self::assertSame('pay-ORD-2610-ABCDEF-1', $event->paymentKey);
        self::assertSame(63800, $event->amount);
        self::assertSame('USD', $event->currency);
        self::assertSame('pi_1', $event->summary['payment_intent']);

        $forged = Request::create('/webhooks/payment/stripe', 'POST', server: ['HTTP_STRIPE_SIGNATURE' => WebhookSignature::sign($payload, 'whsec_wrong')], content: $payload);
        $this->expectException(InvalidWebhookException::class);
        $driver->parseWebhook($this->provider, $forged);
    }

    public function testRefundUsesThePaymentIntent(): void
    {
        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            $captured = ['url' => $url, 'body' => $options['body'], 'headers' => $options['headers']];

            return new MockResponse('{"id":"re_1","amount":63800,"status":"succeeded"}');
        });
        $payment = $this->payment();
        $payment->mergeMetadata(['payment_intent' => 'pi_1']);

        $result = $this->driver($http)->refund($this->provider, $payment, 63800, 'refund-key');

        self::assertSame('re_1', $result->reference);
        self::assertFalse($result->pending);
        self::assertSame('https://api.stripe.com/v1/refunds', $captured['url']);
        self::assertContains('Idempotency-Key: refund-key', $captured['headers']);
        parse_str($captured['body'], $body);
        self::assertSame('pi_1', $body['payment_intent']);
    }
}
