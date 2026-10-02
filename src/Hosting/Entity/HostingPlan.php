<?php

declare(strict_types=1);

namespace App\Hosting\Entity;

use App\Billing\Enum\BillingInterval;
use App\Hosting\Repository\HostingPlanRepository;
use App\Provider\Entity\Provider;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A hosting offer of a provider with its cost for Mzian (minor units per billing period).
 */
#[ORM\Entity(repositoryClass: HostingPlanRepository::class)]
#[ORM\Table(name: 'hosting_plan')]
#[ORM\UniqueConstraint(name: 'uniq_hosting_plan_code', fields: ['provider', 'code'])]
class HostingPlan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Provider::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Provider $provider;

    #[ORM\Column(length: 60)]
    private string $code;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column]
    private int $price;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(length: 10, enumType: BillingInterval::class)]
    private BillingInterval $billingPeriod;

    /** @var array<string, mixed> e.g. {"storage_gb": 50, "databases": 2, "email_accounts": 10, "php": "8.3"} */
    #[ORM\Column(type: Types::JSON)]
    private array $specs = [];

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $capabilities = [];

    #[ORM\Column]
    private bool $enabled = true;

    public function __construct(Provider $provider, string $code, string $name, int $price, string $currency, BillingInterval $billingPeriod)
    {
        $this->provider = $provider;
        $this->code = $code;
        $this->name = $name;
        $this->price = $price;
        $this->currency = $currency;
        $this->billingPeriod = $billingPeriod;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProvider(): Provider
    {
        return $this->provider;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPrice(): int
    {
        return $this->price;
    }

    /** Cost of the first year (what Mzian prepays when provisioning). */
    public function getYearlyPrice(): int
    {
        return BillingInterval::Year === $this->billingPeriod ? $this->price : $this->price * 12;
    }

    public function getMonthlyPrice(): int
    {
        return (int) round($this->getYearlyPrice() / 12);
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getBillingPeriod(): BillingInterval
    {
        return $this->billingPeriod;
    }

    /** @return array<string, mixed> */
    public function getSpecs(): array
    {
        return $this->specs;
    }

    /** @return list<string> */
    public function getCapabilities(): array
    {
        return $this->capabilities;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Whether this plan satisfies a solution's hosting requirements.
     *
     * @param array<string, mixed> $requirements
     */
    public function satisfies(array $requirements): bool
    {
        if (($requirements['storage_gb'] ?? 0) > ($this->specs['storage_gb'] ?? 0)) {
            return false;
        }
        if (($requirements['database'] ?? false) && ($this->specs['databases'] ?? 0) < 1) {
            return false;
        }
        if (($requirements['email_accounts'] ?? 0) > ($this->specs['email_accounts'] ?? 0)) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $specs
     * @param list<string>         $capabilities
     */
    public function update(string $name, int $price, string $currency, BillingInterval $billingPeriod, array $specs, array $capabilities, bool $enabled): void
    {
        $this->name = $name;
        $this->price = $price;
        $this->currency = $currency;
        $this->billingPeriod = $billingPeriod;
        $this->specs = $specs;
        $this->capabilities = $capabilities;
        $this->enabled = $enabled;
    }
}
