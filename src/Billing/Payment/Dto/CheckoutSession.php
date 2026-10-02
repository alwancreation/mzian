<?php

declare(strict_types=1);

namespace App\Billing\Payment\Dto;

/**
 * Where to send the customer to pay.
 */
final readonly class CheckoutSession
{
    public function __construct(
        public string $redirectUrl,
        public ?string $providerReference,
        public string $method,
        /** True when the payment is confirmed later by a human (bank transfer). */
        public bool $offline = false,
    ) {
    }
}
