<?php

declare(strict_types=1);

namespace App\Lead\Service;

use App\Lead\Enum\LeadSource;

/**
 * First-touch marketing attribution of a visitor (kept in session until the lead is captured).
 */
final readonly class Attribution
{
    /**
     * @param array<string, string> $utm
     */
    public function __construct(
        public LeadSource $source = LeadSource::Direct,
        public array $utm = [],
        public ?string $referrer = null,
        public ?string $landingPage = null,
    ) {
    }

    /**
     * @return array{source: string, utm: array<string, string>, referrer: ?string, landing: ?string}
     */
    public function toArray(): array
    {
        return ['source' => $this->source->value, 'utm' => $this->utm, 'referrer' => $this->referrer, 'landing' => $this->landingPage];
    }

    /**
     * @param array{source?: string, utm?: array<string, string>, referrer?: ?string, landing?: ?string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            LeadSource::tryFrom($data['source'] ?? '') ?? LeadSource::Direct,
            $data['utm'] ?? [],
            $data['referrer'] ?? null,
            $data['landing'] ?? null,
        );
    }
}
