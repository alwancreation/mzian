<?php

declare(strict_types=1);

namespace App\Agent;

final readonly class AgentResult
{
    /**
     * @param array<string, mixed> $output stored on the task (never secrets)
     */
    public function __construct(
        public string $summary,
        public array $output = [],
    ) {
    }
}
