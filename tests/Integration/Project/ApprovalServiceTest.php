<?php

declare(strict_types=1);

namespace App\Tests\Integration\Project;

use App\Billing\Entity\Subscription;
use App\Billing\Enum\PaymentStatus;
use App\Billing\Enum\SubscriptionStatus;
use App\Billing\Repository\PaymentRepository;
use App\Notification\Entity\Notification;
use App\Notification\Enum\NotificationType;
use App\Order\Enum\OrderStatus;
use App\Project\Approval\ApprovalService;
use App\Project\Entity\Project;
use App\Project\Enum\ProjectStatus;
use App\Project\Event\ProjectAutomationEvent;
use App\Provider\ProviderRegistry;
use App\Security\Entity\User;
use App\Shared\Security\Actor;
use App\Tests\Support\CommerceFixtureTrait;
use App\Tests\Support\Factory;
use App\Tests\Support\PlatformFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class ApprovalServiceTest extends KernelTestCase
{
    use CommerceFixtureTrait;
    use PlatformFixtureTrait;

    private ApprovalService $approvals;
    private EntityManagerInterface $em;
    private Factory $factory;
    private User $admin;
    /** @var list<ProjectAutomationEvent> */
    private array $automation = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->setUpPlatform();
        $this->approvals = static::getContainer()->get(ApprovalService::class);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->factory = new Factory($this->em, static::getContainer()->get(UserPasswordHasherInterface::class));
        $this->admin = $this->factory->admin();
        static::getContainer()->get(EventDispatcherInterface::class)->addListener(ProjectAutomationEvent::class, function (ProjectAutomationEvent $e): void {
            $this->automation[] = $e;
        });
    }

    private function paidProject(?string $plan = null): Project
    {
        return $this->payOrder($this->placeOrder($this->factory->customer(), $plan))->getProject();
    }

    private function notifications(NotificationType $type): int
    {
        return $this->em->getRepository(Notification::class)->count(['type' => $type]);
    }

    public function testApprovalStartsTheAutomation(): void
    {
        $project = $this->paidProject();

        $this->as(self::adminActor($this->admin), fn () => $this->approvals->approve($project, 'Standard project'));

        self::assertSame(ProjectStatus::Approved, $project->getStatus());
        self::assertSame($this->admin, $project->getApprovedBy());
        self::assertSame('Standard project', $project->getAdminNotes());
        self::assertTrue($project->isSimulated(), 'Mock hosting/domain providers: the project is flagged as a simulation.');
        self::assertSame(1, $this->notifications(NotificationType::ProjectStarted));
        self::assertCount(1, $this->automation);
        self::assertSame(ProjectAutomationEvent::APPROVED, $this->automation[0]->reason);
    }

    public function testNobodyButAHumanAdministratorDecides(): void
    {
        $project = $this->paidProject();
        foreach ([Actor::agent('orchestrator'), Actor::system(), self::customerActor($project->getCustomer()), Actor::admin(999999, 'Ghost')] as $actor) {
            try {
                $this->as($actor, fn () => $this->approvals->approve($project));
                self::fail($actor->label().' must not approve.');
            } catch (AccessDeniedException) {
            }
        }
        self::assertSame(ProjectStatus::PendingAdminApproval, $project->getStatus());
        self::assertSame([], $this->automation);
    }

    public function testChangesRequestedThenCustomerAnswers(): void
    {
        $project = $this->paidProject();

        $this->as(self::adminActor($this->admin), fn () => $this->approvals->requestChanges($project, 'How many vehicles exactly?'));
        self::assertSame(ProjectStatus::ChangesRequested, $project->getStatus());
        self::assertSame('How many vehicles exactly?', $project->getChangesRequested());
        self::assertSame(1, $this->notifications(NotificationType::ChangesRequested));

        $before = $this->notifications(NotificationType::ApprovalRequired);
        $this->as(self::customerActor($project->getCustomer()), fn () => $this->approvals->resubmit($project, '23 vehicles.'));
        self::assertSame(ProjectStatus::PendingAdminApproval, $project->getStatus());
        self::assertNull($project->getChangesRequested());
        self::assertGreaterThan($before, $this->notifications(NotificationType::ApprovalRequired));

        $this->expectException(\InvalidArgumentException::class);
        $this->as(self::adminActor($this->admin), fn () => $this->approvals->requestChanges($project, '   '));
    }

    public function testRejectionRefundsAndCancelsTheSubscription(): void
    {
        $project = $this->paidProject('business');

        $result = $this->as(self::adminActor($this->admin), fn () => $this->approvals->reject($project, 'We cannot build GPS tracking.'));

        self::assertSame(['refunded' => true, 'refund_error' => null], $result);
        self::assertSame(ProjectStatus::Cancelled, $project->getStatus());
        self::assertSame(OrderStatus::Refunded, $project->getOrder()?->getStatus());
        $payment = static::getContainer()->get(PaymentRepository::class)->findOneBy(['order' => $project->getOrder()]);
        self::assertSame(PaymentStatus::Refunded, $payment?->getStatus());
        self::assertSame(SubscriptionStatus::Cancelled, $this->em->getRepository(Subscription::class)->findOneBy(['project' => $project])?->getStatus());
        self::assertSame(1, $this->notifications(NotificationType::ProjectRejected));
    }

    public function testFailedRefundIsReportedToAdministrators(): void
    {
        $project = $this->paidProject();
        $provider = static::getContainer()->get(ProviderRegistry::class)->findByCode('mock_payment');
        $provider?->setSettings(['simulate_failures' => ['refund' => 'permanent']] + $provider->getSettings());
        $this->em->flush();

        $result = $this->as(self::adminActor($this->admin), fn () => $this->approvals->reject($project, 'Out of scope.'));

        self::assertFalse($result['refunded']);
        self::assertStringContainsString('refund', (string) $result['refund_error']);
        self::assertSame(ProjectStatus::Cancelled, $project->getStatus(), 'The rejection stands; the refund is done manually.');
        self::assertSame(OrderStatus::Paid, $project->getOrder()?->getStatus());
        self::assertGreaterThanOrEqual(1, $this->notifications(NotificationType::AdminAttentionRequired));
    }

    public function testPauseAndUnpauseAreAudited(): void
    {
        $project = $this->paidProject();
        $admin = self::adminActor($this->admin);
        $this->as($admin, fn () => $this->approvals->approve($project));

        $this->as($admin, fn () => $this->approvals->pause($project));
        self::assertTrue($project->isAutomationPaused());
        $this->as($admin, fn () => $this->approvals->unpause($project));
        self::assertFalse($project->isAutomationPaused());
        self::assertCount(2, $this->automation, 'Approval + unpause restart the automation.');
    }
}
