<?php

declare(strict_types=1);

namespace App\Billing\Entity;

use App\Billing\Enum\BillingInterval;
use App\Billing\Repository\SubscriptionPlanRepository;
use App\Shared\I18n\LocalizedText;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Recurring offer (hosting + maintenance + support...), e.g. "Mzian Starter $15/month".
 * Prices are configurable data.
 */
#[ORM\Entity(repositoryClass: SubscriptionPlanRepository::class)]
#[ORM\Table(name: 'subscription_plan')]
class SubscriptionPlan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 60, unique: true)]
    private string $code;

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $name;

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $description = [];

    #[ORM\Column]
    private int $price;

    #[ORM\Column(name: 'billing_interval', length: 10, enumType: BillingInterval::class)]
    private BillingInterval $interval;

    /** @var list<array<string, string>> localized bullet points */
    #[ORM\Column(type: Types::JSON)]
    private array $features = [];

    /** @var list<string> included services: hosting, maintenance, support, ai_usage, premium_features */
    #[ORM\Column(type: Types::JSON)]
    private array $includes = [];

    #[ORM\Column]
    private bool $recommended = false;

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column]
    private int $position = 0;

    /**
     * @param array<string, string> $name
     */
    public function __construct(string $code, array $name, int $price, BillingInterval $interval)
    {
        $this->code = $code;
        $this->name = $name;
        $this->price = $price;
        $this->interval = $interval;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(?string $locale = null): string
    {
        return LocalizedText::pick($this->name, $locale);
    }

    public function getDescription(?string $locale = null): string
    {
        return LocalizedText::pick($this->description, $locale);
    }

    public function getPrice(): int
    {
        return $this->price;
    }

    public function getMonthlyPrice(): int
    {
        return (int) round($this->price / $this->interval->months());
    }

    public function getInterval(): BillingInterval
    {
        return $this->interval;
    }

    /** @return list<string> */
    public function getFeatures(?string $locale = null): array
    {
        return array_map(static fn (array $f) => LocalizedText::pick($f, $locale), $this->features);
    }

    /** @return list<string> */
    public function getIncludes(): array
    {
        return $this->includes;
    }

    public function isRecommended(): bool
    {
        return $this->recommended;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    /**
     * @param array<string, string>       $name
     * @param array<string, string>       $description
     * @param list<array<string, string>> $features
     * @param list<string>                $includes
     */
    public function update(array $name, array $description, int $price, BillingInterval $interval, array $features, array $includes, bool $recommended, bool $enabled, int $position): void
    {
        $this->name = $name;
        $this->description = $description;
        $this->price = $price;
        $this->interval = $interval;
        $this->features = $features;
        $this->includes = $includes;
        $this->recommended = $recommended;
        $this->enabled = $enabled;
        $this->position = $position;
    }
}
