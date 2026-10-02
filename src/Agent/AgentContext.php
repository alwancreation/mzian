<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Entity\AgentRun;
use App\Project\Entity\Project;
use App\Project\Entity\ProjectTask;
use App\Project\Enum\PipelineStep;
use App\Shared\Audit\AuditLogger;

/**
 * What an agent receives for one operation. Logs go to the AgentRun (sanitized:
 * secrets are masked) and are visible in Admin > Agents.
 */
final readonly class AgentContext
{
    public function __construct(
        public Project $project,
        public ProjectTask $task,
        public AgentRun $run,
        public string $operation,
        public int $attempt,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(string $message, array $context = [], string $level = 'info'): void
    {
        $this->run->log($level, $message, AuditLogger::sanitize($context));
    }

    /**
     * Output of a previous pipeline step (e.g. the development build directory).
     *
     * @return array<string, mixed>
     */
    public function outputOf(PipelineStep $step): array
    {
        return $this->project->getTask($step)?->getOutput() ?? [];
    }

    public function idempotencyKey(string $suffix): string
    {
        return $this->project->getReference().':'.$suffix;
    }
}
