<?php

declare(strict_types=1);

namespace App\Requirement\Service;

use App\Catalog\Entity\Question;
use App\Catalog\Entity\Sector;
use App\Catalog\Questionnaire\QuestionnaireEngine;
use App\Catalog\Service\CatalogProvider;
use App\Customer\Entity\Customer;
use App\Lead\Entity\Lead;
use App\Lead\Entity\LeadActivity;
use App\Lead\Enum\LeadStatus;
use App\Lead\Service\LeadService;
use App\Requirement\Entity\Requirement;
use App\Requirement\Enum\RequirementItemSource;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds a Requirement from the questionnaire (and later the AI conversation).
 */
final readonly class RequirementService
{
    public function __construct(
        private EntityManagerInterface $em,
        private QuestionnaireEngine $questionnaire,
        private CatalogProvider $catalog,
        private LeadService $leads,
    ) {
    }

    public function start(Sector $sector, string $locale, ?Customer $customer = null): Requirement
    {
        $requirement = new Requirement($locale);
        $requirement->setSector($sector->getCode());
        $requirement->setCustomer($customer);
        if (null !== $customer) {
            $requirement->setBusinessName($customer->getCompanyName());
            $requirement->setCity($customer->getCity());
        }
        $requirement->upsertItem('sector', 'Sector', $sector->getCode(), RequirementItemSource::Questionnaire);
        $this->em->persist($requirement);
        $this->em->flush();

        return $requirement;
    }

    public function sector(Requirement $requirement): ?Sector
    {
        return $this->catalog->sector($requirement->getSector());
    }

    /**
     * Stores a validated answer; answers of questions that became hidden are removed.
     *
     * @throws \App\Catalog\Questionnaire\InvalidAnswerException
     */
    public function answer(Requirement $requirement, Question $question, mixed $raw): void
    {
        $value = $this->questionnaire->normalizeAnswer($question, $raw);
        $requirement->setAnswer($question->getCode(), $value);
        $requirement->upsertItem(
            $question->getCode(),
            $question->getLabel($requirement->getLocale()),
            $this->questionnaire->displayAnswer($question, $value, $requirement->getLocale()) ?: $value,
            RequirementItemSource::Questionnaire,
        );

        $sector = $this->sector($requirement);
        $visibleCodes = array_map(static fn (Question $q) => $q->getCode(), $this->questionnaire->visibleQuestions($sector, $requirement->getAnswers()));
        foreach (array_keys($requirement->getAnswers()) as $code) {
            if (!\in_array($code, $visibleCodes, true)) {
                $requirement->removeAnswer($code);
            }
        }
        if ('domain_name' === $question->getCode() && \is_string($value)) {
            $requirement->upsertItem('domain_name', $question->getLabel($requirement->getLocale()), self::normalizeDomain($value), RequirementItemSource::Questionnaire);
        }
        $this->em->flush();
    }

    public function updateDetails(Requirement $requirement, string $businessName, ?string $city, ?string $description, ?string $desiredDomain): void
    {
        $requirement->setBusinessName($businessName);
        $requirement->setCity($city);
        $requirement->setDescription($description);
        $requirement->upsertItem('business_name', 'Business name', $businessName, RequirementItemSource::Questionnaire);
        if (null !== $city && '' !== $city) {
            $requirement->upsertItem('city', 'City', $city, RequirementItemSource::Questionnaire);
        }
        if (null !== $desiredDomain && '' !== trim($desiredDomain)) {
            $requirement->upsertItem('desired_domain', 'Desired domain', self::normalizeDomain($desiredDomain), RequirementItemSource::Questionnaire);
        }
        $this->em->flush();
    }

    public function attachLead(Requirement $requirement, Lead $lead): void
    {
        $requirement->setLead($lead);
        $lead->setSector($lead->getSector() ?? $requirement->getSector());
        $lead->setCity($lead->getCity() ?? $requirement->getCity());
        if (null !== $requirement->getCustomer()) {
            $lead->attachCustomer($requirement->getCustomer());
        }
        $this->leads->track($lead, LeadActivity::REQUIREMENT_SUBMITTED, 'Requirement submitted', ['requirement' => $requirement->getToken(), 'sector' => $requirement->getSector()], LeadStatus::Engaged);
        $requirement->markSubmitted();
        $this->em->flush();
    }

    /**
     * Feature codes requested so far (questionnaire + conversation).
     *
     * @return list<string>
     */
    public function requestedFeatures(Requirement $requirement): array
    {
        $features = $this->questionnaire->featuresFrom($this->sector($requirement), $requirement->getAnswers());
        foreach ($requirement->getItems() as $item) {
            if (RequirementItemSource::Questionnaire !== $item->getSource() && str_starts_with($item->getKey(), 'feature.') && true === $item->getValue()) {
                $features[] = substr($item->getKey(), 8);
            }
        }

        return array_values(array_unique($features));
    }

    /**
     * Domain explicitly given by the customer (existing or desired), normalized.
     */
    public function domainFor(Requirement $requirement): ?string
    {
        foreach (['domain_name', 'desired_domain'] as $key) {
            $value = $requirement->getItem($key)?->getValue();
            if (\is_string($value) && self::isValidDomain($value)) {
                return $value;
            }
        }

        return null;
    }

    public static function normalizeDomain(string $domain): string
    {
        $domain = mb_strtolower(trim($domain));
        $domain = (string) preg_replace('#^https?://#', '', $domain);
        $domain = (string) preg_replace('#^www\.#', '', $domain);

        return rtrim(explode('/', $domain)[0], '.');
    }

    public static function isValidDomain(string $domain): bool
    {
        return 1 === preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $domain);
    }
}
