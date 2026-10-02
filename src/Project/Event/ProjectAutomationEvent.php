<?php

declare(strict_types=1);

namespace App\Project\Event;

use App\Project\Entity\Project;

/**
 * Dispatched when an administrator lets the automation (re)start: approval,
 * resume after a hold, retry after a failure. The agent orchestrator listens to it.
 */
final readonly class ProjectAutomationEvent
{
    public const APPROVED = 'approved';
    public const RESUMED = 'resumed';
    public const RETRIED = 'retried';

    public function __construct(
        public Project $project,
        public string $reason,
    ) {
    }
}
