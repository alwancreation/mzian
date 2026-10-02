<?php

declare(strict_types=1);

namespace App\Testing\Check;

use App\Testing\Enum\TestSeverity;
use App\Testing\Enum\TestStatus;

/**
 * Small builder used by the check suites.
 */
final class CheckList
{
    /** @var list<CheckResult> */
    private array $results = [];

    /**
     * @param array<string, mixed> $details
     */
    public function assert(bool $condition, string $code, string $name, string $category, TestSeverity $severity, string $success, string $failure, array $details = [], bool $warnOnly = false): bool
    {
        $status = $condition ? TestStatus::Passed : ($warnOnly ? TestStatus::Warning : TestStatus::Failed);
        $this->results[] = new CheckResult($code, $name, $category, $severity, $status, $condition ? $success : $failure, 0, $details);

        return $condition;
    }

    /**
     * @param array<string, mixed> $details
     */
    public function add(string $code, string $name, string $category, TestSeverity $severity, TestStatus $status, string $message, array $details = []): void
    {
        $this->results[] = new CheckResult($code, $name, $category, $severity, $status, $message, 0, $details);
    }

    public function skip(string $code, string $name, string $category, TestSeverity $severity, string $reason): void
    {
        $this->add($code, $name, $category, $severity, TestStatus::Skipped, $reason);
    }

    /**
     * @return list<CheckResult>
     */
    public function all(): array
    {
        return $this->results;
    }
}
