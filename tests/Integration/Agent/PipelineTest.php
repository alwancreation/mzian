<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Agent\Entity\AgentRun;
use App\Billing\Entity\Subscription;
use App\Billing\Enum\SubscriptionStatus;
use App\Domain\Entity\Domain;
use App\Hosting\Entity\HostingAccount;
use App\Notification\Entity\Notification;
use App\Notification\Enum\NotificationType;
use App\Project\Approval\ApprovalService;
use App\Project\Entity\Project;
use App\Project\Enum\CostCategory;
use App\Project\Enum\PipelineStep;
use App\Project\Enum\ProjectStatus;
use App\Project\Enum\ProjectTaskStatus;
use App\Testing\Entity\TestRun;
use App\Testing\Enum\TestRunType;
use App\Tests\Support\CommerceFixtureTrait;
use App\Tests\Support\Factory;
use App\Tests\Support\PipelineRunnerTrait;
use App\Tests\Support\PlatformFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The whole automation, with the mock/local providers: approval → hosting →
 * domain → repository + generation → tests → deployment → QA → delivery.
 */
final class PipelineTest extends KernelTestCase
{
    use CommerceFixtureTrait;
    use PipelineRunnerTrait;
    use PlatformFixtureTrait;

    private EntityManagerInterface $em;
    private Factory $factory;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->setUpPlatform();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->factory = new Factory($this->em, static::getContainer()->get(UserPasswordHasherInterface::class));
        $this->startAppsServer();
    }

    protected function tearDown(): void
    {
        $this->stopAppsServer();
        $container = static::getContainer();
        (new Filesystem())->remove([$container->getParameter('mzian.builds_dir'), $container->getParameter('mzian.repositories_dir'), $container->getParameter('mzian.deployments_dir')]);
        parent::tearDown();
    }

    private function approvedProject(string $plan = 'business'): Project
    {
        $project = $this->payOrder($this->placeOrder($this->factory->customer('karim@atlas.ma'), $plan))->getProject();
        $this->as(self::adminActor($this->factory->admin()), fn () => static::getContainer()->get(ApprovalService::class)->approve($project));

        return $project;
    }

    public function testFromApprovalToDelivery(): void
    {
        $project = $this->approvedProject();

        $operations = $this->runPipeline();
        if (ProjectStatus::Completed !== $project->getStatus()) {
            foreach ($this->em->getRepository(AgentRun::class)->findBy(['project' => $project]) as $run) {
                fwrite(\STDERR, $run->getAgentCode().' '.$run->getStatus()->value.' '.$run->getError().' '.json_encode($run->getLogs())."\n");
            }
            fwrite(\STDERR, $project->getStatus()->value.' '.$project->getHoldReason()."\n");
        }

        self::assertSame(['provision_hosting', 'register_domain', 'create_project', 'generate_application', 'run_tests', 'deploy_application', 'run_qa', 'send_delivery'], $operations);
        self::assertSame(ProjectStatus::Completed, $project->getStatus(), (string) $project->getHoldReason());
        foreach (PipelineStep::ordered() as $step) {
            self::assertSame(ProjectTaskStatus::Succeeded, $project->getTask($step)?->getStatus(), $step->value);
        }

        // Infrastructure bought once, within the budget, costs recorded.
        self::assertSame(1, $this->em->getRepository(HostingAccount::class)->count(['project' => $project]));
        self::assertSame('atlas-cars.com', $this->em->getRepository(Domain::class)->findOneBy(['project' => $project])?->getName());
        self::assertSame(7200, $project->getSpent(CostCategory::Hosting));
        self::assertSame(1200, $project->getSpent(CostCategory::Domain));
        self::assertLessThanOrEqual($project->getBudget(), $project->getSpent());

        // Real tests and QA were executed and recorded.
        $tests = $this->em->getRepository(TestRun::class)->findOneBy(['project' => $project, 'type' => TestRunType::Automated]);
        self::assertTrue($tests?->isPassed());
        self::assertGreaterThan(15, $tests->getResults()->count());
        $qa = $this->em->getRepository(TestRun::class)->findOneBy(['project' => $project, 'type' => TestRunType::Qa]);
        self::assertTrue($qa?->isPassed());
        self::assertSame(['qa.https', 'qa.dns'], array_values(array_map(static fn ($r) => $r->getCode(), array_filter($qa->getResults()->toArray(), static fn ($r) => 'skipped' === $r->getStatus()->value))));

        // The application is really online and its administration works.
        self::assertNotNull($project->getDeploymentUrl());
        $home = (string) @file_get_contents((string) $project->getDeploymentUrl());
        self::assertStringContainsString('Atlas Cars', $home);
        self::assertTrue($project->isSimulated());

        // Delivery.
        self::assertStringContainsString((string) $project->getDeploymentUrl(), (string) $project->getDeliveryDocumentation());
        self::assertNotNull($project->getDeliveredAt());
        $types = array_map(static fn ($c) => $c->getType(), $project->getCredentials()->toArray());
        sort($types);
        self::assertSame(['app_admin', 'database', 'hosting_panel'], $types, 'Credentials stored encrypted for the customer.');
        self::assertSame(SubscriptionStatus::Active, $this->em->getRepository(Subscription::class)->findOneBy(['project' => $project])?->getStatus());
        self::assertSame(1, $this->em->getRepository(Notification::class)->count(['project' => $project, 'type' => NotificationType::ProjectDelivered]));
        self::assertSame(8, $this->em->getRepository(AgentRun::class)->count(['project' => $project]));

        // Nothing left to do: duplicated jobs are ignored.
        self::assertSame([], $this->runPipeline());
    }

    public function testSpendingAboveTheRulesWaitsForAnAdministrator(): void
    {
        $settings = static::getContainer()->get(\App\Shared\Settings\SettingsService::class);
        $settings->set('automation_policy', ['max_hosting_cost' => 50] + $settings->get('automation_policy'));
        $project = $this->approvedProject();

        self::assertSame(['provision_hosting'], $this->runPipeline());
        self::assertSame(ProjectStatus::WaitingAdminApproval, $project->getStatus());
        self::assertStringContainsString('above the automatic limit', (string) $project->getHoldReason());
        self::assertSame(0, $this->em->getRepository(HostingAccount::class)->count([]), 'Nothing was bought.');
        self::assertSame(ProjectTaskStatus::WaitingAdmin, $project->getTask(PipelineStep::Hosting)?->getStatus());

        // An agent can never approve its own spending.
        try {
            $this->as(\App\Shared\Security\Actor::agent('hosting'), fn () => static::getContainer()->get(ApprovalService::class)->approveSpending($project));
            self::fail('Agents cannot approve spending.');
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException) {
        }

        $this->as(self::adminActor($this->factory->admin('boss@example.com')), fn () => static::getContainer()->get(ApprovalService::class)->approveSpending($project));
        self::assertSame(7200, $project->getSpendingOverride(CostCategory::Hosting));
        $this->runPipeline();
        self::assertSame(ProjectStatus::Completed, $project->getStatus(), (string) $project->getHoldReason());
        self::assertSame(1, $this->em->getRepository(HostingAccount::class)->count([]));
    }

    public function testTransientFailuresAreRetriedThenHandedToAnAdministrator(): void
    {
        $provider = static::getContainer()->get(\App\Provider\ProviderRegistry::class)->findByCode('mock_domain');
        $provider?->setSettings(['simulate_failures' => ['register' => 5]] + $provider->getSettings());
        $this->em->flush();
        $project = $this->approvedProject();

        self::assertSame(['provision_hosting', 'register_domain', 'register_domain', 'register_domain'], $this->runPipeline());
        self::assertSame(ProjectStatus::WaitingAdminApproval, $project->getStatus());
        self::assertStringContainsString('register_domain failed 3 times', (string) $project->getHoldReason());
        self::assertSame(3, $this->em->getRepository(AgentRun::class)->count(['project' => $project, 'agentCode' => 'domain']));

        // The registrar is back (failure simulation removed): the administrator resumes.
        $provider?->setSettings(array_diff_key($provider->getSettings(), ['simulate_failures' => true]));
        $this->as(self::adminActor($this->factory->admin('ops@example.com')), fn () => static::getContainer()->get(ApprovalService::class)->resume($project));
        $this->runPipeline();

        self::assertSame(ProjectStatus::Completed, $project->getStatus(), (string) $project->getHoldReason());
        self::assertSame(1, $this->em->getRepository(Domain::class)->count([]), 'The domain is registered once.');
        self::assertSame(1, $this->em->getRepository(HostingAccount::class)->count([]), 'The hosting is not bought again on resume.');
        self::assertSame(1200, $project->getSpent(CostCategory::Domain));
    }

    public function testPermanentProviderErrorFailsTheProjectUntilAnAdministratorRetries(): void
    {
        $provider = static::getContainer()->get(\App\Provider\ProviderRegistry::class)->findByCode('mock_hosting');
        $provider?->setSettings(['simulate_failures' => ['create_account' => 'permanent']] + $provider->getSettings());
        $this->em->flush();
        $project = $this->approvedProject();

        self::assertSame(['provision_hosting'], $this->runPipeline());
        self::assertSame(ProjectStatus::Failed, $project->getStatus());
        self::assertSame(ProjectStatus::Provisioning, $project->getResumeStatus());

        $provider?->setSettings(array_diff_key($provider->getSettings(), ['simulate_failures' => true]));
        $this->as(self::adminActor($this->factory->admin('ops@example.com')), fn () => static::getContainer()->get(ApprovalService::class)->retry($project));
        $this->runPipeline();
        self::assertSame(ProjectStatus::Completed, $project->getStatus(), (string) $project->getHoldReason());
    }

    public function testDisabledAgentAndPausedAutomationStopTheWork(): void
    {
        $agent = $this->em->getRepository(\App\Agent\Entity\Agent::class)->findOneBy(['code' => 'hosting']);
        $agent?->setEnabled(false);
        $this->em->flush();
        $project = $this->approvedProject();

        $this->runPipeline();
        self::assertSame(ProjectStatus::WaitingAdminApproval, $project->getStatus());
        self::assertStringContainsString('disabled by an administrator', (string) $project->getHoldReason());

        $agent?->setEnabled(true);
        $admin = self::adminActor($this->factory->admin('ops@example.com'));
        $approvals = static::getContainer()->get(ApprovalService::class);
        $this->as($admin, fn () => $approvals->pause($project));
        $this->as($admin, fn () => $approvals->resume($project));
        self::assertSame([], $this->runPipeline(), 'Paused: no job runs.');
        $this->as($admin, fn () => $approvals->unpause($project));
        $this->runPipeline();
        self::assertSame(ProjectStatus::Completed, $project->getStatus(), (string) $project->getHoldReason());
    }

    public function testDuplicatedJobsNeverBuyTwice(): void
    {
        $project = $this->approvedProject();
        $bus = static::getContainer()->get(\Symfony\Component\Messenger\MessageBusInterface::class);
        $bus->dispatch(new \App\Agent\Message\ProvisionHostingMessage((int) $project->getId()));
        $bus->dispatch(new \App\Agent\Message\ProvisionHostingMessage((int) $project->getId()));

        $this->runPipeline();

        self::assertSame(ProjectStatus::Completed, $project->getStatus());
        self::assertSame(1, $this->em->getRepository(HostingAccount::class)->count([]));
        self::assertSame(1, $this->em->getRepository(AgentRun::class)->count(['project' => $project, 'agentCode' => 'hosting']), 'Duplicates are ignored, not re-run.');
    }

    public function testAdministratorSkipsANonCriticalStepButNeverAQualityGate(): void
    {
        $provider = static::getContainer()->get(\App\Provider\ProviderRegistry::class)->findByCode('mock_domain');
        $provider?->setSettings(['simulate_failures' => ['register' => 'permanent']] + $provider->getSettings());
        $this->em->flush();
        $project = $this->approvedProject();
        $this->runPipeline();
        self::assertSame(ProjectStatus::Failed, $project->getStatus());

        $approvals = static::getContainer()->get(ApprovalService::class);
        self::assertSame(PipelineStep::Domain, $approvals->blockedStep($project));
        try {
            $this->as(\App\Shared\Security\Actor::agent('domain'), fn () => $approvals->skipStep($project, 'I skip myself'));
            self::fail('Agents cannot skip steps.');
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException) {
        }

        $admin = self::adminActor($this->factory->admin('ops@example.com'));
        $this->as($admin, fn () => $approvals->skipStep($project, 'The customer configures the domain with their registrar.'));
        self::assertSame(ProjectTaskStatus::Skipped, $project->getTask(PipelineStep::Domain)?->getStatus());
        $this->runPipeline();

        self::assertSame(ProjectStatus::Completed, $project->getStatus(), (string) $project->getHoldReason());
        self::assertSame(0, $this->em->getRepository(Domain::class)->count([]), 'No domain bought.');
        self::assertSame(0, $project->getSpent(CostCategory::Domain));

        // Development, tests, deployment and QA can never be skipped.
        $provider?->setSettings(array_diff_key($provider->getSettings(), ['simulate_failures' => true]));
        $development = $this->em->getRepository(\App\Agent\Entity\Agent::class)->findOneBy(['code' => 'development']);
        $development?->setEnabled(false);
        $this->em->flush();
        $nadia = $this->factory->customer('nadia@riad.ma');
        $second = $this->payOrder($this->placeOrder($nadia, 'business', $this->issueQuote($nadia, 'Riad Nadia')))->getProject();
        $this->as($admin, fn () => $approvals->approve($second));
        $this->runPipeline();
        self::assertSame(ProjectStatus::WaitingAdminApproval, $second->getStatus());
        self::assertSame(PipelineStep::Development, $approvals->blockedStep($second));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('quality gate');
        $this->as($admin, fn () => $approvals->skipStep($second, 'Ship it without code'));
    }

    public function testProviderOverrideAndBudgetChangesAreGuarded(): void
    {
        $customer = $this->factory->customer('karim@atlas.ma');
        $project = $this->payOrder($this->placeOrder($customer, 'business'))->getProject();
        $approvals = static::getContainer()->get(ApprovalService::class);
        $admin = self::adminActor($this->factory->admin('ops@example.com'));
        $super = self::adminActor($this->factory->admin('root@example.com', true));

        // Before approval, the hosting provider can be changed (only to an enabled hosting provider).
        $this->as($admin, fn () => $approvals->overrideProvider($project, \App\Provider\Enum\ProviderType::Hosting, 'mock_hosting'));
        self::assertSame('mock_hosting', $project->getProviderOverride('hosting'));
        foreach (['mock_domain', 'does_not_exist'] as $wrong) {
            try {
                $this->as($admin, fn () => $approvals->overrideProvider($project, \App\Provider\Enum\ProviderType::Hosting, $wrong));
                self::fail('Only an enabled hosting provider can be chosen.');
            } catch (\InvalidArgumentException) {
            }
        }
        try {
            $this->as($admin, fn () => $approvals->overrideProvider($project, \App\Provider\Enum\ProviderType::Payment, 'mock_payment'));
            self::fail('The payment provider is not a per-project choice.');
        } catch (\InvalidArgumentException) {
        }

        // Budget: super administrators only, never below what was spent.
        try {
            $this->as($admin, fn () => $approvals->changeBudget($project, 999999, 'More room'));
            self::fail('Only super administrators change budgets.');
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException) {
        }
        $this->as($super, fn () => $approvals->changeBudget($project, 123400, 'Premium hosting agreed with the customer'));
        self::assertSame(123400, $project->getBudget());

        $this->as($admin, fn () => $approvals->approve($project));
        $this->runPipeline();
        self::assertSame(ProjectStatus::Completed, $project->getStatus(), (string) $project->getHoldReason());

        // Once the hosting exists, its provider can no longer be changed (never two hostings).
        $this->as($admin, fn () => $approvals->pause($project));
        $this->expectException(\InvalidArgumentException::class);
        $this->as($admin, fn () => $approvals->overrideProvider($project, \App\Provider\Enum\ProviderType::Hosting, null));
    }
}
