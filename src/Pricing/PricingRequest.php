<?php

declare(strict_types=1);

namespace App\Pricing;

/**
 * Everything the pricing engine needs, as plain values (amounts in minor units).
 */
final readonly class PricingRequest
{
    /**
     * @param list<array{code: string, label: string, price: int}>  $options        optional catalog features requested
     * @param list<array{code: string, label: string}>              $customFeatures requests no template covers
     * @param array{code: string, label: string, price: int}|null   $hosting        first year of hosting
     * @param array{name: string, label: string, price: int}|null   $domain         first year of the domain (null = none / customer's own)
     * @param array{code: string, label: string, monthly: int}|null $subscription   recurring plan after delivery
     */
    public function __construct(
        public string $solutionCode,
        public string $solutionLabel,
        public int $basePrice,
        public string $complexity = 'low',
        public array $options = [],
        public array $customFeatures = [],
        public ?array $hosting = null,
        public ?array $domain = null,
        public int $emailAccounts = 0,
        public string $emailLabel = 'Professional e-mail',
        public ?array $subscription = null,
        public string $customFeatureLabel = 'Custom development: %s',
    ) {
        if (!\in_array($this->complexity, ['low', 'medium', 'high'], true)) {
            throw new \InvalidArgumentException('Unknown complexity '.$this->complexity);
        }
    }
}
