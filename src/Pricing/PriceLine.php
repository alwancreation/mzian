<?php

declare(strict_types=1);

namespace App\Pricing;

/**
 * cost  = what Mzian pays for this line;
 * price = what the customer pays (0 for internal lines such as AI, fees, margin).
 */
final readonly class PriceLine
{
    public function __construct(
        public string $code,
        public string $label,
        public PriceLineType $type,
        public int $cost,
        public int $price,
        public bool $customerVisible,
        public bool $recurring = false,
    ) {
    }

    /**
     * @return array{code: string, label: string, type: string, cost: int, price: int, customer_visible: bool, recurring: bool}
     */
    public function toArray(): array
    {
        return ['code' => $this->code, 'label' => $this->label, 'type' => $this->type->value, 'cost' => $this->cost, 'price' => $this->price, 'customer_visible' => $this->customerVisible, 'recurring' => $this->recurring];
    }
}
