<?php

declare(strict_types=1);

namespace App\Hosting\Dto;

/**
 * Result of a hosting account creation. The password (when the provider returns
 * one) must be stored encrypted right away and is never logged or serialized.
 */
final class HostingProvisioning
{
    public function __construct(
        public readonly string $externalId,
        public readonly string $username,
        public readonly ?string $region,
        public readonly ?string $ipAddress,
        public readonly ?string $controlPanelUrl,
        public readonly ?\DateTimeImmutable $expiresAt,
        /** Yearly cost charged to Mzian, minor units. */
        public readonly int $cost,
        public readonly string $currency,
        public readonly bool $simulated,
        #[\SensitiveParameter]
        private readonly ?string $password = null,
    ) {
    }

    public function password(): ?string
    {
        return $this->password;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['externalId' => $this->externalId, 'username' => $this->username, 'password' => null !== $this->password ? '***' : null];
    }
}
