<?php

declare(strict_types=1);

namespace App\Shared\Entity;

use App\Shared\Repository\SettingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Admin-editable configuration value (pricing policy, automation policy...).
 * Defaults come from config/packages/mzian.yaml; a Setting row overrides them.
 */
#[ORM\Entity(repositoryClass: SettingRepository::class)]
#[ORM\Table(name: 'setting')]
class Setting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'setting_key', length: 100, unique: true)]
    private string $key;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $value;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $updatedBy = null;

    /**
     * @param array<string, mixed> $value
     */
    public function __construct(string $key, array $value)
    {
        $this->key = $key;
        $this->value = $value;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    /** @return array<string, mixed> */
    public function getValue(): array
    {
        return $this->value;
    }

    /**
     * @param array<string, mixed> $value
     */
    public function update(array $value, ?string $updatedBy): void
    {
        $this->value = $value;
        $this->updatedBy = $updatedBy;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getUpdatedBy(): ?string
    {
        return $this->updatedBy;
    }
}
