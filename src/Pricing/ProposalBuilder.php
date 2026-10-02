<?php

declare(strict_types=1);

namespace App\Pricing;

use App\AI\Analysis\RequirementAnalysis;
use App\Billing\Entity\SubscriptionPlan;
use App\Billing\Repository\SubscriptionPlanRepository;
use App\Catalog\Entity\Solution;
use App\Catalog\Service\CatalogProvider;
use App\Domain\DomainService;
use App\Hosting\Entity\HostingPlan;
use App\Hosting\HostingPlanSelector;
use App\Project\Entity\Project;
use App\Provider\Exception\ProviderException;
use App\Requirement\Entity\Requirement;
use App\Requirement\Service\RequirementService;
use App\Shared\Settings\SettingsService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns an analysed requirement into a priced proposal:
 *   catalog (base price, optional features) + hosting plan + domain + e-mail
 *   + subscription → PricingEngine (costs, margin rules) → Proposal.
 *
 * The AI only chooses the solution and the features; every amount comes from the
 * catalog, the providers and the pricing policy.
 */
final readonly class ProposalBuilder
{
    public function __construct(
        private PricingEngine $engine,
        private SettingsService $settings,
        private CatalogProvider $catalog,
        private HostingPlanSelector $hostingPlans,
        private DomainService $domains,
        private SubscriptionPlanRepository $subscriptionPlans,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
    ) {
    }

    public function policy(): PricingPolicy
    {
        return PricingPolicy::fromSettings($this->settings->get('pricing'));
    }

    /**
     * @throws \DomainException when the recommended solution is no longer in the catalog
     */
    public function build(Requirement $requirement, RequirementAnalysis $analysis, ?string $subscriptionCode = null, ?Project $project = null): Proposal
    {
        $solution = $this->catalog->solution($analysis->solution)
            ?? throw new \DomainException(\sprintf('Solution "%s" is not available in the catalog.', $analysis->solution));
        $locale = $requirement->getLocale();
        $policy = $this->policy();
        $warnings = [];

        $options = [];
        foreach ($analysis->features as $code) {
            $feature = $solution->getFeature($code);
            if (null !== $feature && $feature->isEnabled() && !$feature->isIncluded() && $feature->getPrice() > 0) {
                $options[] = ['code' => $code, 'label' => $feature->getName($locale), 'price' => $feature->getPrice()];
            }
        }
        $custom = array_map(static fn (string $code) => ['code' => $code, 'label' => ucfirst(str_replace('_', ' ', $code))], $analysis->unsupportedFeatures);
        if ([] !== $custom) {
            $warnings[] = 'pricing.warning.custom_features';
        }

        $plan = $this->hostingPlan($analysis->hostingRequirements, $project, $warnings);
        $domain = $this->domainDecision($requirement, $solution, $analysis, $project, $warnings);
        $subscription = $this->subscriptionFor($solution, $subscriptionCode);

        $requestedEmails = (int) $analysis->hostingRequirements['email_accounts'];
        $extraEmails = max(0, $requestedEmails - (int) ($plan?->getSpecs()['email_accounts'] ?? 0));

        $request = new PricingRequest(
            $solution->getCode(),
            $solution->getName($locale),
            $solution->getBasePrice(),
            $analysis->complexity,
            $options,
            $custom,
            $this->hostingLine($plan, $locale),
            $domain['price'] > 0 ? ['name' => (string) $domain['name'], 'label' => $this->domainLabel($domain['name'], $locale), 'price' => $domain['price']] : null,
            $extraEmails,
            $this->translator->trans('pricing.line.email', ['%count%' => $extraEmails], 'messages', $locale),
            $this->subscriptionLine($subscription, $locale),
            $this->translator->trans('pricing.line.custom', [], 'messages', $locale),
        );

        return new Proposal(
            $solution,
            $analysis,
            $policy,
            $this->engine->calculate($request, $policy),
            $plan,
            $domain['mode'],
            $domain['name'],
            $domain['requested'],
            $subscription,
            array_values(array_unique($warnings)),
        );
    }

    /**
     * "Starting at" price of a solution: base price, low complexity, included
     * features only, cheapest suitable hosting and a domain in the first preferred TLD.
     */
    public function startingPrice(Solution $solution, string $locale = 'en'): PriceBreakdown
    {
        $warnings = [];
        $plan = $this->hostingPlan($solution->getHostingRequirements(), null, $warnings);
        $domain = null;
        if ($solution->isDomainRequired()) {
            $tld = (string) ($solution->getDomainRequirements()['preferred_tlds'][0] ?? '.com');
            $price = $this->domains->estimate($tld);
            if (null !== $price) {
                $domain = ['name' => 'example'.$tld, 'label' => $tld, 'price' => $price];
            }
        }
        $extraEmails = max(0, (int) ($solution->getHostingRequirements()['email_accounts'] ?? 0) - (int) ($plan?->getSpecs()['email_accounts'] ?? 0));

        return $this->engine->calculate(new PricingRequest(
            $solution->getCode(),
            $solution->getName($locale),
            $solution->getBasePrice(),
            'low',
            hosting: $this->hostingLine($plan, $locale),
            domain: $domain,
            emailAccounts: $extraEmails,
        ), $this->policy());
    }

    /**
     * @param list<Solution> $solutions
     *
     * @return array<string, int> solution code => starting selling price (minor units)
     */
    public function startingPrices(array $solutions, string $locale = 'en'): array
    {
        $prices = [];
        foreach ($solutions as $solution) {
            $prices[$solution->getCode()] = $this->startingPrice($solution, $locale)->sellingPrice;
        }

        return $prices;
    }

    /**
     * Default recurring plan: the cheapest one covering the solution's maintenance cost.
     */
    public function subscriptionFor(Solution $solution, ?string $code = null): ?SubscriptionPlan
    {
        $plans = $this->subscriptionPlans->findBy(['enabled' => true], ['position' => 'ASC']);
        usort($plans, static fn (SubscriptionPlan $a, SubscriptionPlan $b) => $a->getMonthlyPrice() <=> $b->getMonthlyPrice());
        if (null !== $code) {
            foreach ($plans as $plan) {
                if ($plan->getCode() === $code) {
                    return $plan;
                }
            }

            return null;
        }
        foreach ($plans as $plan) {
            if ($plan->getMonthlyPrice() >= $solution->getMaintenancePrice()) {
                return $plan;
            }
        }

        return [] !== $plans ? $plans[\count($plans) - 1] : null;
    }

    /**
     * @param array<string, mixed> $requirements
     * @param list<string>         $warnings
     */
    private function hostingPlan(array $requirements, ?Project $project, array &$warnings): ?HostingPlan
    {
        try {
            $plan = $this->hostingPlans->select($requirements, $project);
        } catch (ProviderException $e) {
            $this->logger->warning('Hosting provider unavailable while pricing', ['error' => $e->getMessage()]);
            $plan = null;
        }
        if (null === $plan) {
            $warnings[] = 'pricing.warning.hosting_plan';
            $this->logger->warning('No hosting plan satisfies the requirements', ['requirements' => $requirements]);
        }

        return $plan;
    }

    /**
     * @return array{code: string, label: string, price: int}|null
     */
    private function hostingLine(?HostingPlan $plan, string $locale): ?array
    {
        if (null === $plan) {
            return null;
        }

        return [
            'code' => $plan->getCode(),
            'label' => $this->translator->trans('pricing.line.hosting', ['%plan%' => $plan->getName()], 'messages', $locale),
            'price' => $plan->getYearlyPrice(),
        ];
    }

    /**
     * @return array{code: string, label: string, monthly: int}|null
     */
    private function subscriptionLine(?SubscriptionPlan $plan, string $locale): ?array
    {
        if (null === $plan) {
            return null;
        }

        return [
            'code' => $plan->getCode(),
            'label' => $this->translator->trans('pricing.line.subscription', ['%plan%' => $plan->getName($locale)], 'messages', $locale),
            'monthly' => $plan->getMonthlyPrice(),
        ];
    }

    private function domainLabel(?string $domain, string $locale): string
    {
        return null === $domain
            ? $this->translator->trans('pricing.line.domain_to_choose', [], 'messages', $locale)
            : $this->translator->trans('pricing.line.domain', ['%domain%' => $domain], 'messages', $locale);
    }

    /**
     * Which domain the project will use and what it costs:
     *  - the customer already owns one → no cost (DNS configured later);
     *  - a desired name is available → registered for one year;
     *  - otherwise the first available variant of the business name;
     *  - solutions that don't need a domain run on a Mzian sub-domain.
     *
     * @param list<string> $warnings
     *
     * @return array{mode: string, name: ?string, requested: ?string, price: int}
     */
    private function domainDecision(Requirement $requirement, Solution $solution, RequirementAnalysis $analysis, ?Project $project, array &$warnings): array
    {
        $owned = $requirement->getItem('domain_name')?->getValue();
        if ('yes' === ($requirement->getAnswers()['has_domain'] ?? null)) {
            return ['mode' => Proposal::DOMAIN_OWN, 'name' => \is_string($owned) && RequirementService::isValidDomain($owned) ? $owned : null, 'requested' => null, 'price' => 0];
        }

        $desired = $requirement->getItem('desired_domain')?->getValue();
        $desired = \is_string($desired) && RequirementService::isValidDomain($desired) ? $desired : null;
        if (null === $desired && !$analysis->domainRequired && !$solution->isDomainRequired()) {
            return ['mode' => Proposal::DOMAIN_NONE, 'name' => null, 'requested' => null, 'price' => 0];
        }

        $tlds = array_values(array_map('strval', (array) ($solution->getDomainRequirements()['preferred_tlds'] ?? ['.com'])));
        try {
            if (null !== $desired) {
                $availability = $this->domains->check($desired, $project);
                if ($availability->available) {
                    return ['mode' => Proposal::DOMAIN_REGISTER, 'name' => $availability->domain, 'requested' => $desired, 'price' => $availability->price];
                }
                $warnings[] = 'pricing.warning.domain_taken';
            }
            $suggestion = $this->domains->suggest(null, $requirement->getBusinessName(), $requirement->getCity(), $tlds);
            if (null !== $suggestion) {
                return ['mode' => Proposal::DOMAIN_REGISTER, 'name' => $suggestion->domain, 'requested' => $desired, 'price' => $suggestion->price];
            }
        } catch (ProviderException $e) {
            $this->logger->warning('Domain check failed while building a proposal', ['error' => $e->getMessage()]);
        }

        // No available name could be confirmed: price an estimate, the name is chosen with the customer.
        $warnings[] = 'pricing.warning.domain_to_choose';

        return ['mode' => Proposal::DOMAIN_TO_CHOOSE, 'name' => null, 'requested' => $desired, 'price' => (int) $this->domains->estimate($tlds[0] ?? '.com', $project)];
    }
}
