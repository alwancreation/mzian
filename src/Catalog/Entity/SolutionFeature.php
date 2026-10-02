<?php

declare(strict_types=1);

namespace App\Catalog\Entity;

use App\Shared\I18n\LocalizedText;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A feature of a solution. "Included" features are part of the base price;
 * optional ones add their own price (minor units) when requested.
 */
#[ORM\Entity]
#[ORM\Table(name: 'solution_feature')]
#[ORM\UniqueConstraint(name: 'uniq_solution_feature_code', fields: ['solution', 'code'])]
class SolutionFeature
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Solution::class, inversedBy: 'features')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Solution $solution;

    #[ORM\Column(length: 80)]
    private string $code;

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $name;

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $description = [];

    #[ORM\Column]
    private bool $included;

    #[ORM\Column]
    private int $price;

    /** Relative effort (1 = trivial ... 5 = heavy), drives complexity estimation. */
    #[ORM\Column]
    private int $complexityWeight = 1;

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column]
    private int $position = 0;

    /**
     * @param array<string, string> $name
     */
    public function __construct(Solution $solution, string $code, array $name, bool $included, int $price = 0)
    {
        $this->solution = $solution;
        $this->code = $code;
        $this->name = $name;
        $this->included = $included;
        $this->price = $price;
        $solution->addFeature($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSolution(): Solution
    {
        return $this->solution;
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

    public function isIncluded(): bool
    {
        return $this->included;
    }

    public function getPrice(): int
    {
        return $this->price;
    }

    public function getComplexityWeight(): int
    {
        return $this->complexityWeight;
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
     * @param array<string, string> $name
     * @param array<string, string> $description
     */
    public function update(array $name, array $description, bool $included, int $price, int $complexityWeight, bool $enabled, int $position): void
    {
        $this->name = $name;
        $this->description = $description;
        $this->included = $included;
        $this->price = $price;
        $this->complexityWeight = max(1, min(5, $complexityWeight));
        $this->enabled = $enabled;
        $this->position = $position;
    }
}
