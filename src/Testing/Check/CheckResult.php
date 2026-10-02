<?php

declare(strict_types=1);

namespace App\Testing\Check;

use App\Testing\Enum\TestSeverity;
use App\Testing\Enum\TestStatus;

/**
 * Outcome of one technical check actually executed (never declared by an AI).
 */
final readonly class CheckResult
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public string $code,
        public string $name,
        public string $category,
        public TestSeverity $severity,
        public TestStatus $status,
        public string $message,
        public int $durationMs = 0,
        public array $details = [],
    ) {
    }

    public function isCriticalFailure(): bool
    {
        return TestSeverity::Critical === $this->severity && TestStatus::Failed === $this->status;
    }
}
