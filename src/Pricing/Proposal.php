<?php

declare(strict_types=1);

namespace App\Pricing;

use App\AI\Analysis\RequirementAnalysis;
use App\Billing\Entity\SubscriptionPlan;
use App\Catalog\Entity\Solution;
use App\Hosting\Entity\HostingPlan;

/**
 * Commercial proposal for a requirement: recommended solution, chosen hosting
 * plan, domain decision, recurring plan and the computed price.
 */
final readonly class Proposal
{
    public const DOMAIN_OWN = 'own';
    public const DOMAIN_REGISTER = 'register';
    public const DOMAIN_TO_CHOOSE = 'to_choose';
    public const DOMAIN_NONE = 'none';

    /**
     * @param list<string> $warnings translation keys (pricing.warning.*) shown to the customer and the admin
     */
    public function __construct(
        public Solution $solution,
        public RequirementAnalysis $analysis,
        public PricingPolicy $policy,
        public PriceBreakdown $breakdown,
        public ?HostingPlan $hostingPlan,
        public string $domainMode,
        public ?string $domain,
        public ?string $requestedDomain,
        public ?SubscriptionPlan $subscriptionPlan,
        public array $warnings = [],
    ) {
    }

    public function getBudget(): int
    {
        return PricingEngine::budgetFor($this->breakdown, $this->policy);
    }

    public function getValidUntil(\DateTimeImmutable $from = new \DateTimeImmutable()): \DateTimeImmutable
    {
        return $from->modify(\sprintf('+%d days', $this->policy->quoteValidityDays));
    }

    /**
     * Whether the requested domain was taken and another one is proposed.
     */
    public function isAlternativeDomain(): bool
    {
        return null !== $this->requestedDomain && $this->requestedDomain !== $this->domain;
    }
}
