<?php

declare(strict_types=1);

namespace App\Billing\Payment\Dto;

/**
 * A verified payment notification, normalized across providers.
 */
final readonly class PaymentEvent
{
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';
    public const REFUNDED = 'refunded';
    public const IGNORED = 'ignored';

    /**
     * @param array<string, scalar|null> $summary non-sensitive data kept for the audit (never card data)
     */
    public function __construct(
        public string $eventId,
        public string $type,
        public string $providerEventType,
        public ?string $paymentKey = null,
        public ?string $providerReference = null,
        public ?int $amount = null,
        public ?string $currency = null,
        public ?string $failureReason = null,
        public array $summary = [],
    ) {
    }
}
