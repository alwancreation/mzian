<?php

declare(strict_types=1);

namespace App\Agent\Budget;

use App\Agent\Exception\NeedsAdminException;
use App\Project\Entity\Project;
use App\Project\Entity\ProjectCostEntry;
use App\Project\Enum\CostCategory;
use App\Shared\Audit\AuditLogger;
use App\Shared\I18n\MoneyFormatter;
use App\Shared\Settings\SettingsService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Every spending of an agent goes through here, BEFORE the provider is called:
 * no irreversible financial operation above the automation rules without an
 * explicit administrator approval (Project::approveSpending). Spending records
 * are idempotent: a retried operation is never counted (nor paid) twice.
 */
final readonly class BudgetGuard
{
    public function __construct(
        private SettingsService $settings,
        private EntityManagerInterface $em,
        private AuditLogger $audit,
    ) {
    }

    public function policy(): AutomationPolicy
    {
        return AutomationPolicy::fromSettings($this->settings->get('automation_policy'));
    }

    /**
     * @throws NeedsAdminException when an administrator must approve this spending first
     */
    public function authorize(Project $project, CostCategory $category, int $amount, string $description, bool $recurring = false): void
    {
        $override = $project->getSpendingOverride($category);
        if (null !== $override && $amount <= $override) {
            return; // explicitly approved by an administrator
        }
        $policy = $this->policy();
        $limit = match (true) {
            $recurring => $policy->maxMonthlyCost,
            CostCategory::Hosting === $category => $policy->maxHostingCost,
            CostCategory::Domain === $category => $policy->maxDomainCost,
            default => null,
        };
        $reason = match (true) {
            null !== $limit && $amount > $limit => \sprintf('%s costs %s, above the automatic limit of %s', $description, $this->money($amount, $project), $this->money($limit, $project)),
            $amount > $policy->requireAdminApprovalAbove => \sprintf('%s costs %s, above %s: administrator approval required', $description, $this->money($amount, $project), $this->money($policy->requireAdminApprovalAbove, $project)),
            $project->getBudget() > 0 && $project->getSpent() + $amount > $project->getBudget() => \sprintf('%s (%s) would exceed the project budget (%s spent of %s)', $description, $this->money($amount, $project), $this->money($project->getSpent(), $project), $this->money($project->getBudget(), $project)),
            default => null,
        };
        if (null !== $reason) {
            $this->audit->log('budget.blocked', $project, null, null, ['category' => $category->value, 'amount' => $amount, 'reference' => $project->getReference()]);

            throw new NeedsAdminException($reason.'.', ['spending' => ['category' => $category->value, 'amount' => $amount, 'description' => $description]]);
        }
    }

    /**
     * Records a cost once per idempotency key.
     */
    public function record(Project $project, CostCategory $category, int $amount, string $currency, string $description, ?string $reference, string $idempotencyKey, bool $simulated): ProjectCostEntry
    {
        foreach ($project->getCostEntries() as $entry) {
            if ($entry->getIdempotencyKey() === $idempotencyKey) {
                return $entry;
            }
        }
        $existing = $this->em->getRepository(ProjectCostEntry::class)->findOneBy(['idempotencyKey' => $idempotencyKey]);
        if (null !== $existing) {
            return $existing;
        }
        $entry = new ProjectCostEntry($project, $category, $amount, $currency, $description, $reference, $idempotencyKey, $simulated);
        $this->em->persist($entry);

        return $entry;
    }

    private function money(int $amount, Project $project): string
    {
        return MoneyFormatter::format($amount, $project->getCurrency(), 'en');
    }
}
