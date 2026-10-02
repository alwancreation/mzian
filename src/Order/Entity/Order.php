<?php

declare(strict_types=1);

namespace App\Order\Entity;

use App\Customer\Entity\Customer;
use App\Order\Enum\OrderStatus;
use App\Order\Repository\OrderRepository;
use App\Project\Entity\Project;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: 'customer_order')]
#[ORM\Index(name: 'idx_order_status', fields: ['status'])]
#[ORM\Index(name: 'idx_order_created', fields: ['createdAt'])]
#[ORM\HasLifecycleCallbacks]
class Order
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 24, unique: true)]
    private string $number;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Customer $customer;

    #[ORM\OneToOne(targetEntity: Project::class, inversedBy: 'order')]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'RESTRICT')]
    private Project $project;

    #[ORM\ManyToOne(targetEntity: Quote::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Quote $quote;

    #[ORM\Column(length: 20, enumType: OrderStatus::class)]
    private OrderStatus $status = OrderStatus::PendingPayment;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column]
    private int $total;

    #[ORM\Column]
    private int $costPrice;

    #[ORM\Column]
    private int $margin;

    #[ORM\Column]
    private int $recurringMonthly;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    /** @var Collection<int, OrderItem> */
    #[ORM\OneToMany(targetEntity: OrderItem::class, mappedBy: 'order', cascade: ['persist'], orphanRemoval: true)]
    private Collection $items;

    public function __construct(string $number, Customer $customer, Quote $quote)
    {
        $this->number = $number;
        $this->customer = $customer;
        $this->quote = $quote;
        $this->project = $quote->getProject();
        $this->project->setOrder($this);
        $this->currency = $quote->getCurrency();
        $this->total = $quote->getSellingPrice();
        $this->costPrice = $quote->getCostPrice();
        $this->margin = $quote->getMargin();
        $this->recurringMonthly = $quote->getRecurringMonthly();
        $this->items = new ArrayCollection();
        foreach ($quote->getCustomerItems() as $quoteItem) {
            $this->items->add(new OrderItem($this, $quoteItem->getCode(), $quoteItem->getLabel(), $quoteItem->getType()->value, $quoteItem->getPrice(), $quoteItem->isRecurring()));
        }
        $this->initializeTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function getProject(): Project
    {
        return $this->project;
    }

    public function getQuote(): Quote
    {
        return $this->quote;
    }

    public function getStatus(): OrderStatus
    {
        return $this->status;
    }

    public function isPaid(): bool
    {
        return OrderStatus::Paid === $this->status;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function getCostPrice(): int
    {
        return $this->costPrice;
    }

    public function getMargin(): int
    {
        return $this->margin;
    }

    public function getRecurringMonthly(): int
    {
        return $this->recurringMonthly;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    /** @return Collection<int, OrderItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function markPaid(): void
    {
        if (OrderStatus::PendingPayment !== $this->status) {
            throw new \LogicException(\sprintf('Order %s cannot be marked as paid from status %s.', $this->number, $this->status->value));
        }
        $this->status = OrderStatus::Paid;
        $this->paidAt = new \DateTimeImmutable();
    }

    public function cancel(): void
    {
        if (OrderStatus::Paid === $this->status) {
            $this->status = OrderStatus::Refunded;
        } elseif (OrderStatus::PendingPayment === $this->status) {
            $this->status = OrderStatus::Cancelled;
        }
        $this->cancelledAt = new \DateTimeImmutable();
    }
}
