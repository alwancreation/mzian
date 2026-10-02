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
use App\Provider\Exception\ProviderException;
use App\Provider\Exception\ProviderNotConfiguredException;
use App\Shared\Webhook\InvalidWebhookException;
use App\Shared\Webhook\WebhookSignature;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Stripe Checkout (hosted payment page: Mzian never sees card data).
 *
 * Credentials (Admin > Providers, encrypted, or env fallback): api_key
 * (STRIPE_SECRET_KEY) and webhook_secret (STRIPE_WEBHOOK_SECRET). Webhook endpoint:
 * POST /webhooks/payment/stripe with events checkout.session.completed,
 * checkout.session.async_payment_succeeded/failed, checkout.session.expired.
 */
final readonly class StripePaymentProvider implements PaymentProviderInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private CredentialVault $vault,
    ) {
    }

    public static function getDriver(): string
    {
        return 'stripe';
    }

    public function createCheckout(Provider $provider, Payment $payment, CheckoutUrls $urls): CheckoutSession
    {
        $order = $payment->getOrder();
        $data = $this->request($provider, 'checkout/sessions', $payment->getIdempotencyKey(), [
            'mode' => 'payment',
            'success_url' => $urls->success,
            'cancel_url' => $urls->cancel,
            'client_reference_id' => $order->getNumber(),
            'customer_email' => $order->getCustomer()->getEmail(),
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($payment->getCurrency()),
                    'unit_amount' => $payment->getAmount(),
                    'product_data' => ['name' => \sprintf('Mzian.net — %s (%s)', $order->getQuote()->getSolution()->getName('en'), $order->getNumber())],
                ],
            ]],
            'metadata' => ['payment_key' => $payment->getIdempotencyKey(), 'order' => $order->getNumber()],
            'payment_intent_data' => ['metadata' => ['payment_key' => $payment->getIdempotencyKey(), 'order' => $order->getNumber()]],
        ]);
        if (!isset($data['id'], $data['url'])) {
            throw ProviderException::transient('Unexpected Stripe checkout response.', $provider->getCode());
        }

        return new CheckoutSession((string) $data['url'], (string) $data['id'], 'card');
    }

    public function parseWebhook(Provider $provider, Request $request): PaymentEvent
    {
        $secret = $this->vault->get($provider, 'webhook_secret') ?? throw new InvalidWebhookException('Stripe webhook secret is not configured.');
        $payload = (string) $request->getContent();
        $tolerance = (int) ($provider->getSettings()['tolerance_seconds'] ?? 300);
        if (!WebhookSignature::verify($payload, $request->headers->get('Stripe-Signature'), $secret, $tolerance)) {
            throw new InvalidWebhookException('Invalid Stripe signature.');
        }
        $event = json_decode($payload, true);
        if (!\is_array($event) || !isset($event['id'], $event['type'], $event['data']['object']) || !\is_array($event['data']['object'])) {
            throw new InvalidWebhookException('Malformed Stripe event.');
        }
        $object = $event['data']['object'];
        $type = (string) $event['type'];
        $paymentKey = isset($object['metadata']['payment_key']) ? (string) $object['metadata']['payment_key'] : null;

        $normalized = match ($type) {
            'checkout.session.completed' => 'paid' === ($object['payment_status'] ?? null) ? PaymentEvent::SUCCEEDED : PaymentEvent::IGNORED,
            'checkout.session.async_payment_succeeded' => PaymentEvent::SUCCEEDED,
            'checkout.session.async_payment_failed', 'checkout.session.expired' => PaymentEvent::FAILED,
            'charge.refunded' => null !== $paymentKey ? PaymentEvent::REFUNDED : PaymentEvent::IGNORED,
            default => PaymentEvent::IGNORED,
        };
        $isSession = str_starts_with($type, 'checkout.session.');

        return new PaymentEvent(
            (string) $event['id'],
            $normalized,
            $type,
            $paymentKey,
            $isSession && isset($object['id']) ? (string) $object['id'] : null,
            $isSession && isset($object['amount_total']) ? (int) $object['amount_total'] : null,
            $isSession && isset($object['currency']) ? strtoupper((string) $object['currency']) : null,
            PaymentEvent::FAILED === $normalized ? ('checkout.session.expired' === $type ? 'Checkout expired' : 'Payment failed') : null,
            array_filter([
                'payment_intent' => isset($object['payment_intent']) && \is_string($object['payment_intent']) ? $object['payment_intent'] : null,
                'livemode' => isset($event['livemode']) ? (bool) $event['livemode'] : null,
            ], static fn ($v) => null !== $v),
        );
    }

    public function refund(Provider $provider, Payment $payment, int $amount, string $idempotencyKey): RefundResult
    {
        $paymentIntent = $payment->getMetadata()['payment_intent'] ?? null;
        if (!\is_string($paymentIntent) || '' === $paymentIntent) {
            throw ProviderException::permanent('No Stripe payment intent recorded for this payment.', $provider->getCode());
        }
        $data = $this->request($provider, 'refunds', $idempotencyKey, [
            'payment_intent' => $paymentIntent,
            'amount' => $amount,
            'metadata' => ['payment_key' => $payment->getIdempotencyKey()],
        ]);

        return new RefundResult((string) ($data['id'] ?? $idempotencyKey), (int) ($data['amount'] ?? $amount), 'succeeded' !== ($data['status'] ?? null));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function request(Provider $provider, string $path, string $idempotencyKey, array $body): array
    {
        $apiKey = $this->vault->get($provider, 'api_key') ?? throw new ProviderNotConfiguredException('Stripe API key is missing (Admin > Providers or STRIPE_SECRET_KEY).', $provider->getCode());
        $baseUrl = rtrim((string) ($provider->getSettings()['base_url'] ?? 'https://api.stripe.com/v1'), '/');

        try {
            $response = $this->httpClient->request('POST', $baseUrl.'/'.$path, [
                'auth_bearer' => $apiKey,
                'headers' => ['Idempotency-Key' => $idempotencyKey, 'Content-Type' => 'application/x-www-form-urlencoded'],
                'body' => http_build_query($body, '', '&'),
                'timeout' => 20,
            ]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw ProviderException::transient('Stripe unreachable: '.$e->getMessage(), $provider->getCode(), $e);
        }
        if ($status >= 400) {
            $message = 'Stripe error '.$status.': '.(string) ($data['error']['message'] ?? 'unknown');
            throw $status >= 500 || 429 === $status ? ProviderException::transient($message, $provider->getCode()) : ProviderException::permanent($message, $provider->getCode());
        }

        return $data;
    }
}
