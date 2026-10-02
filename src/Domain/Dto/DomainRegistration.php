<?php

declare(strict_types=1);

namespace App\Domain\Dto;

final readonly class DomainRegistration
{
    /**
     * @param list<string> $nameservers
     */
    public function __construct(
        public string $domain,
        public string $externalId,
        public int $cost,
        public string $currency,
        public \DateTimeImmutable $expiresAt,
        public array $nameservers,
        public bool $simulated,
    ) {
    }
}
