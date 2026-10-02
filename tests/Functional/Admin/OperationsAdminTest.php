<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Agent\AgentPermission;
use App\Agent\Entity\Agent;
use App\Agent\Entity\AgentRun;
use App\Agent\Enum\AgentRunStatus;
use App\Project\Entity\Project;
use App\Project\Entity\ProjectTask;
use App\Project\Enum\PipelineStep;
use App\Project\Enum\ProjectStatus;
use App\Project\Workflow\ProjectStateMachine;
use App\Provider\Entity\Provider;
use App\Provider\Entity\ProviderCredential;
use App\Security\SecretManagerInterface;
use App\Shared\Entity\AuditLog;
use App\Shared\Security\Actor;
use App\Shared\Settings\SettingsService;
use App\Testing\Entity\TestResult;
use App\Testing\Entity\TestRun;
use App\Testing\Enum\TestRunType;
use App\Testing\Enum\TestSeverity;
use App\Testing\Enum\TestStatus;
use App\Tests\Support\CommerceFixtureTrait;
use App\Tests\Support\PlatformFixtureTrait;
use App\Tests\Support\WebTestCaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Admin screens used to operate the platform: agents, providers (write-only
 * secrets), settings, leads, AI recommendations and the project controls.
 */
final class OperationsAdminTest extends WebTestCase
{
    use CommerceFixtureTrait;
    use PlatformFixtureTrait;
    use WebTestCaseTrait;

    private const SECRET = 'dummy-value-THIS-MUST-NEVER-BE-DISPLAYED-42';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->setUpPlatform();
    }

    public function testAgentsAreConfiguredBySuperAdminsOnly(): void
    {
        $agent = $this->em()->getRepository(Agent::class)->findOneBy(['code' => 'hosting']) ?? throw new \LogicException();
        $project = $this->factory()->project();
        $task = new ProjectTask($project, PipelineStep::Hosting);
        $run = new AgentRun('hosting', $project, $task);
        $run->log('info', 'Hosting account created');
        $run->finish(AgentRunStatus::Succeeded);
        $testRun = new TestRun($project, TestRunType::Qa, 'http://apps:8081/atlas/');
        $testRun->addResult(new TestResult($testRun, 'qa.availability', 'Website online', 'availability', TestSeverity::Critical, TestStatus::Passed, 'The home page answers 200.'));
        $testRun->finish();
        foreach ([$task, $run, $testRun] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        $this->loginAs($this->factory()->admin());
        $crawler = $this->client->request('GET', '/admin/agents');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Hosting');
        $form = $crawler->filter('form[action="/admin/agents/'.$agent->getId().'"]')->form();
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/admin/agent-runs/'.$run->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Hosting account created');
        $this->client->request('GET', '/admin/test-runs/'.$testRun->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Website online');

        $this->loginAs($this->factory()->admin('root@example.com', true));
        $crawler = $this->client->request('GET', '/admin/agents');
        $form = $crawler->filter('form[action="/admin/agents/'.$agent->getId().'"]')->form();
        $values = $form->getPhpValues();
        $values['permissions'] = [AgentPermission::HOSTING_PROVISION, 'project.approve', 'budget.spend'];
        $values['max_attempts'] = '5';
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects('/admin/agents');

        $this->em()->clear();
        $agent = $this->em()->getRepository(Agent::class)->findOneBy(['code' => 'hosting']) ?? throw new \LogicException();
        self::assertSame(5, $agent->getMaxAttempts());
        self::assertTrue($agent->isAllowed(AgentPermission::HOSTING_PROVISION));
        self::assertFalse($agent->isAllowed('project.approve'), 'Human-only decisions can never be granted to an agent.');
    }

    public function testProviderSecretsAreWriteOnly(): void
    {
        $this->loginAs($this->factory()->admin());
        $this->client->request('GET', '/admin/providers');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="provider-mock_hosting"]');
        self::assertSelectorTextContains('[data-testid="credentials-openai"]', 'api_key');
        $openai = $this->em()->getRepository(Provider::class)->findOneBy(['code' => 'openai']) ?? throw new \LogicException();
        $this->client->request('POST', '/admin/providers/'.$openai->getId().'/credentials', ['name' => 'api_key', 'value' => self::SECRET]);
        self::assertResponseStatusCodeSame(403, 'Simple administrators cannot change providers.');

        $this->loginAs($this->factory()->admin('root@example.com', true));
        $crawler = $this->client->request('GET', '/admin/providers');
        $card = $crawler->filter('[data-testid="provider-openai"]');
        $this->client->submit($card->selectButton('🔒 Encrypt & store')->form(['name' => 'api_key', 'value' => self::SECRET]));
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-testid="credentials-openai"]', 'stored encrypted');
        self::assertStringNotContainsString(self::SECRET, (string) $this->client->getResponse()->getContent());

        $credential = $this->em()->getRepository(ProviderCredential::class)->findOneBy(['name' => 'api_key']) ?? throw new \LogicException();
        self::assertNotSame(self::SECRET, $credential->getEncryptedValue());
        self::assertSame(self::SECRET, static::getContainer()->get(SecretManagerInterface::class)->decrypt($credential->getEncryptedValue()));
        foreach ($this->em()->getRepository(AuditLog::class)->findAll() as $log) {
            self::assertStringNotContainsString(self::SECRET, (string) json_encode([$log->getOldValue(), $log->getNewValue(), $log->getMetadata(), $log->getMessage()]));
        }

        // A secret pasted in the clear settings is refused and not echoed back.
        $crawler = $this->client->request('GET', '/admin/providers');
        $card = $crawler->filter('[data-testid="provider-openai"]');
        $this->client->submit($card->selectButton('Save settings')->form(['settings' => '{"base_url": "https://api.openai.com/v1", "api_key": "'.self::SECRET.'"}']));
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'looks like a secret');
        self::assertStringNotContainsString(self::SECRET, (string) $this->client->getResponse()->getContent());
        $this->em()->clear();
        self::assertArrayNotHasKey('api_key', $this->em()->getRepository(Provider::class)->findOneBy(['code' => 'openai'])?->getSettings() ?? []);

        // Valid settings are saved; "default" is exclusive within a type.
        $crawler = $this->client->request('GET', '/admin/providers');
        $card = $crawler->filter('[data-testid="provider-local_git"]');
        $this->client->submit($card->selectButton('Save settings')->form(['settings' => '{"branch": "main", "author": "Mzian Bot"}']));
        $github = $this->em()->getRepository(Provider::class)->findOneBy(['code' => 'github']) ?? throw new \LogicException();
        $this->client->request('GET', '/admin/providers');
        $crawler = $this->client->getCrawler();
        $form = $crawler->filter('form[action="/admin/providers/'.$github->getId().'"]')->form();
        $this->client->submit($form, ['enabled' => '1', 'default' => '1']);
        $this->em()->clear();
        $repository = $this->em()->getRepository(Provider::class);
        self::assertSame(['branch' => 'main', 'author' => 'Mzian Bot'], $repository->findOneBy(['code' => 'local_git'])?->getSettings());
        self::assertTrue($repository->findOneBy(['code' => 'github'])?->isDefault());
        self::assertFalse($repository->findOneBy(['code' => 'local_git'])?->isDefault());
    }

    public function testAutomationPolicyIsEditedBySuperAdminsOnly(): void
    {
        $this->loginAs($this->factory()->admin());
        $crawler = $this->client->request('GET', '/admin/settings');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="automation-policy"]', 'WAITING_ADMIN_APPROVAL');
        $this->client->request('POST', '/admin/settings', ['automation_policy' => ['max_hosting_cost' => '1000']]);
        self::assertResponseStatusCodeSame(403);

        $this->loginAs($this->factory()->admin('root@example.com', true));
        $crawler = $this->client->request('GET', '/admin/settings');
        $this->client->submit($crawler->selectButton('Save automation policy')->form([
            'automation_policy[max_hosting_cost]' => '60',
            'automation_policy[max_domain_cost]' => '20',
            'automation_policy[max_monthly_cost]' => '25',
            'automation_policy[require_admin_approval_above]' => '80',
            'automation_policy[qa_min_score]' => '85',
        ]));
        self::assertResponseRedirects('/admin/settings');
        $settings = static::getContainer()->get(SettingsService::class);
        $settings->reset();
        $policy = $settings->get('automation_policy');
        self::assertEquals(60, $policy['max_hosting_cost']);
        self::assertSame(85, $policy['qa_min_score']);

        $crawler = $this->client->request('GET', '/admin/settings');
        $this->client->submit($crawler->selectButton('Save automation policy')->form(['automation_policy[qa_min_score]' => '150']));
        self::assertResponseStatusCodeSame(422);
    }

    public function testLeadsAndAiRecommendations(): void
    {
        $quote = $this->issueQuote($this->factory()->customer('karim@atlas.ma'));
        $project = $quote->getProject();
        $this->loginAs($this->factory()->admin());

        $this->client->request('GET', '/admin/ai-recommendations');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="ai-recommendations"]', $project->getReference());
        self::assertSelectorTextContains('[data-testid="ai-stats"]', 'Analyses');

        $lead = $this->factory()->lead('prospect@riad.ma');
        $crawler = $this->client->request('GET', '/admin/leads/'.$lead->getId());
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Add note')->form(['note' => 'Called, wants a booking site', 'lost' => true]));
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-testid="lead-activity"]', 'Called, wants a booking site');
        self::assertSelectorTextContains('h1', 'lost');
    }

    public function testProjectControls(): void
    {
        $project = $this->payOrder($this->placeOrder($this->factory()->customer('karim@atlas.ma')))->getProject();
        $admin = $this->factory()->admin();
        $this->loginAs($admin);

        // Provider override before approval, then the automation budget (super admin only).
        $crawler = $this->client->request('GET', '/admin/projects/'.$project->getId());
        self::assertSelectorExists('[data-testid="project-providers"]');
        $this->client->submit($crawler->filter('[data-testid="project-providers"] form')->first()->form(['provider' => 'mock_hosting']));
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'now uses mock_hosting');
        $this->client->request('POST', '/admin/projects/'.$project->getId().'/decision/budget', ['_token' => $crawler->filter('input[name="_token"]')->attr('value'), 'budget' => '5000', 'message' => 'more']);
        self::assertResponseStatusCodeSame(403);

        // An agent put the project on hold at the domain step: the admin skips it.
        $reloaded = $this->em()->getRepository(Project::class)->find($project->getId()) ?? throw new \LogicException();
        $machine = static::getContainer()->get(ProjectStateMachine::class);
        $this->as(self::adminActor($admin), fn () => $machine->apply($reloaded, 'approve'));
        $this->as(Actor::agent('hosting'), function () use ($machine, $reloaded): void {
            $machine->apply($reloaded, 'start_provisioning');
            $machine->apply($reloaded, 'hosting_ready');
        });
        $this->as(Actor::agent('domain'), fn () => $machine->hold($reloaded, 'The domain name must be chosen with the customer before going on.'));

        $crawler = $this->client->request('GET', '/admin/projects/'.$project->getId());
        self::assertSelectorTextContains('main', 'Skip the “domain” step');
        $this->client->submit($crawler->selectButton('⏭️ Skip step')->form(['message' => 'The customer keeps their own domain for now.']));
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-testid="project-status"]', 'DOMAIN_READY');
        self::assertSelectorTextContains('[data-testid="pipeline-tasks"]', 'skipped');
        self::assertSelectorTextContains('[data-testid="timeline"]', 'Step "domain" skipped');
        self::assertSame(ProjectStatus::DomainReady, $this->em()->getRepository(Project::class)->find($project->getId())?->getStatus());
    }
}
