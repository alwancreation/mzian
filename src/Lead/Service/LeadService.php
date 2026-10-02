<?php

declare(strict_types=1);

namespace App\Lead\Service;

use App\Lead\Entity\Lead;
use App\Lead\Entity\LeadActivity;
use App\Lead\Enum\LeadStatus;
use App\Lead\EventSubscriber\AttributionSubscriber;
use App\Lead\Repository\LeadRepository;
use App\Shared\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Captures leads (before any payment) and records their activity in the funnel.
 */
final readonly class LeadService
{
    public function __construct(
        private EntityManagerInterface $em,
        private LeadRepository $leads,
        private AuditLogger $audit,
    ) {
    }

    /**
     * Returns the existing open lead for this e-mail or creates a new one.
     */
    public function capture(
        string $email,
        string $fullName,
        ?string $phone = null,
        ?string $companyName = null,
        ?string $sector = null,
        ?string $city = null,
        string $locale = 'fr',
        ?Request $request = null,
        bool $marketingConsent = false,
    ): Lead {
        $lead = $this->leads->findOpenByEmail($email);
        $isNew = null === $lead;
        if ($isNew) {
            $lead = new Lead($email, $fullName);
            $attribution = $this->attribution($request);
            $lead->setSource($attribution->source);
            $lead->setUtm($attribution->utm);
            $lead->setReferrer($attribution->referrer);
            $lead->setLandingPage($attribution->landingPage);
            $lead->addActivity(LeadActivity::CREATED, 'Lead captured', ['source' => $attribution->source->value]);
            $this->em->persist($lead);
        }

        $lead->setFullName($fullName);
        $lead->setPhone($phone ?? $lead->getPhone());
        $lead->setCompanyName($companyName ?? $lead->getCompanyName());
        $lead->setSector($sector ?? $lead->getSector());
        $lead->setCity($city ?? $lead->getCity());
        $lead->setLocale($locale);
        $lead->setMarketingConsent($marketingConsent || $lead->hasMarketingConsent());

        if ($isNew) {
            $this->audit->log('lead.captured', $lead, newValue: ['email' => $lead->getEmail(), 'source' => $lead->getSource()]);
        }

        return $lead;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function track(Lead $lead, string $activity, string $description, array $metadata = [], ?LeadStatus $advanceTo = null): void
    {
        $lead->addActivity($activity, $description, $metadata);
        if (null !== $advanceTo) {
            $lead->advanceTo($advanceTo);
        }
    }

    public function attribution(?Request $request): Attribution
    {
        if (null !== $request && $request->hasSession() && \is_array($data = $request->getSession()->get(AttributionSubscriber::SESSION_KEY))) {
            return Attribution::fromArray($data);
        }

        return new Attribution();
    }
}
