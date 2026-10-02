<?php

declare(strict_types=1);

namespace App\Billing\Payment\Dto;

final readonly class RefundResult
{
    public function __construct(
        public string $reference,
        public int $amount,
        public bool $pending = false,
    ) {
    }
}
