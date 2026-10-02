<?php

declare(strict_types=1);

namespace App\Hosting;

use App\Hosting\Entity\HostingPlan;
use App\Hosting\Repository\HostingPlanRepository;
use App\Project\Entity\Project;
use App\Provider\Entity\Provider;
use App\Provider\Enum\ProviderType;
use App\Provider\ProviderRegistry;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Picks the cheapest enabled plan of the hosting provider in charge that satisfies
 * the solution's requirements (storage, database, e-mail accounts).
 */
final class HostingPlanSelector implements ResetInterface
{
    /** @var array<int, list<HostingPlan>> */
    private array $plans = [];

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly HostingPlanRepository $repository,
    ) {
    }

    public function provider(?Project $project = null): Provider
    {
        return $this->providers->resolve(ProviderType::Hosting, $project);
    }

    /**
     * @param array<string, mixed> $requirements
     *
     * @throws \App\Provider\Exception\ProviderNotConfiguredException
     */
    public function select(array $requirements, ?Project $project = null): ?HostingPlan
    {
        foreach ($this->plansOf($this->provider($project)) as $plan) {
            if ($plan->satisfies($requirements)) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * Enabled plans of a provider, cheapest first.
     *
     * @return list<HostingPlan>
     */
    public function plansOf(Provider $provider): array
    {
        $id = (int) $provider->getId();
        if (!isset($this->plans[$id])) {
            $plans = $this->repository->findBy(['provider' => $provider, 'enabled' => true]);
            usort($plans, static fn (HostingPlan $a, HostingPlan $b) => $a->getYearlyPrice() <=> $b->getYearlyPrice());
            $this->plans[$id] = $plans;
        }

        return $this->plans[$id];
    }

    public function reset(): void
    {
        $this->plans = [];
    }
}
