<?php

declare(strict_types=1);

namespace App\Project\Workflow\EventListener;

use App\Project\Entity\Project;
use App\Shared\Security\CurrentActor;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\TransitionBlocker;

/**
 * Who may apply a transition (metadata "actors" of ProjectWorkflowDefinition),
 * plus business preconditions that no actor can bypass.
 */
final readonly class ActorGuardListener
{
    public const CODE_ACTOR = 'mzian.actor_not_allowed';
    public const CODE_PRECONDITION = 'mzian.precondition';

    public function __construct(private CurrentActor $actor)
    {
    }

    #[AsEventListener('workflow.project.guard')]
    public function __invoke(GuardEvent $event): void
    {
        $actor = $this->actor->get();
        $allowed = (array) ($event->getMetadata('actors', $event->getTransition()) ?? []);
        if (!\in_array($actor->type->value, $allowed, true)) {
            $event->addTransitionBlocker(new TransitionBlocker(\sprintf('"%s" cannot apply "%s".', $actor->label(), $event->getTransition()->getName()), self::CODE_ACTOR));

            return;
        }

        /** @var Project $project */
        $project = $event->getSubject();
        $quote = $project->getCurrentQuote();
        $blocker = match ($event->getTransition()->getName()) {
            'quote' => null === $quote ? 'No quote issued.' : null,
            'order' => null === $quote || !$quote->isAcceptable() ? 'The quote is missing or no longer valid.' : null,
            'pay', 'submit_for_approval', 'approve' => true === $project->getOrder()?->isPaid() ? null : 'The order is not paid.',
            default => null,
        };
        if (null !== $blocker) {
            $event->addTransitionBlocker(new TransitionBlocker($blocker, self::CODE_PRECONDITION));
        }
    }
}
