<?php

declare(strict_types=1);

namespace App\Catalog\Entity;

use App\Catalog\Enum\QuestionType;
use App\Catalog\Repository\QuestionRepository;
use App\Shared\I18n\LocalizedText;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A question of the dynamic questionnaire. Questions are data (YAML import or admin),
 * so the questionnaire can be enriched without changing code.
 *
 * - sector = null means the question is asked for every sector.
 * - condition is a Symfony ExpressionLanguage expression evaluated against the
 *   answers given so far, e.g. "answers['contracts'] == 'yes'".
 * - options: [{"value": "yes", "label": {"fr": "Oui"}, "features": ["contracts"]}]
 */
#[ORM\Entity(repositoryClass: QuestionRepository::class)]
#[ORM\Table(name: 'question')]
#[ORM\UniqueConstraint(name: 'uniq_question_sector_code', fields: ['sector', 'code'])]
#[ORM\Index(name: 'idx_question_position', fields: ['position'])]
class Question
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80)]
    private string $code;

    #[ORM\ManyToOne(targetEntity: Sector::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Sector $sector;

    #[ORM\Column(length: 20, enumType: QuestionType::class)]
    private QuestionType $type;

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $label;

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $help = [];

    /** @var list<array{value: string, label: array<string, string>, features?: list<string>}> */
    #[ORM\Column(type: Types::JSON)]
    private array $options = [];

    #[ORM\Column(name: 'condition_expression', length: 500, nullable: true)]
    private ?string $condition = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $required = true;

    #[ORM\Column]
    private bool $enabled = true;

    /**
     * @param array<string, string> $label
     */
    public function __construct(string $code, ?Sector $sector, QuestionType $type, array $label)
    {
        $this->code = $code;
        $this->sector = $sector;
        $this->type = $type;
        $this->label = $label;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getSector(): ?Sector
    {
        return $this->sector;
    }

    public function getType(): QuestionType
    {
        return $this->type;
    }

    public function getLabel(?string $locale = null): string
    {
        return LocalizedText::pick($this->label, $locale);
    }

    public function getHelp(?string $locale = null): string
    {
        return LocalizedText::pick($this->help, $locale);
    }

    /**
     * @return list<array{value: string, label: array<string, string>, features?: list<string>}>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @return list<array{value: string, label: string, features: list<string>}>
     */
    public function getLocalizedOptions(?string $locale = null): array
    {
        return array_map(static fn (array $o) => [
            'value' => (string) $o['value'],
            'label' => LocalizedText::pick($o['label'], $locale),
            'features' => $o['features'] ?? [],
        ], $this->options);
    }

    /**
     * Feature codes implied by an answer (e.g. "yes" to "contracts" => ["contracts"]).
     *
     * @return list<string>
     */
    public function featuresFor(mixed $answer): array
    {
        $values = \is_array($answer) ? array_map('strval', $answer) : [(string) $answer];
        $features = [];
        foreach ($this->options as $option) {
            if (\in_array((string) $option['value'], $values, true)) {
                array_push($features, ...($option['features'] ?? []));
            }
        }

        return array_values(array_unique($features));
    }

    public function hasOption(string $value): bool
    {
        foreach ($this->options as $option) {
            if ((string) $option['value'] === $value) {
                return true;
            }
        }

        return false;
    }

    public function getCondition(): ?string
    {
        return $this->condition;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function isRequired(): bool
    {
        return $this->required;
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
     * @param array<string, string>                                                             $label
     * @param array<string, string>                                                             $help
     * @param list<array{value: string, label: array<string, string>, features?: list<string>}> $options
     */
    public function update(QuestionType $type, array $label, array $help, array $options, ?string $condition, int $position, bool $required): void
    {
        $this->type = $type;
        $this->label = $label;
        $this->help = $help;
        $this->options = $options;
        $this->condition = $condition ?: null;
        $this->position = $position;
        $this->required = $required;
    }
}
