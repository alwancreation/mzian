<?php

declare(strict_types=1);

namespace App\Agent\Agents;

use App\Agent\AgentContext;
use App\Agent\AgentInterface;
use App\Agent\AgentPermission;
use App\Agent\AgentResult;
use App\Agent\Budget\BudgetGuard;
use App\Agent\Exception\AgentException;
use App\Agent\Exception\NeedsAdminException;
use App\Delivery\CredentialService;
use App\Hosting\Entity\HostingAccount;
use App\Hosting\Entity\HostingDeployment;
use App\Hosting\HostingPlanSelector;
use App\Hosting\Provider\HostingProviderInterface;
use App\Project\Enum\CostCategory;
use App\Project\Enum\PipelineStep;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/**
 * Hosting Agent: chooses the plan of the quote (or the cheapest suitable one of
 * the provider an administrator selected), checks the budget, creates the
 * account and the website space, stores the credentials encrypted.
 */
final readonly class HostingAgent implements AgentInterface
{
    public function __construct(
        private HostingPlanSelector $plans,
        #[AutowireLocator('mzian.hosting_provider', defaultIndexMethod: 'getDriver')]
        private ContainerInterface $drivers,
        private BudgetGuard $budget,
        private CredentialService $credentials,
        private EntityManagerInterface $em,
    ) {
    }

    public static function getCode(): string
    {
        return 'hosting';
    }

    public static function step(): PipelineStep
    {
        return PipelineStep::Hosting;
    }

    public static function requiredPermissions(string $operation): array
    {
        return [AgentPermission::HOSTING_PROVISION, AgentPermission::BUDGET_SPEND, AgentPermission::CREDENTIALS_STORE];
    }

    public function execute(AgentContext $context): AgentResult
    {
        $project = $context->project;
        $provider = $this->plans->provider($project);
        if (!$this->drivers->has($provider->getDriver())) {
            throw AgentException::permanent(\sprintf('No hosting driver "%s" installed.', $provider->getDriver()));
        }
        /** @var HostingProviderInterface $driver */
        $driver = $this->drivers->get($provider->getDriver());

        $quoted = $project->getCurrentQuote()?->getHostingPlan();
        $requirements = $project->getSolution()?->getHostingRequirements() ?? [];
        $plan = null !== $quoted && $quoted->getProvider() === $provider && $quoted->isEnabled() ? $quoted : $this->plans->select($requirements, $project);
        if (null === $plan) {
            throw new NeedsAdminException(\sprintf('No plan of %s satisfies the requirements: choose a plan or another hosting provider.', $provider->getName()));
        }
        $context->log('Hosting plan selected', ['provider' => $provider->getCode(), 'plan' => $plan->getCode(), 'yearly_cost' => $plan->getYearlyPrice()]);

        $accountKey = $context->idempotencyKey('hosting-account');
        $account = $this->em->getRepository(HostingAccount::class)->findOneBy(['idempotencyKey' => $accountKey]);
        if (null === $account) {
            $this->budget->authorize($project, CostCategory::Hosting, $plan->getYearlyPrice(), 'Hosting plan '.$plan->getName().' (1 year)');
            $provisioning = $driver->provisionAccount($provider, $plan, $project, $accountKey);
            $account = new HostingAccount($project, $provider->getCode(), $plan, $provisioning->externalId, $provisioning->cost, $provisioning->currency, $accountKey, $provisioning->simulated);
            $account->activate($provisioning->username, $provisioning->region, $provisioning->ipAddress, $provisioning->controlPanelUrl, $provisioning->expiresAt);
            $this->em->persist($account);
            if (null !== $provisioning->password()) {
                $this->credentials->store($project, 'hosting_panel', 'Hosting control panel', $provisioning->username, (string) $provisioning->password(), $provisioning->controlPanelUrl);
            }
            $this->budget->record($project, CostCategory::Hosting, $provisioning->cost, $provisioning->currency, 'Hosting '.$plan->getName().' (1 year)', $provisioning->externalId, $accountKey, $provisioning->simulated, $context->task);
            $this->em->flush();
            $context->log('Hosting account created', ['external_id' => $provisioning->externalId, 'simulated' => $provisioning->simulated]);
        } else {
            $context->log('Hosting account already exists (idempotent)', ['external_id' => $account->getExternalId()]);
        }

        $siteKey = $context->idempotencyKey('hosting-site');
        $domain = $project->getDomainName() ?? $project->getSlug().'.mzian.app';
        $site = $this->em->getRepository(HostingDeployment::class)->findOneBy(['idempotencyKey' => $siteKey]);
        if (null === $site) {
            $created = $driver->createSite($provider, $account, $domain, $requirements, $siteKey);
            $site = new HostingDeployment($account, $created->externalId, $created->documentRoot, $created->phpVersion, $created->databaseName, $created->sslEnabled, $siteKey);
            $this->em->persist($site);
            if (null !== $created->databasePassword() && null !== $created->databaseUser) {
                $this->credentials->store($project, 'database', 'Database', $created->databaseUser, (string) $created->databasePassword());
            }
            $this->em->flush();
        }

        return new AgentResult(\sprintf('Hosting ready on %s (%s).', $provider->getName(), $plan->getName()), [
            'hosting_provider' => $provider->getCode(),
            'hosting_account' => $account->getExternalId(),
            'hosting_plan' => $plan->getCode(),
            'ip_address' => $account->getIpAddress(),
            'document_root' => $site->getDocumentRoot(),
            'simulated' => $account->isSimulated(),
        ]);
    }
}
