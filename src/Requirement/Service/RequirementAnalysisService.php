<?php

declare(strict_types=1);

namespace App\Requirement\Service;

use App\AI\Analysis\RequirementAnalysis;
use App\AI\Analysis\RequirementAnalyzer;
use App\AI\Analysis\RequirementInput;
use App\Catalog\Questionnaire\QuestionnaireEngine;
use App\Catalog\Service\CatalogProvider;
use App\Project\Entity\Project;
use App\Requirement\Entity\Requirement;
use App\Requirement\Enum\MessageRole;
use App\Shared\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds the analyzer input from a Requirement (questionnaire + conversation) and
 * stores the validated analysis on it.
 */
final readonly class RequirementAnalysisService
{
    private const NUMERIC_KEYS = ['fleet_size', 'rooms_count', 'products_count', 'staff_count', 'offers_count'];

    public function __construct(
        private RequirementAnalyzer $analyzer,
        private RequirementService $requirements,
        private QuestionnaireEngine $questionnaire,
        private CatalogProvider $catalog,
        private EntityManagerInterface $em,
        private AuditLogger $audit,
    ) {
    }

    public function buildInput(Requirement $requirement): RequirementInput
    {
        $sector = $this->requirements->sector($requirement);
        $labels = [];
        foreach ($this->questionnaire->visibleQuestions($sector, $requirement->getAnswers()) as $question) {
            if (\array_key_exists($question->getCode(), $requirement->getAnswers())) {
                $labels[$question->getCode()] = $question->getLabel('en').' '.$this->questionnaire->displayAnswer($question, $requirement->getAnswers()[$question->getCode()], 'en');
            }
        }

        $refused = [];
        $numbers = [];
        foreach ($requirement->getItems() as $item) {
            if (str_starts_with($item->getKey(), 'feature.') && false === $item->getValue()) {
                $refused[] = substr($item->getKey(), 8);
            }
            if (\in_array($item->getKey(), self::NUMERIC_KEYS, true) && is_numeric($item->getValue())) {
                $numbers[$item->getKey()] = (int) $item->getValue();
            }
        }
        foreach (self::NUMERIC_KEYS as $key) {
            if (is_numeric($requirement->getAnswers()[$key] ?? null)) {
                $numbers[$key] = (int) $requirement->getAnswers()[$key];
            }
        }

        $description = (string) $requirement->getDescription();
        $conversation = $requirement->getConversation();
        if (null !== $conversation) {
            foreach ($conversation->getMessages() as $message) {
                if (MessageRole::User === $message->getRole()) {
                    $description .= "\n".$message->getContent();
                }
            }
        }

        return new RequirementInput(
            $requirement->getSector(),
            $requirement->getAnswers(),
            trim($description),
            $this->requirements->requestedFeatures($requirement),
            array_values(array_unique($refused)),
            $labels,
            $requirement->getBusinessName(),
            $requirement->getCity(),
            $this->requirements->domainFor($requirement),
            $numbers,
            $requirement->getLocale(),
        );
    }

    public function analyze(Requirement $requirement, ?Project $project = null): RequirementAnalysis
    {
        $analysis = $this->analyzer->analyze($this->buildInput($requirement), $project);
        $requirement->recordAnalysis($analysis->toArray(), $this->catalog->solution($analysis->solution));
        $this->audit->log('requirement.analyzed', $requirement, newValue: [
            'solution' => $analysis->solution,
            'complexity' => $analysis->complexity,
            'engine' => $analysis->engine,
            'fallback' => $analysis->fallbackUsed,
        ]);
        $this->em->flush();

        return $analysis;
    }

    public function storedAnalysis(Requirement $requirement): ?RequirementAnalysis
    {
        return null !== $requirement->getAnalysis() ? RequirementAnalysis::fromArray($requirement->getAnalysis()) : null;
    }
}
