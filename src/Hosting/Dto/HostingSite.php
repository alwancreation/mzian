<?php

declare(strict_types=1);

namespace App\Hosting\Dto;

/**
 * A website (vhost) created on a hosting account. Secrets are kept private.
 */
final class HostingSite
{
    public function __construct(
        public readonly string $externalId,
        public readonly string $documentRoot,
        public readonly ?string $phpVersion,
        public readonly ?string $databaseName,
        public readonly ?string $databaseUser,
        public readonly bool $sslEnabled,
        #[\SensitiveParameter]
        private readonly ?string $databasePassword = null,
    ) {
    }

    public function databasePassword(): ?string
    {
        return $this->databasePassword;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['externalId' => $this->externalId, 'documentRoot' => $this->documentRoot, 'databasePassword' => null !== $this->databasePassword ? '***' : null];
    }
}
