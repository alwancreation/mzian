<?php

declare(strict_types=1);

namespace App\Requirement\Entity;

use App\Catalog\Entity\Solution;
use App\Customer\Entity\Customer;
use App\Lead\Entity\Lead;
use App\Requirement\Enum\RequirementItemSource;
use App\Requirement\Enum\RequirementStatus;
use App\Requirement\Repository\RequirementRepository;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Structured description of what the customer needs: sector, free description,
 * questionnaire answers and conversation-extracted items, plus the validated AI analysis.
 */
#[ORM\Entity(repositoryClass: RequirementRepository::class)]
#[ORM\Table(name: 'requirement')]
#[ORM\Index(name: 'idx_requirement_status', fields: ['status'])]
#[ORM\HasLifecycleCallbacks]
class Requirement
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, unique: true)]
    private string $token;

    #[ORM\ManyToOne(targetEntity: Lead::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Lead $lead = null;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Customer $customer = null;

    #[ORM\OneToOne(targetEntity: Conversation::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Conversation $conversation = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $sector = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $businessName = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** @var array<string, mixed> questionnaire answers keyed by question code */
    #[ORM\Column(type: Types::JSON)]
    private array $answers = [];

    #[ORM\Column(length: 5)]
    private string $locale;

    #[ORM\Column(length: 12, enumType: RequirementStatus::class)]
    private RequirementStatus $status = RequirementStatus::Draft;

    /** @var array<string, mixed>|null validated RequirementAnalysis (see App\AI\Analysis) */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $analysis = null;

    #[ORM\ManyToOne(targetEntity: Solution::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Solution $recommendedSolution = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $analyzedAt = null;

    /** @var Collection<int, RequirementItem> */
    #[ORM\OneToMany(targetEntity: RequirementItem::class, mappedBy: 'requirement', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => \SortDirection::Ascending])]
    private Collection $items;

    public function __construct(string $locale = 'fr')
    {
        $this->token = bin2hex(random_bytes(16));
        $this->locale = $locale;
        $this->items = new ArrayCollection();
        $this->initializeTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getLead(): ?Lead
    {
        return $this->lead;
    }

    public function setLead(?Lead $lead): void
    {
        $this->lead = $lead;
    }

    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function setCustomer(?Customer $customer): void
    {
        $this->customer = $customer;
    }

    public function getConversation(): ?Conversation
    {
        return $this->conversation;
    }

    public function setConversation(?Conversation $conversation): void
    {
        $this->conversation = $conversation;
    }

    public function getSector(): ?string
    {
        return $this->sector;
    }

    public function setSector(?string $sector): void
    {
        $this->sector = $sector;
    }

    public function getBusinessName(): ?string
    {
        return $this->businessName;
    }

    public function setBusinessName(?string $businessName): void
    {
        $this->businessName = $businessName;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): void
    {
        $this->city = $city;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    /** @return array<string, mixed> */
    public function getAnswers(): array
    {
        return $this->answers;
    }

    public function setAnswer(string $questionCode, mixed $value): void
    {
        $this->answers[$questionCode] = $value;
        $this->touch();
    }

    public function removeAnswer(string $questionCode): void
    {
        unset($this->answers[$questionCode]);
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    public function getStatus(): RequirementStatus
    {
        return $this->status;
    }

    public function markSubmitted(): void
    {
        $this->status = RequirementStatus::Submitted;
    }

    public function markFailed(): void
    {
        $this->status = RequirementStatus::Failed;
    }

    /** @return array<string, mixed>|null */
    public function getAnalysis(): ?array
    {
        return $this->analysis;
    }

    /**
     * @param array<string, mixed> $analysis
     */
    public function recordAnalysis(array $analysis, ?Solution $solution): void
    {
        $this->analysis = $analysis;
        $this->recommendedSolution = $solution;
        $this->status = RequirementStatus::Analyzed;
        $this->analyzedAt = new \DateTimeImmutable();
    }

    public function getRecommendedSolution(): ?Solution
    {
        return $this->recommendedSolution;
    }

    public function getAnalyzedAt(): ?\DateTimeImmutable
    {
        return $this->analyzedAt;
    }

    /** @return Collection<int, RequirementItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function getItem(string $key): ?RequirementItem
    {
        foreach ($this->items as $item) {
            if ($item->getKey() === $key) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Inserts or updates a structured requirement item (idempotent per key).
     */
    public function upsertItem(string $key, string $label, mixed $value, RequirementItemSource $source, ?float $confidence = null): RequirementItem
    {
        $item = $this->getItem($key);
        if (null === $item) {
            $item = new RequirementItem($this, $key, $label, $value, $source, $confidence);
            $this->items->add($item);
        } else {
            $item->update($label, $value, $source, $confidence);
        }
        $this->touch();

        return $item;
    }

    /**
     * Flat key => value view (answers + items), the input of the analyzer.
     *
     * @return array<string, mixed>
     */
    public function collectSignals(): array
    {
        $signals = $this->answers;
        foreach ($this->items as $item) {
            $signals[$item->getKey()] ??= $item->getValue();
        }

        return $signals;
    }
}
