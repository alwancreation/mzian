<?php

declare(strict_types=1);

namespace App\AI\Analysis;

/**
 * Validated, structured result of the requirement analysis (never free text only).
 */
final readonly class RequirementAnalysis
{
    /**
     * @param list<string>                                                           $features
     * @param array{storage_gb: int, database: bool, ssl: bool, email_accounts: int} $hostingRequirements
     * @param list<string>                                                           $unsupportedFeatures
     * @param list<string>                                                           $risks
     * @param array<string, mixed>                                                   $usage
     */
    public function __construct(
        public string $solutionType,
        public string $solution,
        public array $features,
        public string $complexity,
        public int $estimatedDevelopmentDays,
        public array $hostingRequirements,
        public bool $domainRequired,
        public string $recommendation,
        public float $confidence,
        public array $unsupportedFeatures = [],
        public array $risks = [],
        public string $engine = 'rules',
        public bool $fallbackUsed = false,
        public ?string $fallbackReason = null,
        public array $usage = [],
    ) {
    }

    /**
     * Builds the DTO from a schema-validated array (snake_case, as returned by the AI).
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $usage
     */
    public static function fromArray(array $data, string $engine = 'rules', bool $fallbackUsed = false, ?string $fallbackReason = null, array $usage = []): self
    {
        $hosting = (array) ($data['hosting_requirements'] ?? []);

        return new self(
            (string) $data['solution_type'],
            (string) $data['solution'],
            array_values(array_unique(array_map('strval', (array) $data['features']))),
            (string) $data['complexity'],
            (int) $data['estimated_development_days'],
            [
                'storage_gb' => (int) ($hosting['storage_gb'] ?? 5),
                'database' => (bool) ($hosting['database'] ?? false),
                'ssl' => (bool) ($hosting['ssl'] ?? true),
                'email_accounts' => (int) ($hosting['email_accounts'] ?? 1),
            ],
            (bool) $data['domain_required'],
            (string) $data['recommendation'],
            (float) $data['confidence'],
            array_values(array_unique(array_map('strval', (array) ($data['unsupported_features'] ?? [])))),
            array_values(array_map('strval', (array) ($data['risks'] ?? []))),
            (string) ($data['engine'] ?? $engine),
            (bool) ($data['fallback_used'] ?? $fallbackUsed),
            isset($data['fallback_reason']) ? (string) $data['fallback_reason'] : $fallbackReason,
            (array) ($data['usage'] ?? $usage),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'solution_type' => $this->solutionType,
            'solution' => $this->solution,
            'features' => $this->features,
            'complexity' => $this->complexity,
            'estimated_development_days' => $this->estimatedDevelopmentDays,
            'hosting_requirements' => $this->hostingRequirements,
            'domain_required' => $this->domainRequired,
            'recommendation' => $this->recommendation,
            'confidence' => $this->confidence,
            'unsupported_features' => $this->unsupportedFeatures,
            'risks' => $this->risks,
            'engine' => $this->engine,
            'fallback_used' => $this->fallbackUsed,
            'fallback_reason' => $this->fallbackReason,
            'usage' => $this->usage,
        ];
    }

    /**
     * The schema-shaped part only (what an AI provider must return).
     *
     * @return array<string, mixed>
     */
    public function toSchemaArray(): array
    {
        return array_diff_key($this->toArray(), array_flip(['engine', 'fallback_used', 'fallback_reason', 'usage']));
    }
}
