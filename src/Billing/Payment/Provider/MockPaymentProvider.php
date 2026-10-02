<?php

declare(strict_types=1);

namespace App\Billing\Payment\Provider;

use App\Billing\Entity\Payment;
use App\Billing\Payment\Dto\CheckoutSession;
use App\Billing\Payment\Dto\CheckoutUrls;
use App\Billing\Payment\Dto\PaymentEvent;
use App\Billing\Payment\Dto\RefundResult;
use App\Billing\Payment\PaymentProviderInterface;
use App\Provider\CredentialVault;
use App\Provider\Entity\Provider;
use App\Provider\Exception\ProviderNotConfiguredException;
use App\Provider\Mock\MockBehavior;
use App\Shared\Webhook\InvalidWebhookException;
use App\Shared\Webhook\WebhookSignature;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Simulated payment gateway for development and tests: a local checkout page
 * (no card, no money) that notifies the platform through a SIGNED webhook, exactly
 * like a real gateway. Refused in production unless explicitly allowed.
 */
final readonly class MockPaymentProvider implements PaymentProviderInterface
{
    public const SIGNATURE_HEADER = 'Mzian-Signature';

    public function __construct(
        private UrlGeneratorInterface $urls,
        private CredentialVault $vault,
        private MockBehavior $behavior,
        #[Autowire('%kernel.environment%')]
        private string $environment,
        #[Autowire('%env(bool:default::MZIAN_ALLOW_MOCK_PAYMENTS)%')]
        private ?bool $allowInProduction = false,
    ) {
    }

    public static function getDriver(): string
    {
        return 'mock';
    }

    public function createCheckout(Provider $provider, Payment $payment, CheckoutUrls $urls): CheckoutSession
    {
        $this->assertAllowed($provider);
        $this->behavior->simulate($provider, 'checkout', $payment->getIdempotencyKey());

        return new CheckoutSession(
            $this->urls->generate('payment_mock_checkout', ['key' => $payment->getIdempotencyKey(), '_locale' => $payment->getOrder()->getProject()->getLocale()], UrlGeneratorInterface::ABSOLUTE_URL),
            'mock_cs_'.substr(hash('sha256', $payment->getIdempotencyKey()), 0, 24),
            'mock_card',
        );
    }

    public function parseWebhook(Provider $provider, Request $request): PaymentEvent
    {
        $this->assertAllowed($provider);
        $payload = (string) $request->getContent();
        if (!WebhookSignature::verify($payload, $request->headers->get(self::SIGNATURE_HEADER), $this->secret($provider))) {
            throw new InvalidWebhookException('Invalid mock payment signature.');
        }
        $data = json_decode($payload, true);
        if (!\is_array($data) || !isset($data['id'], $data['type'], $data['data']) || !\is_array($data['data'])) {
            throw new InvalidWebhookException('Malformed mock payment event.');
        }
        $object = $data['data'];

        return new PaymentEvent(
            (string) $data['id'],
            match ($data['type']) {
                'payment.succeeded' => PaymentEvent::SUCCEEDED,
                'payment.failed' => PaymentEvent::FAILED,
                'payment.refunded' => PaymentEvent::REFUNDED,
                default => PaymentEvent::IGNORED,
            },
            (string) $data['type'],
            isset($object['payment_key']) ? (string) $object['payment_key'] : null,
            isset($object['reference']) ? (string) $object['reference'] : null,
            isset($object['amount']) ? (int) $object['amount'] : null,
            isset($object['currency']) ? strtoupper((string) $object['currency']) : null,
            isset($object['failure_reason']) ? (string) $object['failure_reason'] : null,
            ['simulated' => true],
        );
    }

    public function refund(Provider $provider, Payment $payment, int $amount, string $idempotencyKey): RefundResult
    {
        $this->assertAllowed($provider);
        $this->behavior->simulate($provider, 'refund', $idempotencyKey);

        return new RefundResult('mock_re_'.substr(hash('sha256', $idempotencyKey), 0, 24), $amount);
    }

    /**
     * What the simulated gateway sends to the platform when the customer completes
     * (or fails) the mock checkout: a signed event, verified like any webhook.
     *
     * @return array{payload: string, signature: string}
     */
    public function simulatedEvent(Provider $provider, Payment $payment, bool $success): array
    {
        $this->assertAllowed($provider);
        $payload = json_encode([
            'id' => 'evt_mock_'.bin2hex(random_bytes(8)),
            'type' => $success ? 'payment.succeeded' : 'payment.failed',
            'data' => [
                'payment_key' => $payment->getIdempotencyKey(),
                'reference' => $payment->getProviderReference(),
                'amount' => $payment->getAmount(),
                'currency' => $payment->getCurrency(),
                'failure_reason' => $success ? null : 'Card declined (simulation)',
            ],
        ], \JSON_THROW_ON_ERROR);

        return ['payload' => $payload, 'signature' => WebhookSignature::sign($payload, $this->secret($provider))];
    }

    private function secret(Provider $provider): string
    {
        return $this->vault->get($provider, 'webhook_secret')
            ?? throw new ProviderNotConfiguredException('The mock payment webhook secret is missing (MZIAN_WEBHOOK_SECRET).', $provider->getCode());
    }

    private function assertAllowed(Provider $provider): void
    {
        if ('prod' === $this->environment && true !== $this->allowInProduction) {
            throw new ProviderNotConfiguredException('Mock payments are disabled in production: configure a real payment provider.', $provider->getCode());
        }
    }
}
