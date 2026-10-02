<?php

declare(strict_types=1);

namespace App\Catalog\Questionnaire;

use App\Catalog\Entity\Question;
use App\Catalog\Entity\Sector;
use App\Catalog\Enum\QuestionType;
use App\Catalog\Service\CatalogProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

/**
 * Dynamic questionnaire: which question comes next depends on the previous answers.
 * Questions and conditions are data (YAML/admin), so the questionnaire can be
 * enriched without code changes.
 */
final class QuestionnaireEngine
{
    private ExpressionLanguage $expressions;

    public function __construct(
        private readonly CatalogProvider $catalog,
        private readonly LoggerInterface $logger,
    ) {
        $this->expressions = new ExpressionLanguage();
        $this->expressions->addFunction(new ExpressionFunction(
            'has',
            static fn (string $feature) => \sprintf('in_array(%s, $features, true)', $feature),
            static fn (array $variables, string $feature) => \in_array($feature, $variables['features'], true),
        ));
    }

    /**
     * Questions currently visible for these answers, in order.
     *
     * @param array<string, mixed> $answers
     *
     * @return list<Question>
     */
    public function visibleQuestions(?Sector $sector, array $answers): array
    {
        $visible = [];
        $known = [];
        foreach ($this->catalog->questionsFor($sector) as $question) {
            // Conditions can only depend on questions asked before (no cycles).
            if ($this->isVisible($question, $answers, $known)) {
                $visible[] = $question;
                if (\array_key_exists($question->getCode(), $answers)) {
                    $known[$question->getCode()] = $answers[$question->getCode()];
                }
            }
        }

        return $visible;
    }

    /**
     * @param array<string, mixed> $answers
     */
    public function nextQuestion(?Sector $sector, array $answers): ?Question
    {
        foreach ($this->visibleQuestions($sector, $answers) as $question) {
            if (!\array_key_exists($question->getCode(), $answers)) {
                return $question;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $answers
     */
    public function previousQuestion(?Sector $sector, array $answers, ?Question $current): ?Question
    {
        $previous = null;
        foreach ($this->visibleQuestions($sector, $answers) as $question) {
            if (null !== $current && $question->getCode() === $current->getCode()) {
                return $previous;
            }
            if (\array_key_exists($question->getCode(), $answers)) {
                $previous = $question;
            }
        }

        return null === $current ? $previous : null;
    }

    /**
     * @param array<string, mixed> $answers
     */
    public function findVisible(?Sector $sector, array $answers, string $code): ?Question
    {
        foreach ($this->visibleQuestions($sector, $answers) as $question) {
            if ($question->getCode() === $code) {
                return $question;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $answers
     *
     * @return array{answered: int, total: int, percent: int}
     */
    public function progress(?Sector $sector, array $answers): array
    {
        $visible = $this->visibleQuestions($sector, $answers);
        $answered = \count(array_filter($visible, static fn (Question $q) => \array_key_exists($q->getCode(), $answers)));
        $total = max(1, \count($visible));

        return ['answered' => $answered, 'total' => \count($visible), 'percent' => (int) round($answered * 100 / $total)];
    }

    /**
     * Validates and normalizes a raw answer (from a form or the API).
     *
     * @throws InvalidAnswerException
     */
    public function normalizeAnswer(Question $question, mixed $raw): mixed
    {
        switch ($question->getType()) {
            case QuestionType::Boolean:
            case QuestionType::SingleChoice:
                $value = \is_bool($raw) ? ($raw ? 'yes' : 'no') : (\is_scalar($raw) ? (string) $raw : '');
                if (!$question->hasOption($value)) {
                    throw new InvalidAnswerException('questionnaire.error.choose_option');
                }

                return $value;
            case QuestionType::MultipleChoice:
                $values = array_values(array_unique(array_map('strval', array_filter(\is_array($raw) ? $raw : [$raw], 'is_scalar'))));
                foreach ($values as $value) {
                    if (!$question->hasOption($value)) {
                        throw new InvalidAnswerException('questionnaire.error.choose_option');
                    }
                }
                if ([] === $values && $question->isRequired()) {
                    throw new InvalidAnswerException('questionnaire.error.choose_option');
                }

                return $values;
            case QuestionType::Number:
                if (!is_numeric($raw) || (int) $raw != $raw || (int) $raw < 0 || (int) $raw > 100000) {
                    throw new InvalidAnswerException('questionnaire.error.number');
                }

                return (int) $raw;
            case QuestionType::Text:
                $value = trim(\is_scalar($raw) ? (string) $raw : '');
                if ('' === $value && $question->isRequired()) {
                    throw new InvalidAnswerException('questionnaire.error.required');
                }
                if (mb_strlen($value) > 255) {
                    throw new InvalidAnswerException('questionnaire.error.too_long');
                }

                return $value;
        }
    }

    /**
     * Feature codes implied by the answers of the visible questions.
     *
     * @param array<string, mixed> $answers
     *
     * @return list<string>
     */
    public function featuresFrom(?Sector $sector, array $answers): array
    {
        $features = [];
        foreach ($this->visibleQuestions($sector, $answers) as $question) {
            if (\array_key_exists($question->getCode(), $answers)) {
                array_push($features, ...$question->featuresFor($answers[$question->getCode()]));
            }
        }

        return array_values(array_unique($features));
    }

    /**
     * Human readable value of an answer in a locale (for summaries and the AI prompt).
     */
    public function displayAnswer(Question $question, mixed $answer, ?string $locale = null): string
    {
        if (\in_array($question->getType(), [QuestionType::Number, QuestionType::Text], true)) {
            return (string) (\is_scalar($answer) ? $answer : '');
        }
        $labels = [];
        foreach ($question->getLocalizedOptions($locale) as $option) {
            if (\in_array($option['value'], \is_array($answer) ? $answer : [(string) $answer], true)) {
                $labels[] = $option['label'];
            }
        }

        return implode(', ', $labels);
    }

    /**
     * @param array<string, mixed> $answers all answers
     * @param array<string, mixed> $known   answers of the questions already visible
     */
    private function isVisible(Question $question, array $answers, array $known): bool
    {
        $condition = $question->getCondition();
        if (null === $condition || '' === trim($condition)) {
            return true;
        }

        try {
            $allCodes = array_map(static fn (Question $q) => $q->getCode(), $this->catalog->questionsFor($question->getSector()));

            return (bool) $this->expressions->evaluate($condition, [
                'answers' => $known + array_fill_keys([...$allCodes, ...array_keys($answers)], null),
                'features' => $this->knownFeatures($question, $known),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Invalid questionnaire condition, question hidden', ['question' => $question->getCode(), 'condition' => $condition, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * @param array<string, mixed> $known
     *
     * @return list<string>
     */
    private function knownFeatures(Question $question, array $known): array
    {
        $features = [];
        foreach ($this->catalog->questionsFor($question->getSector()) as $candidate) {
            if (\array_key_exists($candidate->getCode(), $known)) {
                array_push($features, ...$candidate->featuresFor($known[$candidate->getCode()]));
            }
        }

        return $features;
    }
}
