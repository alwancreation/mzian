<?php

declare(strict_types=1);

namespace App\Billing\Payment\Provider;

use App\Billing\Entity\Payment;
use App\Billing\Payment\Dto\CheckoutSession;
use App\Billing\Payment\Dto\CheckoutUrls;
use App\Billing\Payment\Dto\PaymentEvent;
use App\Billing\Payment\Dto\RefundResult;
use App\Billing\Payment\PaymentProviderInterface;
use App\Provider\Entity\Provider;
use App\Shared\Webhook\InvalidWebhookException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Bank transfer / cash: the customer receives the payment instructions (provider
 * settings: beneficiary, IBAN, BIC, bank) and an administrator confirms the
 * payment once received (Admin > Payments). No webhook.
 */
final readonly class ManualPaymentProvider implements PaymentProviderInterface
{
    public static function getDriver(): string
    {
        return 'manual';
    }

    public function createCheckout(Provider $provider, Payment $payment, CheckoutUrls $urls): CheckoutSession
    {
        return new CheckoutSession($urls->success, $payment->getIdempotencyKey(), (string) ($provider->getSettings()['method'] ?? 'bank_transfer'), true);
    }

    public function parseWebhook(Provider $provider, Request $request): PaymentEvent
    {
        throw new InvalidWebhookException('Manual payments are confirmed by an administrator, never by webhook.');
    }

    public function refund(Provider $provider, Payment $payment, int $amount, string $idempotencyKey): RefundResult
    {
        // The administrator sends the money back manually; the refund is recorded as pending.
        return new RefundResult('manual-'.$idempotencyKey, $amount, true);
    }
}
