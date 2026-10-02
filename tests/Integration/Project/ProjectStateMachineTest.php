<?php

declare(strict_types=1);

namespace App\Tests\Integration\Project;

use App\Project\Entity\Project;
use App\Project\Entity\ProjectEvent;
use App\Project\Enum\ProjectStatus;
use App\Project\Workflow\IllegalTransitionException;
use App\Project\Workflow\ProjectStateMachine;
use App\Project\Workflow\ProjectWorkflowDefinition;
use App\Shared\Entity\AuditLog;
use App\Shared\Enum\ActorType;
use App\Shared\Security\Actor;
use App\Tests\Support\CommerceFixtureTrait;
use App\Tests\Support\Factory;
use App\Tests\Support\PlatformFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ProjectStateMachineTest extends KernelTestCase
{
    use CommerceFixtureTrait;
    use PlatformFixtureTrait;

    private ProjectStateMachine $machine;
    private Factory $factory;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->setUpPlatform();
        $this->machine = static::getContainer()->get(ProjectStateMachine::class);
        $this->factory = new Factory(static::getContainer()->get(EntityManagerInterface::class), static::getContainer()->get(UserPasswordHasherInterface::class));
    }

    private function paidProject(): Project
    {
        return $this->payOrder($this->placeOrder($this->factory->customer()))->getProject();
    }

    public function testDefinitionCoversEveryStatusAndOnlyHumansDecide(): void
    {
        $transitions = ProjectWorkflowDefinition::transitions();
        $reachable = [ProjectStatus::Draft];
        foreach ($transitions as $name => $t) {
            $reachable[] = $t['to'];
            if (\in_array($name, ['approve', 'reject', 'request_changes', 'cancel'], true) || str_starts_with($name, 'resume_to_') || str_starts_with($name, 'retry_to_')) {
                self::assertSame([ActorType::Admin], $t['actors'], $name.' is reserved to human administrators.');
            }
            if (\in_array(ActorType::Agent, $t['actors'], true)) {
                self::assertNotContains($t['to'], [ProjectStatus::Approved, ProjectStatus::Cancelled], 'Agents never approve nor cancel ('.$name.').');
            }
        }
        foreach (ProjectStatus::cases() as $status) {
            self::assertContains($status, $reachable, $status->value.' is reachable.');
        }
    }

    public function testQuoteRecordsTransitionsAsEventsAndAuditLogs(): void
    {
        $project = $this->issueQuote()->getProject();

        self::assertSame(ProjectStatus::Quoted, $project->getStatus());
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $events = $em->getRepository(ProjectEvent::class)->findBy(['project' => $project], ['id' => 'ASC']);
        self::assertSame(['analyze', 'quote'], array_map(static fn (ProjectEvent $e) => $e->getTransition(), $events));
        self::assertSame(ProjectStatus::Draft, $events[0]->getFromStatus());
        self::assertSame(ProjectStatus::Quoted, $events[1]->getToStatus());
        self::assertSame(2, $em->getRepository(AuditLog::class)->count(['action' => 'project.transition']));
    }

    public function testVisitorsAndAgentsCannotSkipTheSaleOrTheApproval(): void
    {
        $project = $this->issueQuote()->getProject();

        // Ordering needs a customer; nobody can jump to approval before payment.
        $this->as(Actor::visitor(), fn () => self::assertFalse($this->machine->can($project, 'order')));
        $this->as(self::adminActor($this->factory->admin()), fn () => self::assertFalse($this->machine->can($project, 'approve')));

        $this->expectException(IllegalTransitionException::class);
        $this->as(Actor::agent('development'), fn () => $this->machine->apply($project, 'start_provisioning'));
    }

    public function testOnlyAnAdministratorApprovesAPaidProject(): void
    {
        $project = $this->paidProject();
        self::assertSame(ProjectStatus::PendingAdminApproval, $project->getStatus());

        foreach ([Actor::agent('qa'), Actor::system(), Actor::webhook('mock_payment'), self::customerActor($project->getCustomer())] as $actor) {
            try {
                $this->as($actor, fn () => $this->machine->apply($project, 'approve'));
                self::fail($actor->label().' must not approve.');
            } catch (IllegalTransitionException $e) {
                self::assertStringContainsString('cannot apply "approve"', $e->getMessage());
            }
        }

        $this->as(self::adminActor($this->factory->admin()), fn () => $this->machine->apply($project, 'approve', 'Looks good'));
        self::assertSame(ProjectStatus::Approved, $project->getStatus());
    }

    public function testAgentsRunThePipelineHoldAndOnlyAdminsResume(): void
    {
        $project = $this->paidProject();
        $admin = self::adminActor($this->factory->admin());
        $this->as($admin, fn () => $this->machine->apply($project, 'approve'));

        $agent = Actor::agent('hosting');
        $this->as($agent, fn () => $this->machine->apply($project, 'start_provisioning'));
        $this->as($agent, fn () => $this->machine->hold($project, 'Hosting cost above the automation budget'));
        self::assertSame(ProjectStatus::WaitingAdminApproval, $project->getStatus());
        self::assertSame(ProjectStatus::Provisioning, $project->getResumeStatus());

        try {
            $this->as($agent, fn () => $this->machine->resume($project));
            self::fail('An agent cannot resume a held project.');
        } catch (IllegalTransitionException) {
        }

        $this->as($admin, fn () => $this->machine->resume($project));
        self::assertSame(ProjectStatus::Provisioning, $project->getStatus());
        self::assertNull($project->getHoldReason());

        $this->as($agent, fn () => $this->machine->fail($project, 'Provider error'));
        self::assertSame(ProjectStatus::Failed, $project->getStatus());
        $this->as($admin, fn () => $this->machine->retry($project));
        self::assertSame(ProjectStatus::Provisioning, $project->getStatus());

        foreach (['hosting_ready', 'domain_ready', 'start_development', 'start_testing', 'start_deployment', 'deployed', 'start_qa', 'start_delivery', 'complete'] as $transition) {
            $this->as(Actor::agent('pipeline'), fn () => $this->machine->apply($project, $transition));
        }
        self::assertSame(ProjectStatus::Completed, $project->getStatus());
        self::assertSame(100, $project->getStatus()->progress());
    }
}
