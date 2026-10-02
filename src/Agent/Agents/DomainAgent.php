<?php

declare(strict_types=1);

namespace App\Agent\Agents;

use App\Agent\AgentContext;
use App\Agent\AgentInterface;
use App\Agent\AgentPermission;
use App\Agent\AgentResult;
use App\Agent\Budget\BudgetGuard;
use App\Agent\Exception\NeedsAdminException;
use App\Domain\DomainService;
use App\Domain\Entity\Domain;
use App\Pricing\Proposal;
use App\Project\Enum\CostCategory;
use App\Project\Enum\PipelineStep;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Domain Agent: registers the domain of the quote (never twice: the domain name
 * is unique and the registration idempotent), points it to the hosting, or
 * records the DNS instructions when the customer already owns a domain.
 */
final readonly class DomainAgent implements AgentInterface
{
    public function __construct(
        private DomainService $domains,
        private BudgetGuard $budget,
        private EntityManagerInterface $em,
    ) {
    }

    public static function getCode(): string
    {
        return 'domain';
    }

    public static function step(): PipelineStep
    {
        return PipelineStep::Domain;
    }

    public static function requiredPermissions(string $operation): array
    {
        return [AgentPermission::DOMAIN_REGISTER, AgentPermission::DOMAIN_DNS, AgentPermission::BUDGET_SPEND];
    }

    public function execute(AgentContext $context): AgentResult
    {
        $project = $context->project;
        $snapshot = (array) ($project->getCurrentQuote()?->getPricingSnapshot()['domain'] ?? []);
        $mode = (string) ($snapshot['mode'] ?? (null !== $project->getDomainName() ? Proposal::DOMAIN_REGISTER : Proposal::DOMAIN_NONE));
        $name = $project->getDomainName();
        $ip = (string) ($context->outputOf(PipelineStep::Hosting)['ip_address'] ?? '');
        $records = [['type' => 'A', 'name' => '@', 'value' => $ip, 'ttl' => 3600], ['type' => 'CNAME', 'name' => 'www', 'value' => '@', 'ttl' => 3600]];

        if (Proposal::DOMAIN_NONE === $mode || null === $name) {
            if (Proposal::DOMAIN_TO_CHOOSE === $mode) {
                throw new NeedsAdminException('The domain name must be chosen with the customer before going on.');
            }

            return new AgentResult('No custom domain: the application uses its Mzian address.', ['domain' => null, 'mode' => Proposal::DOMAIN_NONE]);
        }
        if ('' === $ip) {
            throw new NeedsAdminException('The hosting address is unknown (the hosting step was skipped): configure the domain manually, then skip this step.');
        }

        $provider = $this->domains->provider($project);
        $existing = $this->em->getRepository(Domain::class)->findOneBy(['name' => $name]);
        if (null !== $existing && $existing->getProject() !== $project) {
            throw new NeedsAdminException(\sprintf('%s is already used by another project.', $name));
        }

        if (Proposal::DOMAIN_OWN === $mode) {
            $domain = $existing ?? new Domain($project, $name, 'customer', $project->getCurrency(), false);
            $domain->markExternal();
            $domain->recordDnsInstructions($records);
            $this->em->persist($domain);
            $context->log('Customer-owned domain: DNS records to set at their registrar', ['domain' => $name]);

            return new AgentResult(\sprintf('%s belongs to the customer: DNS instructions recorded.', $name), ['domain' => $name, 'mode' => $mode, 'dns_records' => $records, 'dns_by_customer' => true]);
        }

        $driver = $this->domains->driver($provider);
        $key = $context->idempotencyKey('domain-registration');
        $domain = $existing;
        if (null === $domain || !\in_array($domain->getStatus()->value, ['registered', 'active'], true)) {
            $availability = $driver->checkAvailability($provider, $name);
            if (!$availability->available && null === $existing) {
                throw new NeedsAdminException(\sprintf('%s is no longer available: choose another name with the customer.', $name));
            }
            $this->budget->authorize($project, CostCategory::Domain, $availability->price, 'Domain '.$name);
            $registration = $driver->register($provider, $name, 1, $key);
            $domain ??= new Domain($project, $name, $provider->getCode(), $registration->currency, $registration->simulated);
            $domain->markRegistered($registration->externalId, $registration->cost, $registration->expiresAt, $registration->nameservers);
            $this->em->persist($domain);
            $this->budget->record($project, CostCategory::Domain, $registration->cost, $registration->currency, 'Domain '.$name.' (1 year)', $registration->externalId, $key, $registration->simulated, $context->task);
            $this->em->flush();
            $context->log('Domain registered', ['domain' => $name, 'simulated' => $registration->simulated]);
        }
        $driver->configureDns($provider, $name, $records, $context->idempotencyKey('dns'));
        $domain->configureDns($records);
        $this->em->flush();

        return new AgentResult(\sprintf('%s registered and pointed to the hosting.', $name), ['domain' => $name, 'mode' => $mode, 'nameservers' => $domain->getNameservers(), 'dns_records' => $records, 'simulated' => $domain->isSimulated()]);
    }
}
