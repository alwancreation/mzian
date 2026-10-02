<?php

declare(strict_types=1);

namespace App\AI\Analysis;

/**
 * Input of the requirement analysis: {"business_type", "answers", "description", ...}.
 */
final readonly class RequirementInput
{
    /**
     * @param array<string, mixed>  $answers           question code => answer
     * @param array<string, string> $answerLabels      question code => "Question? Answer" (human readable)
     * @param list<string>          $requestedFeatures features explicitly requested (questionnaire / chat)
     * @param list<string>          $refusedFeatures   features explicitly refused
     * @param array<string, int>    $numbers           e.g. fleet_size
     */
    public function __construct(
        public ?string $businessType,
        public array $answers = [],
        public string $description = '',
        public array $requestedFeatures = [],
        public array $refusedFeatures = [],
        public array $answerLabels = [],
        public ?string $businessName = null,
        public ?string $city = null,
        public ?string $domain = null,
        public array $numbers = [],
        public string $locale = 'fr',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'business_type' => $this->businessType,
            'answers' => $this->answers,
            'description' => $this->description,
            'requested_features' => $this->requestedFeatures,
            'refused_features' => $this->refusedFeatures,
            'answer_labels' => $this->answerLabels,
            'business_name' => $this->businessName,
            'city' => $this->city,
            'domain' => $this->domain,
            'numbers' => $this->numbers,
            'locale' => $this->locale,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            isset($data['business_type']) ? (string) $data['business_type'] : null,
            (array) ($data['answers'] ?? []),
            (string) ($data['description'] ?? ''),
            array_values(array_map('strval', (array) ($data['requested_features'] ?? []))),
            array_values(array_map('strval', (array) ($data['refused_features'] ?? []))),
            (array) ($data['answer_labels'] ?? []),
            isset($data['business_name']) ? (string) $data['business_name'] : null,
            isset($data['city']) ? (string) $data['city'] : null,
            isset($data['domain']) ? (string) $data['domain'] : null,
            array_map('intval', (array) ($data['numbers'] ?? [])),
            (string) ($data['locale'] ?? 'fr'),
        );
    }
}
