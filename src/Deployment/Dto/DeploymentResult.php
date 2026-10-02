<?php

declare(strict_types=1);

namespace App\Deployment\Dto;

final readonly class DeploymentResult
{
    /**
     * @param list<string> $logs
     */
    public function __construct(
        /** Public URL of the website. */
        public string $url,
        /** URL the QA agent can reach from the platform (may differ inside Docker). */
        public string $internalUrl,
        public string $adminUrl,
        public string $externalId,
        public array $logs,
        /** True when the target is not the customer's real public hosting. */
        public bool $simulated,
    ) {
    }
}
