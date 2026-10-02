<?php

declare(strict_types=1);

namespace App\Pricing;

/**
 * Pricing configuration (amounts in minor units). Built from the "pricing" settings:
 * nothing is hardcoded, administrators change it in Admin > Pricing.
 */
final readonly class PricingPolicy
{
    /**
     * @param array<string, int>   $aiCost                cost per complexity (low/medium/high)
     * @param array<string, float> $complexityMultipliers development multiplier per complexity
     */
    public function __construct(
        public string $currency = 'USD',
        public int $minimumMargin = 5000,
        public int $targetMargin = 8000,
        public float $targetMarginRate = 0.0,
        public float $paymentFeePercent = 2.9,
        public int $paymentFeeFixed = 30,
        public int $infrastructureCost = 1000,
        public int $emailCostPerAccount = 200,
        public array $aiCost = ['low' => 300, 'medium' => 500, 'high' => 800],
        public array $complexityMultipliers = ['low' => 1.0, 'medium' => 1.25, 'high' => 1.6],
        public int $customFeaturePrice = 8000,
        public int $rounding = 100,
        public int $quoteValidityDays = 30,
        public float $budgetBufferPercent = 15.0,
    ) {
        if ($this->minimumMargin < 0 || $this->paymentFeePercent < 0 || $this->paymentFeePercent >= 50) {
            throw new \InvalidArgumentException('Invalid pricing policy.');
        }
    }

    /**
     * @param array<string, mixed> $settings values in MAJOR units (see config/packages/mzian.yaml)
     */
    public static function fromSettings(array $settings): self
    {
        $cents = static fn (mixed $v): int => (int) round((float) $v * 100);

        return new self(
            (string) ($settings['currency'] ?? 'USD'),
            $cents($settings['minimum_margin'] ?? 50),
            $cents($settings['target_margin'] ?? 80),
            (float) ($settings['target_margin_rate'] ?? 0),
            (float) ($settings['payment_fee_percent'] ?? 0),
            $cents($settings['payment_fee_fixed'] ?? 0),
            $cents($settings['infrastructure_cost'] ?? 0),
            $cents($settings['email_cost_per_account'] ?? 0),
            array_map($cents, (array) ($settings['ai_cost'] ?? ['low' => 0, 'medium' => 0, 'high' => 0])),
            array_map('floatval', (array) ($settings['complexity_multipliers'] ?? ['low' => 1, 'medium' => 1, 'high' => 1])),
            $cents($settings['custom_feature_price'] ?? 0),
            max(1, $cents($settings['rounding'] ?? 1)),
            (int) ($settings['quote_validity_days'] ?? 30),
            (float) ($settings['budget_buffer_percent'] ?? 0),
        );
    }

    public function multiplier(string $complexity): float
    {
        return (float) ($this->complexityMultipliers[$complexity] ?? 1.0);
    }

    public function aiCostFor(string $complexity): int
    {
        return (int) ($this->aiCost[$complexity] ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
