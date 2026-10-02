<?php

declare(strict_types=1);

namespace App\Project\Workflow;

use App\Project\Entity\Project;
use App\Project\Entity\ProjectEvent;
use App\Project\Enum\ProjectStatus;
use App\Project\Event\ProjectTransitionedEvent;
use App\Shared\Audit\AuditLogger;
use App\Shared\Security\CurrentActor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * The only way to change a project status. Each transition is checked by the
 * workflow guards (state + actor + preconditions) and recorded as a ProjectEvent
 * (timeline) and an AuditLog entry (who, what, when, old/new values).
 */
final readonly class ProjectStateMachine
{
    public function __construct(
        #[Target('project.state_machine')]
        private WorkflowInterface $workflow,
        private EntityManagerInterface $em,
        private AuditLogger $audit,
        private CurrentActor $actor,
        private LoggerInterface $logger,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    public function can(Project $project, string $transition): bool
    {
        return $this->workflow->can($project, $transition);
    }

    /**
     * Transitions the current actor may apply now.
     *
     * @return list<string>
     */
    public function available(Project $project): array
    {
        return array_values(array_map(static fn ($t) => $t->getName(), $this->workflow->getEnabledTransitions($project)));
    }

    /**
     * @param array<string, mixed> $metadata context stored with the event (never secrets)
     *
     * @throws IllegalTransitionException
     */
    public function apply(Project $project, string $transition, ?string $message = null, array $metadata = [], bool $flush = true): void
    {
        $from = $project->getStatus();
        $blockers = $this->workflow->buildTransitionBlockerList($project, $transition);
        if (!$blockers->isEmpty()) {
            $reasons = array_map(static fn ($b) => $b->getMessage(), iterator_to_array($blockers));
            $this->logger->warning('Refused project transition', ['project' => $project->getReference(), 'transition' => $transition, 'from' => $from->value, 'reasons' => $reasons]);

            throw new IllegalTransitionException($transition, $from->value, $reasons);
        }

        $actor = $this->actor->get();
        $this->workflow->apply($project, $transition, ['actor' => $actor->label()]);
        $to = $project->getStatus();
        $label = (string) ($this->workflow->getMetadataStore()->getTransitionMetadata($this->transition($transition))['title'] ?? $transition);

        $event = new ProjectEvent($project, 'transition', $actor->type, $actor->name, $message ?? $label, $transition, $from, $to, $metadata);
        $this->em->persist($event);
        $this->audit->log('project.transition', $project, ['status' => $from->value], ['status' => $to->value], ['transition' => $transition, 'reference' => $project->getReference()] + $metadata);
        if ($flush) {
            $this->em->flush();
        }
        $this->dispatcher->dispatch(new ProjectTransitionedEvent($project, $transition, $from, $to, $actor, $message ?? $label, $metadata));
    }

    /**
     * Applies the transition only when it is allowed (idempotent helpers, e.g. a webhook received twice).
     *
     * @param array<string, mixed> $metadata
     */
    public function applyIfPossible(Project $project, string $transition, ?string $message = null, array $metadata = [], bool $flush = true): bool
    {
        if (!$this->can($project, $transition)) {
            return false;
        }
        $this->apply($project, $transition, $message, $metadata, $flush);

        return true;
    }

    /**
     * Stops the automation and asks an administrator (budget exceeded, repeated failures...).
     *
     * @param array<string, mixed> $metadata
     */
    public function hold(Project $project, string $reason, array $metadata = []): void
    {
        $resumeTo = $project->getStatus();
        $this->apply($project, 'hold', $reason, $metadata + ['resume_to' => $resumeTo->value], false);
        $project->hold($resumeTo, $reason);
        $this->em->flush();
    }

    /**
     * Administrator decision: resume a held project where it stopped (or at another automation step).
     */
    public function resume(Project $project, ?ProjectStatus $to = null, ?string $message = null): void
    {
        $to ??= $project->getResumeStatus() ?? throw new IllegalTransitionException('resume', $project->getStatus()->value, ['No resume step recorded.']);
        $this->apply($project, ProjectWorkflowDefinition::resumeTransition($to), $message, ['hold_reason' => $project->getHoldReason()], false);
        $project->clearHold();
        $this->em->flush();
    }

    /**
     * Marks the project failed, remembering the step to retry.
     *
     * @param array<string, mixed> $metadata
     */
    public function fail(Project $project, string $reason, array $metadata = []): void
    {
        $failedAt = $project->getStatus();
        $this->apply($project, 'fail', $reason, $metadata + ['failed_at' => $failedAt->value], false);
        $project->hold($failedAt, $reason);
        $this->em->flush();
    }

    /**
     * Administrator decision: retry a failed project at an automation step (by default where it failed).
     */
    public function retry(Project $project, ?ProjectStatus $to = null, ?string $message = null): void
    {
        $to ??= $project->getResumeStatus() ?? throw new IllegalTransitionException('retry', $project->getStatus()->value, ['No failed step recorded.']);
        $this->apply($project, ProjectWorkflowDefinition::retryTransition($to), $message, ['failure' => $project->getHoldReason()], false);
        $project->clearHold();
        $this->em->flush();
    }

    private function transition(string $name): Transition
    {
        foreach ($this->workflow->getDefinition()->getTransitions() as $transition) {
            if ($transition->getName() === $name) {
                return $transition;
            }
        }

        throw new \LogicException('Unknown transition '.$name);
    }
}
