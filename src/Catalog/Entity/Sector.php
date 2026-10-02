<?php

declare(strict_types=1);

namespace App\Catalog\Entity;

use App\Catalog\Repository\SectorRepository;
use App\Shared\I18n\LocalizedText;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Business sector proposed in the first questionnaire step ("Quel est votre secteur ?").
 */
#[ORM\Entity(repositoryClass: SectorRepository::class)]
#[ORM\Table(name: 'sector')]
class Sector
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

    /** @var array<string, string> localized URL slugs for the SEO "industries" pages */
    #[ORM\Column(type: Types::JSON)]
    private array $slugs = [];

    #[ORM\Column(length: 16)]
    private string $icon = '✨';

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $defaultSolutionCode = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $enabled = true;

    /**
     * @param array<string, string> $name
     */
    public function __construct(string $code, array $name)
    {
        $this->code = $code;
        $this->name = $name;
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

    /** @return array<string, string> */
    public function getNameTranslations(): array
    {
        return $this->name;
    }

    public function getDescription(?string $locale = null): string
    {
        return LocalizedText::pick($this->description, $locale);
    }

    public function getSlug(?string $locale = null): string
    {
        return LocalizedText::pick($this->slugs, $locale) ?: str_replace('_', '-', $this->code);
    }

    /** @return array<string, string> */
    public function getSlugs(): array
    {
        return $this->slugs;
    }

    public function getIcon(): string
    {
        return $this->icon;
    }

    public function getDefaultSolutionCode(): ?string
    {
        return $this->defaultSolutionCode;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /**
     * @param array<string, string> $name
     * @param array<string, string> $description
     * @param array<string, string> $slugs
     */
    public function update(array $name, array $description, array $slugs, string $icon, ?string $defaultSolutionCode, int $position): void
    {
        $this->name = $name;
        $this->description = $description;
        $this->slugs = $slugs;
        $this->icon = $icon;
        $this->defaultSolutionCode = $defaultSolutionCode;
        $this->position = $position;
    }
}
