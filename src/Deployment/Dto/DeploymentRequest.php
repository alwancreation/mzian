<?php

declare(strict_types=1);

namespace App\Deployment\Dto;

use App\Hosting\Entity\HostingAccount;

final class DeploymentRequest
{
    /**
     * @param array<string, mixed> $runtimeConfig written to app/config.php on the target (never committed)
     */
    public function __construct(
        public readonly string $slug,
        /** Generated application (public/, app/...). */
        public readonly string $sourceDir,
        public readonly string $version,
        public readonly ?string $domain,
        public readonly string $idempotencyKey,
        public readonly ?HostingAccount $hostingAccount = null,
        #[\SensitiveParameter]
        private readonly array $runtimeConfig = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function runtimeConfig(): array
    {
        return $this->runtimeConfig;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['slug' => $this->slug, 'version' => $this->version, 'domain' => $this->domain, 'runtimeConfig' => '***'];
    }
}
