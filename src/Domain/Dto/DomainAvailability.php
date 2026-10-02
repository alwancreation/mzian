<?php

declare(strict_types=1);

namespace App\Domain\Dto;

final readonly class DomainAvailability
{
    public function __construct(
        public string $domain,
        public bool $available,
        /** Yearly registration price, minor units. */
        public int $price,
        public string $currency,
        public bool $premium = false,
    ) {
    }
}
