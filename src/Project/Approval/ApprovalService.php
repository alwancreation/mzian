<?php

declare(strict_types=1);

namespace App\Project\Approval;

use App\Billing\Repository\SubscriptionRepository;
use App\Billing\Service\PaymentService;
use App\Notification\Enum\NotificationType;
use App\Notification\NotificationService;
use App\Project\Entity\Project;
use App\Project\Entity\ProjectEvent;
use App\Project\Enum\CostCategory;
use App\Project\Enum\ProjectStatus;
use App\Project\Event\ProjectAutomationEvent;
use App\Project\Workflow\ProjectStateMachine;
use App\Provider\Enum\ProviderType;
use App\Provider\Enum\ProvisioningMethod;
use App\Provider\Exception\ProviderException;
use App\Provider\ProviderRegistry;
use App\Security\Entity\User;
use App\Shared\Audit\AuditLogger;
use App\Shared\I18n\MoneyFormatter;
use App\Shared\Security\CurrentActor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The mandatory human decision between payment and automation:
 * approve (agents start), request changes (customer answers), reject (refund).
 * Also the administrator's resume/retry/cancel decisions on running projects.
 *
 * Only a human administrator can call these (state machine guards + explicit check).
 */
final readonly class ApprovalService
{
    public function __construct(
        private ProjectStateMachine $stateMachine,
        private PaymentService $payments,
        private SubscriptionRepository $subscriptions,
        private NotificationService $notifications,
        private ProviderRegistry $providers,
        private EventDispatcherInterface $dispatcher,
        private CurrentActor $actor,
        private AuditLogger $audit,
        private EntityManagerInterface $em,
    ) {
    }

    public function approve(Project $project, ?string $note = null): void
    {
        $admin = $this->admin();
        $this->stateMachine->apply($project, 'approve', null, array_filter(['note' => $note]), false);
        $project->recordApproval($admin);
        if (null !== $note && '' !== trim($note)) {
            $project->setAdminNotes(trim($note));
        }
        if ($this->usesSimulatedProviders($project)) {
            $project->markSimulated();
        }
        $this->em->flush();

        $this->notifyCustomer($project, NotificationType::ProjectStarted);
        $this->dispatcher->dispatch(new ProjectAutomationEvent($project, ProjectAutomationEvent::APPROVED));
    }

    public function requestChanges(Project $project, string $message): void
    {
        $this->admin();
        $message = self::clean($message);
        $this->stateMachine->apply($project, 'request_changes', null, ['message' => $message], false);
        $project->setChangesRequested($message);
        $this->em->flush();

        $this->notifyCustomer($project, NotificationType::ChangesRequested, ['message' => $message]);
    }

    /**
     * The customer answers the administrator's questions.
     */
    public function resubmit(Project $project, string $reply): void
    {
        $reply = self::clean($reply);
        $this->stateMachine->apply($project, 'resubmit', 'Customer answer: '.mb_substr($reply, 0, 400), ['reply' => $reply], false);
        $project->setChangesRequested(null);
        $this->em->flush();

        $order = $project->getOrder();
        $this->notifications->notifyAdmins(NotificationType::ApprovalRequired, [
            'project' => $project->getName(),
            'customer' => $project->getCustomer()?->getDisplayName() ?? '—',
            'order' => $order?->getNumber() ?? '—',
            'amount' => null !== $order ? MoneyFormatter::format($order->getTotal(), $order->getCurrency(), 'en') : '—',
        ], $project);
    }

    /**
     * @return array{refunded: bool, refund_error: ?string}
     */
    public function reject(Project $project, string $reason, bool $refund = true): array
    {
        $this->admin();
        $reason = self::clean($reason);
        $this->stateMachine->apply($project, 'reject', 'Rejected: '.mb_substr($reason, 0, 400), ['reason' => $reason, 'refund' => $refund], false);
        $project->setRejectionReason($reason);
        $result = $this->closeCommercially($project, $reason, $refund);
        $this->notifyCustomer($project, NotificationType::ProjectRejected, ['message' => $reason]);

        return $result;
    }

    /**
     * Stops a project for good (any non-terminal state after payment).
     *
     * @return array{refunded: bool, refund_error: ?string}
     */
    public function cancel(Project $project, string $reason, bool $refund = false): array
    {
        $this->admin();
        $reason = self::clean($reason);
        $transition = $this->stateMachine->can($project, 'cancel') ? 'cancel' : 'abandon';
        $this->stateMachine->apply($project, $transition, 'Cancelled: '.mb_substr($reason, 0, 400), ['reason' => $reason, 'refund' => $refund], false);
        $project->setRejectionReason($reason);

        return $this->closeCommercially($project, $reason, $refund);
    }

    /**
     * Resumes a project put on hold by an agent (WAITING_ADMIN_APPROVAL).
     */
    public function resume(Project $project, ?ProjectStatus $at = null): void
    {
        $this->admin();
        $this->stateMachine->resume($project, $at);
        $this->dispatcher->dispatch(new ProjectAutomationEvent($project, ProjectAutomationEvent::RESUMED));
    }

    /**
     * Retries a failed project (by default at the step that failed).
     */
    public function retry(Project $project, ?ProjectStatus $at = null): void
    {
        $this->admin();
        $this->stateMachine->retry($project, $at);
        $this->dispatcher->dispatch(new ProjectAutomationEvent($project, ProjectAutomationEvent::RETRIED));
    }

    /**
     * The spending an agent is waiting for (from the last hold), if any.
     *
     * @return array{category: string, amount: int, description: string}|null
     */
    public function pendingSpending(Project $project): ?array
    {
        if (ProjectStatus::WaitingAdminApproval !== $project->getStatus()) {
            return null;
        }
        $hold = $this->em->getRepository(ProjectEvent::class)->findOneBy(['project' => $project, 'transition' => 'hold'], ['id' => 'DESC']);
        $spending = $hold?->getMetadata()['spending'] ?? null;

        return \is_array($spending) && isset($spending['category'], $spending['amount']) ? ['category' => (string) $spending['category'], 'amount' => (int) $spending['amount'], 'description' => (string) ($spending['description'] ?? '')] : null;
    }

    /**
     * Explicit approval of a spending above the automation rules, then resume.
     */
    public function approveSpending(Project $project): void
    {
        $this->admin();
        $spending = $this->pendingSpending($project) ?? throw new \InvalidArgumentException('No spending is waiting for approval.');
        $category = CostCategory::from($spending['category']);
        $project->approveSpending($category, $spending['amount']);
        $this->audit->log('budget.spending_approved', $project, null, ['category' => $category->value, 'amount' => $spending['amount']], ['reference' => $project->getReference(), 'description' => $spending['description']]);
        $this->em->flush();
        $this->resume($project);
    }

    public function pause(Project $project): void
    {
        $this->admin();
        $project->pauseAutomation();
        $this->audit->log('project.automation_paused', $project, null, null, ['reference' => $project->getReference()]);
        $this->em->flush();
    }

    public function unpause(Project $project): void
    {
        $this->admin();
        $project->resumeAutomation();
        $this->audit->log('project.automation_unpaused', $project, null, null, ['reference' => $project->getReference()]);
        $this->em->flush();
        if ($project->getStatus()->isAutomation()) {
            $this->dispatcher->dispatch(new ProjectAutomationEvent($project, ProjectAutomationEvent::RESUMED));
        }
    }

    /**
     * @return array{refunded: bool, refund_error: ?string}
     */
    private function closeCommercially(Project $project, string $reason, bool $refund): array
    {
        foreach ($this->subscriptions->findBy(['project' => $project]) as $subscription) {
            $subscription->cancel();
        }
        $this->em->flush();

        $order = $project->getOrder();
        if (!$refund || null === $order || !$order->isPaid()) {
            return ['refunded' => false, 'refund_error' => null];
        }
        try {
            $this->payments->refund($order, $reason);

            return ['refunded' => true, 'refund_error' => null];
        } catch (ProviderException $e) {
            $this->audit->log('payment.refund_failed', $order, null, null, ['error' => $e->getMessage(), 'reference' => $project->getReference()]);
            $this->em->flush();
            $this->notifications->notifyAdmins(NotificationType::AdminAttentionRequired, ['project' => $project->getName(), 'reason' => 'automatic refund failed, refund the customer manually ('.$e->getMessage().')'], $project);

            return ['refunded' => false, 'refund_error' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    private function notifyCustomer(Project $project, NotificationType $type, array $parameters = []): void
    {
        $user = $project->getCustomer()?->getUser();
        if (null !== $user) {
            $this->notifications->notifyUser($user, $type, ['project' => $project->getName()] + $parameters, $project);
        }
    }

    /**
     * Projects whose infrastructure providers are simulated are flagged: the customer
     * and the QA agent know the deployment is not a real public website.
     */
    private function usesSimulatedProviders(Project $project): bool
    {
        foreach ([ProviderType::Hosting, ProviderType::Domain, ProviderType::Deployment] as $type) {
            try {
                if (ProvisioningMethod::Mock === $this->providers->resolve($type, $project)->getProvisioningMethod()) {
                    return true;
                }
            } catch (ProviderException) {
                continue;
            }
        }

        return false;
    }

    private function admin(): User
    {
        $actor = $this->actor->get();
        $user = $actor->isAdmin() && null !== $actor->id ? $this->em->find(User::class, (int) $actor->id) : null;
        if (!$user instanceof User || !$user->isAdmin() || !$user->isActive()) {
            throw new AccessDeniedException('Only a human administrator can take this decision.');
        }

        return $user;
    }

    private static function clean(string $text): string
    {
        $text = trim($text);
        if ('' === $text) {
            throw new \InvalidArgumentException('A message is required.');
        }

        return mb_substr($text, 0, 2000);
    }
}
