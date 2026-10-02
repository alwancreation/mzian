<?php

declare(strict_types=1);

namespace App\Project\Entity;

use App\Catalog\Entity\Solution;
use App\Customer\Entity\Customer;
use App\Lead\Entity\Lead;
use App\Order\Entity\Order;
use App\Order\Entity\Quote;
use App\Project\Enum\CostCategory;
use App\Project\Enum\PipelineStep;
use App\Project\Enum\ProjectStatus;
use App\Project\Repository\ProjectRepository;
use App\Requirement\Entity\Requirement;
use App\Security\Entity\User;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Central aggregate: a customer's project from the quote to the delivered application.
 * Its status is driven exclusively by the "project" state machine.
 */
#[ORM\Entity(repositoryClass: ProjectRepository::class)]
#[ORM\Table(name: 'project')]
#[ORM\Index(name: 'idx_project_status', fields: ['status'])]
#[ORM\Index(name: 'idx_project_created', fields: ['createdAt'])]
#[ORM\HasLifecycleCallbacks]
class Project
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 24, unique: true)]
    private string $reference;

    /** URL/repository-safe identifier, e.g. "mzian-client-42". */
    #[ORM\Column(length: 80, unique: true)]
    private string $slug;

    #[ORM\Column(length: 160)]
    private string $name;

    #[ORM\Column(length: 40, enumType: ProjectStatus::class)]
    private ProjectStatus $status = ProjectStatus::Draft;

    #[ORM\ManyToOne(targetEntity: Lead::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Lead $lead = null;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Customer $customer = null;

    #[ORM\OneToOne(targetEntity: Requirement::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Requirement $requirement;

    #[ORM\ManyToOne(targetEntity: Solution::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Solution $solution = null;

    #[ORM\ManyToOne(targetEntity: Quote::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Quote $currentQuote = null;

    #[ORM\OneToOne(targetEntity: Order::class, mappedBy: 'project')]
    private ?Order $order = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $businessName = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $sector = null;

    #[ORM\Column(length: 5)]
    private string $locale = 'fr';

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $features = [];

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $applicationTemplate = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $complexity = null;

    #[ORM\Column(nullable: true)]
    private ?int $estimatedDevelopmentDays = null;

    #[ORM\Column(length: 10)]
    private string $risk = 'low';

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $riskNotes = [];

    #[ORM\Column(length: 253, nullable: true)]
    private ?string $domainName = null;

    /** Maximum amount the agents may spend on this project (minor units). */
    #[ORM\Column]
    private int $budget = 0;

    #[ORM\Column(length: 3)]
    private string $currency = 'USD';

    #[ORM\Column]
    private bool $automationPaused = false;

    /** Reason of the current WAITING_ADMIN_APPROVAL / FAILED state. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $holdReason = null;

    /** State to come back to after WAITING_ADMIN_APPROVAL / FAILED. */
    #[ORM\Column(length: 40, nullable: true, enumType: ProjectStatus::class)]
    private ?ProjectStatus $resumeStatus = null;

    /** @var array<string, int> spending explicitly approved by an admin above the policy, per cost category */
    #[ORM\Column(type: Types::JSON)]
    private array $spendingOverrides = [];

    /** @var array<string, string> provider code chosen by an admin, per provider type */
    #[ORM\Column(type: Types::JSON)]
    private array $providerOverrides = [];

    /** True when at least one mock provider is involved (nothing real was bought). */
    #[ORM\Column]
    private bool $simulated = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $repositoryUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $deploymentUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adminUrl = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $deliveryDocumentation = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $adminNotes = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $changesRequested = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rejectionReason = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $approvedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deliveredAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /** @var Collection<int, ProjectTask> */
    #[ORM\OneToMany(targetEntity: ProjectTask::class, mappedBy: 'project', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $tasks;

    /** @var Collection<int, ProjectEvent> */
    #[ORM\OneToMany(targetEntity: ProjectEvent::class, mappedBy: 'project', cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'DESC'])]
    private Collection $events;

    /** @var Collection<int, ProjectCostEntry> */
    #[ORM\OneToMany(targetEntity: ProjectCostEntry::class, mappedBy: 'project', cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $costEntries;

    /** @var Collection<int, ProjectCredential> */
    #[ORM\OneToMany(targetEntity: ProjectCredential::class, mappedBy: 'project', cascade: ['persist'], orphanRemoval: true)]
    private Collection $credentials;

    public function __construct(string $reference, string $slug, string $name, Requirement $requirement)
    {
        $this->reference = $reference;
        $this->slug = $slug;
        $this->name = $name;
        $this->requirement = $requirement;
        $this->tasks = new ArrayCollection();
        $this->events = new ArrayCollection();
        $this->costEntries = new ArrayCollection();
        $this->credentials = new ArrayCollection();
        $this->initializeTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /** Used by the Symfony Workflow marking store. Do not call directly: use ProjectStateMachine. */
    public function getStatus(): ProjectStatus
    {
        return $this->status;
    }

    /** Used by the Symfony Workflow marking store. Do not call directly: use ProjectStateMachine. */
    public function setStatus(ProjectStatus $status): void
    {
        $this->status = $status;
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

    public function getRequirement(): Requirement
    {
        return $this->requirement;
    }

    public function getSolution(): ?Solution
    {
        return $this->solution;
    }

    public function setSolution(?Solution $solution): void
    {
        $this->solution = $solution;
    }

    public function getCurrentQuote(): ?Quote
    {
        return $this->currentQuote;
    }

    public function setCurrentQuote(?Quote $quote): void
    {
        $this->currentQuote = $quote;
    }

    public function getOrder(): ?Order
    {
        return $this->order;
    }

    public function setOrder(?Order $order): void
    {
        $this->order = $order;
    }

    public function getBusinessName(): ?string
    {
        return $this->businessName;
    }

    public function setBusinessName(?string $businessName): void
    {
        $this->businessName = $businessName;
    }

    public function getSector(): ?string
    {
        return $this->sector;
    }

    public function setSector(?string $sector): void
    {
        $this->sector = $sector;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    /** @return list<string> */
    public function getFeatures(): array
    {
        return $this->features;
    }

    /**
     * @param list<string> $features
     */
    public function setFeatures(array $features): void
    {
        $this->features = array_values(array_unique($features));
    }

    public function getApplicationTemplate(): ?string
    {
        return $this->applicationTemplate;
    }

    public function setApplicationTemplate(?string $applicationTemplate): void
    {
        $this->applicationTemplate = $applicationTemplate;
    }

    public function getComplexity(): ?string
    {
        return $this->complexity;
    }

    public function setComplexity(?string $complexity): void
    {
        $this->complexity = $complexity;
    }

    public function getEstimatedDevelopmentDays(): ?int
    {
        return $this->estimatedDevelopmentDays;
    }

    public function setEstimatedDevelopmentDays(?int $days): void
    {
        $this->estimatedDevelopmentDays = $days;
    }

    public function getRisk(): string
    {
        return $this->risk;
    }

    /** @return list<string> */
    public function getRiskNotes(): array
    {
        return $this->riskNotes;
    }

    /**
     * @param list<string> $notes
     */
    public function setRisk(string $risk, array $notes): void
    {
        $this->risk = $risk;
        $this->riskNotes = $notes;
    }

    public function getDomainName(): ?string
    {
        return $this->domainName;
    }

    public function setDomainName(?string $domainName): void
    {
        $this->domainName = null !== $domainName ? mb_strtolower(trim($domainName)) : null;
    }

    public function getBudget(): int
    {
        return $this->budget;
    }

    public function setBudget(int $budget): void
    {
        $this->budget = max(0, $budget);
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): void
    {
        $this->currency = $currency;
    }

    public function isAutomationPaused(): bool
    {
        return $this->automationPaused;
    }

    public function pauseAutomation(): void
    {
        $this->automationPaused = true;
    }

    public function resumeAutomation(): void
    {
        $this->automationPaused = false;
    }

    public function getHoldReason(): ?string
    {
        return $this->holdReason;
    }

    public function getResumeStatus(): ?ProjectStatus
    {
        return $this->resumeStatus;
    }

    public function hold(ProjectStatus $resumeStatus, string $reason): void
    {
        $this->resumeStatus = $resumeStatus;
        $this->holdReason = $reason;
    }

    public function clearHold(): void
    {
        $this->resumeStatus = null;
        $this->holdReason = null;
    }

    /** @return array<string, int> */
    public function getSpendingOverrides(): array
    {
        return $this->spendingOverrides;
    }

    public function getSpendingOverride(CostCategory $category): ?int
    {
        return $this->spendingOverrides[$category->value] ?? null;
    }

    public function approveSpending(CostCategory $category, int $amount): void
    {
        $this->spendingOverrides[$category->value] = max($amount, $this->spendingOverrides[$category->value] ?? 0);
    }

    /** @return array<string, string> */
    public function getProviderOverrides(): array
    {
        return $this->providerOverrides;
    }

    public function getProviderOverride(string $type): ?string
    {
        return $this->providerOverrides[$type] ?? null;
    }

    public function setProviderOverride(string $type, ?string $providerCode): void
    {
        if (null === $providerCode) {
            unset($this->providerOverrides[$type]);

            return;
        }
        $this->providerOverrides[$type] = $providerCode;
    }

    public function isSimulated(): bool
    {
        return $this->simulated;
    }

    public function markSimulated(): void
    {
        $this->simulated = true;
    }

    public function getRepositoryUrl(): ?string
    {
        return $this->repositoryUrl;
    }

    public function setRepositoryUrl(?string $repositoryUrl): void
    {
        $this->repositoryUrl = $repositoryUrl;
    }

    public function getDeploymentUrl(): ?string
    {
        return $this->deploymentUrl;
    }

    public function setDeploymentUrl(?string $deploymentUrl): void
    {
        $this->deploymentUrl = $deploymentUrl;
    }

    public function getAdminUrl(): ?string
    {
        return $this->adminUrl;
    }

    public function setAdminUrl(?string $adminUrl): void
    {
        $this->adminUrl = $adminUrl;
    }

    public function getDeliveryDocumentation(): ?string
    {
        return $this->deliveryDocumentation;
    }

    public function setDeliveryDocumentation(?string $documentation): void
    {
        $this->deliveryDocumentation = $documentation;
    }

    public function getAdminNotes(): ?string
    {
        return $this->adminNotes;
    }

    public function setAdminNotes(?string $adminNotes): void
    {
        $this->adminNotes = $adminNotes;
    }

    public function getChangesRequested(): ?string
    {
        return $this->changesRequested;
    }

    public function setChangesRequested(?string $changesRequested): void
    {
        $this->changesRequested = $changesRequested;
    }

    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    public function setRejectionReason(?string $rejectionReason): void
    {
        $this->rejectionReason = $rejectionReason;
    }

    public function getApprovedBy(): ?User
    {
        return $this->approvedBy;
    }

    public function getApprovedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function recordApproval(User $admin): void
    {
        $this->approvedBy = $admin;
        $this->approvedAt = new \DateTimeImmutable();
    }

    public function getDeliveredAt(): ?\DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    public function markDelivered(): void
    {
        $this->deliveredAt = new \DateTimeImmutable();
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function markCompleted(): void
    {
        $this->completedAt = new \DateTimeImmutable();
    }

    /** @return Collection<int, ProjectTask> */
    public function getTasks(): Collection
    {
        return $this->tasks;
    }

    public function getTask(PipelineStep $step): ?ProjectTask
    {
        foreach ($this->tasks as $task) {
            if ($task->getStep() === $step) {
                return $task;
            }
        }

        return null;
    }

    public function addTask(ProjectTask $task): void
    {
        if (!$this->tasks->contains($task)) {
            $this->tasks->add($task);
        }
    }

    /** @return Collection<int, ProjectEvent> */
    public function getEvents(): Collection
    {
        return $this->events;
    }

    public function addEvent(ProjectEvent $event): void
    {
        $this->events->add($event);
    }

    /** @return Collection<int, ProjectCostEntry> */
    public function getCostEntries(): Collection
    {
        return $this->costEntries;
    }

    public function addCostEntry(ProjectCostEntry $entry): void
    {
        $this->costEntries->add($entry);
    }

    public function getSpent(?CostCategory $category = null): int
    {
        $total = 0;
        foreach ($this->costEntries as $entry) {
            if (null === $category || $entry->getCategory() === $category) {
                $total += $entry->getAmount();
            }
        }

        return $total;
    }

    public function getRemainingBudget(): int
    {
        return $this->budget - $this->getSpent();
    }

    /** @return Collection<int, ProjectCredential> */
    public function getCredentials(): Collection
    {
        return $this->credentials;
    }

    public function addCredential(ProjectCredential $credential): void
    {
        if (!$this->credentials->contains($credential)) {
            $this->credentials->add($credential);
        }
    }

    public function getProgress(): int
    {
        if (\in_array($this->status, [ProjectStatus::WaitingAdminApproval, ProjectStatus::Failed], true) && null !== $this->resumeStatus) {
            return $this->resumeStatus->progress();
        }

        return $this->status->progress();
    }
}
