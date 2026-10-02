<?php

declare(strict_types=1);

namespace App\Order\Service;

use App\AI\Analysis\RequirementAnalysis;
use App\Lead\Entity\LeadActivity;
use App\Lead\Enum\LeadStatus;
use App\Lead\Service\LeadService;
use App\Order\Entity\Quote;
use App\Order\Entity\QuoteItem;
use App\Pricing\Proposal;
use App\Pricing\ProposalBuilder;
use App\Project\Entity\Project;
use App\Project\Enum\ProjectStatus;
use App\Project\Repository\ProjectRepository;
use App\Project\Workflow\ProjectStateMachine;
use App\Requirement\Entity\Requirement;
use App\Shared\Reference\ReferenceGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Issues the quote of an analysed requirement and keeps its project in sync:
 * DRAFT/QUOTED → ANALYZING → QUOTED. The price is frozen in the quote (items +
 * pricing snapshot) for its validity period, whatever changes in the catalog.
 */
final readonly class QuoteService
{
    public function __construct(
        private ProposalBuilder $proposals,
        private ProjectStateMachine $stateMachine,
        private ProjectRepository $projects,
        private ReferenceGenerator $references,
        private LeadService $leads,
        private EntityManagerInterface $em,
    ) {
    }

    public function projectFor(Requirement $requirement): ?Project
    {
        return $this->projects->findOneBy(['requirement' => $requirement]);
    }

    /**
     * Whether the requirement can still be (re)analysed and (re)quoted.
     */
    public function isOpen(Requirement $requirement): bool
    {
        $project = $this->projectFor($requirement);

        return null === $project || \in_array($project->getStatus(), [ProjectStatus::Draft, ProjectStatus::Analyzing, ProjectStatus::Quoted], true);
    }

    /**
     * @throws \DomainException when the request is already ordered or the solution is no longer offered
     */
    public function issue(Requirement $requirement, RequirementAnalysis $analysis): Quote
    {
        if (!$this->isOpen($requirement)) {
            throw new \DomainException('This request has already been ordered.');
        }
        $project = $this->projectFor($requirement) ?? $this->createProject($requirement);
        if (ProjectStatus::Analyzing !== $project->getStatus()) {
            $this->stateMachine->apply($project, 'analyze', null, ['engine' => $analysis->engine], false);
        }

        $proposal = $this->proposals->build($requirement, $analysis, null, $project);
        $breakdown = $proposal->breakdown;

        $project->getCurrentQuote()?->supersede();
        $quote = new Quote(
            $this->references->quote(),
            $project,
            $requirement,
            $proposal->solution,
            $breakdown->currency,
            $breakdown->costPrice,
            $breakdown->sellingPrice,
            $analysis->features,
            $proposal->getValidUntil(),
        );
        $quote->setHostingPlan($proposal->hostingPlan);
        $quote->setSubscriptionPlan($proposal->subscriptionPlan);
        $quote->setRecurringMonthly($breakdown->recurringMonthly);
        $quote->setDomainName($proposal->domain);
        $quote->setPricingSnapshot([
            'breakdown' => $breakdown->toArray(),
            'policy' => $proposal->policy->toArray(),
            'budget' => $proposal->getBudget(),
            'domain' => ['mode' => $proposal->domainMode, 'name' => $proposal->domain, 'requested' => $proposal->requestedDomain],
            'hosting_plan' => $proposal->hostingPlan?->getCode(),
            'warnings' => $proposal->warnings,
            'analysis' => $analysis->toArray(),
        ]);
        foreach ($breakdown->lines as $position => $line) {
            new QuoteItem($quote, $line->code, $line->label, $line->type, $line->cost, $line->price, $line->recurring, $line->customerVisible, $position); // registers itself
        }
        $this->em->persist($quote);

        $this->syncProject($project, $requirement, $proposal, $quote);
        $this->stateMachine->apply($project, 'quote', \sprintf('Quote %s issued', $quote->getNumber()), ['quote' => $quote->getNumber(), 'price' => $breakdown->sellingPrice], false);

        if (null !== $requirement->getLead()) {
            $this->leads->track($requirement->getLead(), LeadActivity::QUOTE_ISSUED, 'Quote '.$quote->getNumber(), ['quote' => $quote->getNumber(), 'price' => $breakdown->sellingPrice, 'currency' => $breakdown->currency], LeadStatus::Quoted);
        }
        $this->em->flush();

        return $quote;
    }

    private function createProject(Requirement $requirement): Project
    {
        $name = $requirement->getBusinessName() ?: 'Project';
        $slug = strtolower((string) (new AsciiSlugger())->slug($name));
        $slug = trim(substr('' !== $slug ? $slug : 'project', 0, 40), '-').'-'.bin2hex(random_bytes(2));

        $project = new Project($this->references->project(), $slug, mb_substr($name, 0, 160), $requirement);
        $project->setLocale($requirement->getLocale());
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    private function syncProject(Project $project, Requirement $requirement, Proposal $proposal, Quote $quote): void
    {
        $analysis = $proposal->analysis;
        $project->setName(mb_substr($requirement->getBusinessName() ?: $proposal->solution->getName('en'), 0, 160));
        $project->setBusinessName($requirement->getBusinessName());
        $project->setSector($requirement->getSector());
        $project->setLocale($requirement->getLocale());
        $project->setLead($requirement->getLead());
        $project->setCustomer($requirement->getCustomer() ?? $project->getCustomer());
        $project->setSolution($proposal->solution);
        $project->setFeatures($analysis->features);
        $project->setApplicationTemplate($proposal->solution->getApplicationTemplate());
        $project->setComplexity($analysis->complexity);
        $project->setEstimatedDevelopmentDays($analysis->estimatedDevelopmentDays);
        $project->setDomainName(Proposal::DOMAIN_NONE === $proposal->domainMode ? null : $proposal->domain);
        $project->setCurrency($proposal->breakdown->currency);
        $project->setBudget($proposal->getBudget());
        $project->setCurrentQuote($quote);
        [$risk, $notes] = $this->assessRisk($proposal);
        $project->setRisk($risk, $notes);
    }

    /**
     * Risk shown to the administrator who approves the project.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function assessRisk(Proposal $proposal): array
    {
        $analysis = $proposal->analysis;
        $notes = [];
        if ([] !== $analysis->unsupportedFeatures) {
            $notes[] = 'Custom development needed: '.implode(', ', $analysis->unsupportedFeatures).'.';
        }
        if ($analysis->fallbackUsed) {
            $notes[] = 'AI unavailable, rule-based fallback analysis ('.($analysis->fallbackReason ?? 'unknown').').';
        }
        if ($analysis->confidence < 0.6) {
            $notes[] = \sprintf('Low analysis confidence (%.0f %%).', $analysis->confidence * 100);
        }
        if (null === $proposal->hostingPlan) {
            $notes[] = 'No hosting plan satisfies the requirements.';
        }
        if (Proposal::DOMAIN_TO_CHOOSE === $proposal->domainMode) {
            $notes[] = 'Domain name still to be chosen with the customer.';
        }
        foreach ($analysis->risks as $risk) {
            $notes[] = $risk;
        }

        $level = match (true) {
            [] !== $analysis->unsupportedFeatures || null === $proposal->hostingPlan || $analysis->confidence < 0.4 => 'high',
            'high' === $analysis->complexity || [] !== $notes => 'medium',
            default => 'low',
        };

        return [$level, array_values(array_unique($notes))];
    }
}
