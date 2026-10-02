<?php

declare(strict_types=1);

namespace App\Order\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'order_item')]
class OrderItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    #[ORM\Column(length: 80)]
    private string $code;

    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column(length: 20)]
    private string $type;

    #[ORM\Column]
    private int $unitPrice;

    #[ORM\Column]
    private int $quantity = 1;

    #[ORM\Column]
    private bool $recurring;

    public function __construct(Order $order, string $code, string $label, string $type, int $unitPrice, bool $recurring)
    {
        $this->order = $order;
        $this->code = $code;
        $this->label = mb_substr($label, 0, 255);
        $this->type = $type;
        $this->unitPrice = $unitPrice;
        $this->recurring = $recurring;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrder(): Order
    {
        return $this->order;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getUnitPrice(): int
    {
        return $this->unitPrice;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getTotal(): int
    {
        return $this->unitPrice * $this->quantity;
    }

    public function isRecurring(): bool
    {
        return $this->recurring;
    }
}
