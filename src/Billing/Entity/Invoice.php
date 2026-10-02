<?php

declare(strict_types=1);

namespace App\Billing\Entity;

use App\Billing\Enum\InvoiceStatus;
use App\Billing\Repository\InvoiceRepository;
use App\Customer\Entity\Customer;
use App\Order\Entity\Order;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InvoiceRepository::class)]
#[ORM\Table(name: 'invoice')]
class Invoice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 24, unique: true)]
    private string $number;

    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Order $order;

    #[ORM\ManyToOne(targetEntity: Subscription::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Subscription $subscription = null;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Customer $customer;

    #[ORM\Column(length: 10, enumType: InvoiceStatus::class)]
    private InvoiceStatus $status = InvoiceStatus::Issued;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column]
    private int $subtotal;

    #[ORM\Column]
    private int $taxAmount;

    #[ORM\Column]
    private int $total;

    /** @var list<array{label: string, amount: int}> */
    #[ORM\Column(name: 'invoice_lines', type: Types::JSON)]
    private array $lines;

    /** @var array<string, string|null> */
    #[ORM\Column(type: Types::JSON)]
    private array $billingDetails;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $issuedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    /**
     * @param list<array{label: string, amount: int}> $lines
     * @param array<string, string|null>              $billingDetails
     */
    public function __construct(string $number, Customer $customer, ?Order $order, string $currency, array $lines, int $taxAmount, array $billingDetails)
    {
        $this->number = $number;
        $this->customer = $customer;
        $this->order = $order;
        $this->currency = $currency;
        $this->lines = $lines;
        $this->subtotal = array_sum(array_column($lines, 'amount'));
        $this->taxAmount = $taxAmount;
        $this->total = $this->subtotal + $taxAmount;
        $this->billingDetails = $billingDetails;
        $this->issuedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function getOrder(): ?Order
    {
        return $this->order;
    }

    public function getSubscription(): ?Subscription
    {
        return $this->subscription;
    }

    public function setSubscription(?Subscription $subscription): void
    {
        $this->subscription = $subscription;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function getStatus(): InvoiceStatus
    {
        return $this->status;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getSubtotal(): int
    {
        return $this->subtotal;
    }

    public function getTaxAmount(): int
    {
        return $this->taxAmount;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    /** @return list<array{label: string, amount: int}> */
    public function getLines(): array
    {
        return $this->lines;
    }

    /** @return array<string, string|null> */
    public function getBillingDetails(): array
    {
        return $this->billingDetails;
    }

    public function getIssuedAt(): \DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function markPaid(): void
    {
        $this->status = InvoiceStatus::Paid;
        $this->paidAt = new \DateTimeImmutable();
    }

    public function void(): void
    {
        $this->status = InvoiceStatus::Void;
    }
}
