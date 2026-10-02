<?php

declare(strict_types=1);

namespace App\Billing\Payment;

use App\Billing\Entity\Payment;
use App\Billing\Payment\Dto\CheckoutSession;
use App\Billing\Payment\Dto\CheckoutUrls;
use App\Billing\Payment\Dto\PaymentEvent;
use App\Billing\Payment\Dto\RefundResult;
use App\Provider\Entity\Provider;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\Request;

/**
 * Payment gateway driver (Stripe, bank transfer, mock...). See PROVIDERS.md.
 *
 * Rules: never handle card data (hosted checkout only), pass the payment
 * idempotency key to the gateway, authenticate every webhook.
 */
#[AutoconfigureTag('mzian.payment_provider')]
interface PaymentProviderInterface
{
    public static function getDriver(): string;

    /**
     * @throws \App\Provider\Exception\ProviderException
     */
    public function createCheckout(Provider $provider, Payment $payment, CheckoutUrls $urls): CheckoutSession;

    /**
     * Authenticates and normalizes a webhook request.
     *
     * @throws \App\Shared\Webhook\InvalidWebhookException when the signature is missing/invalid or the payload unreadable
     */
    public function parseWebhook(Provider $provider, Request $request): PaymentEvent;

    /**
     * @throws \App\Provider\Exception\ProviderException
     */
    public function refund(Provider $provider, Payment $payment, int $amount, string $idempotencyKey): RefundResult;
}
