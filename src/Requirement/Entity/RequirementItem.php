<?php

declare(strict_types=1);

namespace App\Requirement\Entity;

use App\Requirement\Enum\RequirementItemSource;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'requirement_item')]
#[ORM\UniqueConstraint(name: 'uniq_requirement_item_key', fields: ['requirement', 'key'])]
class RequirementItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Requirement::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Requirement $requirement;

    #[ORM\Column(name: 'item_key', length: 80)]
    private string $key;

    #[ORM\Column(length: 255)]
    private string $label;

    /** @var array{v: mixed} arbitrary JSON scalar/array, wrapped to keep a consistent JSON document */
    #[ORM\Column(name: 'item_value', type: Types::JSON)]
    private array $value;

    #[ORM\Column(length: 20, enumType: RequirementItemSource::class)]
    private RequirementItemSource $source;

    #[ORM\Column(nullable: true)]
    private ?float $confidence;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Requirement $requirement, string $key, string $label, mixed $value, RequirementItemSource $source, ?float $confidence = null)
    {
        $this->requirement = $requirement;
        $this->key = $key;
        $this->label = mb_substr($label, 0, 255);
        $this->value = ['v' => $value];
        $this->source = $source;
        $this->confidence = $confidence;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRequirement(): Requirement
    {
        return $this->requirement;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getValue(): mixed
    {
        return $this->value['v'] ?? null;
    }

    public function getDisplayValue(): string
    {
        $value = $this->getValue();

        return match (true) {
            \is_bool($value) => $value ? 'yes' : 'no',
            \is_array($value) => implode(', ', array_map('strval', $value)),
            null === $value => '—',
            default => (string) $value,
        };
    }

    public function getSource(): RequirementItemSource
    {
        return $this->source;
    }

    public function getConfidence(): ?float
    {
        return $this->confidence;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function update(string $label, mixed $value, RequirementItemSource $source, ?float $confidence): void
    {
        $this->label = mb_substr($label, 0, 255);
        $this->value = ['v' => $value];
        $this->source = $source;
        $this->confidence = $confidence;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
