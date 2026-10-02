<?php

declare(strict_types=1);

namespace App\Project\EventListener;

use App\Notification\Enum\NotificationType;
use App\Notification\NotificationService;
use App\Notification\Repository\NotificationRepository;
use App\Project\Enum\ProjectStatus;
use App\Project\Event\ProjectTransitionedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Customer and administrator notifications driven by the state machine, so that
 * every path (agents, admin, retries) notifies consistently and only once.
 */
#[AsEventListener]
final readonly class MilestoneNotifier
{
    private const CUSTOMER_MILESTONES = [
        ProjectStatus::Testing->value => NotificationType::DevelopmentCompleted,
        ProjectStatus::Deploying->value => NotificationType::TestingCompleted,
        ProjectStatus::Deployed->value => NotificationType::ProjectDeployed,
        ProjectStatus::Completed->value => NotificationType::ProjectDelivered,
    ];

    public function __construct(
        private NotificationService $notifications,
        private NotificationRepository $repository,
    ) {
    }

    public function __invoke(ProjectTransitionedEvent $event): void
    {
        $project = $event->project;

        if (\in_array($event->to, [ProjectStatus::WaitingAdminApproval, ProjectStatus::Failed], true)) {
            $this->notifications->notifyAdmins(NotificationType::AdminAttentionRequired, [
                'project' => $project->getName().' ('.$project->getReference().')',
                'reason' => $event->message,
            ], $project);

            return;
        }

        $type = self::CUSTOMER_MILESTONES[$event->to->value] ?? null;
        $user = $project->getCustomer()?->getUser();
        if (null === $type || null === $user || $this->repository->count(['project' => $project, 'type' => $type]) > 0) {
            return; // retries never notify the customer twice
        }
        $this->notifications->notifyUser($user, $type, array_filter([
            'project' => $project->getName(),
            'url' => $project->getDeploymentUrl(),
            'admin_url' => $project->getAdminUrl(),
        ], static fn (?string $v) => null !== $v && '' !== $v), $project);
    }
}
