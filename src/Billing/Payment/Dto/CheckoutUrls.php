<?php

declare(strict_types=1);

namespace App\Billing\Payment\Dto;

final readonly class CheckoutUrls
{
    public function __construct(
        public string $success,
        public string $cancel,
    ) {
    }
}
