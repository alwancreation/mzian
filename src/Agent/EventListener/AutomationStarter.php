<?php

declare(strict_types=1);

namespace App\Agent\EventListener;

use App\Agent\AgentOrchestrator;
use App\Project\Event\ProjectAutomationEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Starts (or restarts) the agents once an administrator approved, resumed,
 * retried or unpaused a project.
 */
#[AsEventListener]
final readonly class AutomationStarter
{
    public function __construct(private AgentOrchestrator $orchestrator)
    {
    }

    public function __invoke(ProjectAutomationEvent $event): void
    {
        if (\in_array($event->reason, [ProjectAutomationEvent::RESUMED, ProjectAutomationEvent::RETRIED], true)) {
            $this->orchestrator->prepareRestart($event->project);
        }
        $this->orchestrator->kick($event->project);
    }
}
