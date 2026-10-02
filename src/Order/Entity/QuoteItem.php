<?php

declare(strict_types=1);

namespace App\Order\Entity;

use App\Pricing\PriceLineType;
use Doctrine\ORM\Mapping as ORM;

/**
 * A pricing line. "cost" is what Mzian pays, "price" what the customer sees;
 * internal lines (margin, AI, fees) are not customer visible.
 */
#[ORM\Entity]
#[ORM\Table(name: 'quote_item')]
class QuoteItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Quote::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Quote $quote;

    #[ORM\Column(length: 80)]
    private string $code;

    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column(length: 20, enumType: PriceLineType::class)]
    private PriceLineType $type;

    #[ORM\Column]
    private int $cost;

    #[ORM\Column]
    private int $price;

    #[ORM\Column]
    private bool $recurring;

    #[ORM\Column]
    private bool $customerVisible;

    #[ORM\Column]
    private int $position;

    public function __construct(Quote $quote, string $code, string $label, PriceLineType $type, int $cost, int $price, bool $recurring, bool $customerVisible, int $position)
    {
        $this->quote = $quote;
        $this->code = $code;
        $this->label = mb_substr($label, 0, 255);
        $this->type = $type;
        $this->cost = $cost;
        $this->price = $price;
        $this->recurring = $recurring;
        $this->customerVisible = $customerVisible;
        $this->position = $position;
        $quote->addItem($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuote(): Quote
    {
        return $this->quote;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getType(): PriceLineType
    {
        return $this->type;
    }

    public function getCost(): int
    {
        return $this->cost;
    }

    public function getPrice(): int
    {
        return $this->price;
    }

    public function isRecurring(): bool
    {
        return $this->recurring;
    }

    public function isCustomerVisible(): bool
    {
        return $this->customerVisible;
    }

    public function getPosition(): int
    {
        return $this->position;
    }
}
