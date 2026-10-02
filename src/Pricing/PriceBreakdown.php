<?php

declare(strict_types=1);

namespace App\Pricing;

final readonly class PriceBreakdown
{
    /**
     * @param list<PriceLine>      $lines
     * @param array<string, mixed> $snapshot inputs + policy used (audit / reproducibility)
     */
    public function __construct(
        public array $lines,
        public string $currency,
        public int $costPrice,
        public int $sellingPrice,
        public int $margin,
        public float $marginPercentage,
        public int $recurringMonthly,
        public array $snapshot = [],
    ) {
    }

    /** @return list<PriceLine> */
    public function customerLines(): array
    {
        return array_values(array_filter($this->lines, static fn (PriceLine $l) => $l->customerVisible && !$l->recurring));
    }

    public function line(string $code): ?PriceLine
    {
        foreach ($this->lines as $line) {
            if ($line->code === $code) {
                return $line;
            }
        }

        return null;
    }

    public function costOf(PriceLineType $type): int
    {
        return array_sum(array_map(static fn (PriceLine $l) => $l->type === $type ? $l->cost : 0, $this->lines));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'cost_price' => $this->costPrice,
            'selling_price' => $this->sellingPrice,
            'margin' => $this->margin,
            'margin_percentage' => $this->marginPercentage,
            'recurring_monthly' => $this->recurringMonthly,
            'lines' => array_map(static fn (PriceLine $l) => $l->toArray(), $this->lines),
        ];
    }
}
