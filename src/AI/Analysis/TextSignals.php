<?php

declare(strict_types=1);

namespace App\AI\Analysis;

/**
 * What the deterministic engine understood in a piece of free text.
 */
final readonly class TextSignals
{
    /**
     * @param array<string, bool> $features feature code => wanted (true) / explicitly refused (false)
     * @param array<string, int>  $numbers  e.g. ["fleet_size" => 15]
     */
    public function __construct(
        public ?string $sector = null,
        public array $features = [],
        public array $numbers = [],
        public ?string $city = null,
    ) {
    }

    /** @return list<string> */
    public function wanted(): array
    {
        return array_keys(array_filter($this->features));
    }

    /** @return list<string> */
    public function refused(): array
    {
        return array_keys(array_filter($this->features, static fn (bool $v) => !$v));
    }

    public function isEmpty(): bool
    {
        return null === $this->sector && [] === $this->features && [] === $this->numbers && null === $this->city;
    }
}
