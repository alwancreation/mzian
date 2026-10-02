<?php

declare(strict_types=1);

namespace App\Catalog\Entity;

use App\Catalog\Enum\SolutionCategory;
use App\Catalog\Repository\SolutionRepository;
use App\Shared\Entity\TimestampableTrait;
use App\Shared\I18n\LocalizedText;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A sellable solution of the configurable catalog (prices are data, never code).
 * Amounts are stored in minor units (cents) of the platform currency.
 */
#[ORM\Entity(repositoryClass: SolutionRepository::class)]
#[ORM\Table(name: 'solution')]
#[ORM\Index(name: 'idx_solution_enabled', fields: ['enabled', 'position'])]
#[ORM\HasLifecycleCallbacks]
class Solution
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Stable machine name used by the AI analyzer, e.g. "car_rental_management". */
    #[ORM\Column(length: 80, unique: true)]
    private string $code;

    /** Canonical (French) slug, e.g. "location-voiture". */
    #[ORM\Column(length: 120, unique: true)]
    private string $slug;

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $slugs = [];

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $name;

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $shortDescription = [];

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $description = [];

    #[ORM\Column(length: 20, enumType: SolutionCategory::class)]
    private SolutionCategory $category;

    /** Base solution price (development & setup), minor units. */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $basePrice;

    #[ORM\Column]
    #[Assert\Positive]
    private int $estimatedDevelopmentDays;

    /** Monthly maintenance price, minor units. */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $maintenancePrice = 0;

    /** @var array<string, mixed> e.g. {"storage_gb": 10, "database": true, "ssl": true, "email_accounts": 2} */
    #[ORM\Column(type: Types::JSON)]
    private array $hostingRequirements = [];

    /** @var array<string, mixed> e.g. {"required": true, "preferred_tlds": [".com", ".ma"]} */
    #[ORM\Column(type: Types::JSON)]
    private array $domainRequirements = [];

    /** Code of the application template used by the Development Agent. */
    #[ORM\Column(length: 60)]
    private string $applicationTemplate;

    /** @var list<string> sector codes this solution is recommended for */
    #[ORM\Column(type: Types::JSON)]
    private array $sectors = [];

    #[ORM\Column(length: 16)]
    private string $icon = '🌐';

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column]
    private bool $featured = false;

    #[ORM\Column]
    private int $position = 0;

    /** @var Collection<int, SolutionFeature> */
    #[ORM\OneToMany(targetEntity: SolutionFeature::class, mappedBy: 'solution', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $features;

    /**
     * @param array<string, string> $name
     */
    public function __construct(string $code, string $slug, array $name, SolutionCategory $category, int $basePrice, int $estimatedDevelopmentDays, string $applicationTemplate)
    {
        $this->code = $code;
        $this->slug = $slug;
        $this->name = $name;
        $this->category = $category;
        $this->basePrice = $basePrice;
        $this->estimatedDevelopmentDays = $estimatedDevelopmentDays;
        $this->applicationTemplate = $applicationTemplate;
        $this->features = new ArrayCollection();
        $this->initializeTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getSlug(?string $locale = null): string
    {
        if (null === $locale) {
            return $this->slug;
        }

        return $this->slugs[$locale] ?? $this->slug;
    }

    /** @return array<string, string> */
    public function getSlugs(): array
    {
        return $this->slugs;
    }

    public function getName(?string $locale = null): string
    {
        return LocalizedText::pick($this->name, $locale);
    }

    /** @return array<string, string> */
    public function getNameTranslations(): array
    {
        return $this->name;
    }

    public function getShortDescription(?string $locale = null): string
    {
        return LocalizedText::pick($this->shortDescription, $locale);
    }

    /** @return array<string, string> */
    public function getShortDescriptionTranslations(): array
    {
        return $this->shortDescription;
    }

    public function getDescription(?string $locale = null): string
    {
        return LocalizedText::pick($this->description, $locale);
    }

    /** @return array<string, string> */
    public function getDescriptionTranslations(): array
    {
        return $this->description;
    }

    public function getCategory(): SolutionCategory
    {
        return $this->category;
    }

    public function getBasePrice(): int
    {
        return $this->basePrice;
    }

    public function setBasePrice(int $basePrice): void
    {
        $this->basePrice = $basePrice;
    }

    public function getEstimatedDevelopmentDays(): int
    {
        return $this->estimatedDevelopmentDays;
    }

    public function setEstimatedDevelopmentDays(int $days): void
    {
        $this->estimatedDevelopmentDays = $days;
    }

    public function getMaintenancePrice(): int
    {
        return $this->maintenancePrice;
    }

    public function setMaintenancePrice(int $maintenancePrice): void
    {
        $this->maintenancePrice = $maintenancePrice;
    }

    /** @return array<string, mixed> */
    public function getHostingRequirements(): array
    {
        return $this->hostingRequirements;
    }

    /** @return array<string, mixed> */
    public function getDomainRequirements(): array
    {
        return $this->domainRequirements;
    }

    public function isDomainRequired(): bool
    {
        return (bool) ($this->domainRequirements['required'] ?? true);
    }

    public function getApplicationTemplate(): string
    {
        return $this->applicationTemplate;
    }

    /** @return list<string> */
    public function getSectors(): array
    {
        return $this->sectors;
    }

    public function getIcon(): string
    {
        return $this->icon;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function isFeatured(): bool
    {
        return $this->featured;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    /** @return Collection<int, SolutionFeature> */
    public function getFeatures(): Collection
    {
        return $this->features;
    }

    /** @return list<SolutionFeature> */
    public function getEnabledFeatures(): array
    {
        return array_values($this->features->filter(static fn (SolutionFeature $f) => $f->isEnabled())->toArray());
    }

    public function getFeature(string $code): ?SolutionFeature
    {
        foreach ($this->features as $feature) {
            if ($feature->getCode() === $code) {
                return $feature;
            }
        }

        return null;
    }

    public function addFeature(SolutionFeature $feature): void
    {
        if (!$this->features->contains($feature)) {
            $this->features->add($feature);
        }
    }

    /**
     * @param array<string, string> $name
     * @param array<string, string> $shortDescription
     * @param array<string, string> $description
     * @param array<string, string> $slugs
     * @param array<string, mixed>  $hostingRequirements
     * @param array<string, mixed>  $domainRequirements
     * @param list<string>          $sectors
     */
    public function update(
        array $name,
        array $shortDescription,
        array $description,
        array $slugs,
        SolutionCategory $category,
        int $basePrice,
        int $estimatedDevelopmentDays,
        int $maintenancePrice,
        array $hostingRequirements,
        array $domainRequirements,
        string $applicationTemplate,
        array $sectors,
        string $icon,
        bool $featured,
        int $position,
    ): void {
        $this->name = $name;
        $this->shortDescription = $shortDescription;
        $this->description = $description;
        $this->slugs = $slugs;
        $this->category = $category;
        $this->basePrice = $basePrice;
        $this->estimatedDevelopmentDays = $estimatedDevelopmentDays;
        $this->maintenancePrice = $maintenancePrice;
        $this->hostingRequirements = $hostingRequirements;
        $this->domainRequirements = $domainRequirements;
        $this->applicationTemplate = $applicationTemplate;
        $this->sectors = $sectors;
        $this->icon = $icon;
        $this->featured = $featured;
        $this->position = $position;
    }
}
