<?php

declare(strict_types=1);

namespace App\Project\Event;

use App\Project\Entity\Project;
use App\Project\Enum\ProjectStatus;
use App\Shared\Security\Actor;

/**
 * Dispatched by ProjectStateMachine after every applied transition.
 */
final readonly class ProjectTransitionedEvent
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public Project $project,
        public string $transition,
        public ProjectStatus $from,
        public ProjectStatus $to,
        public Actor $actor,
        public string $message,
        public array $metadata = [],
    ) {
    }
}
