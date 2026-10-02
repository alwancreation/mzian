<?php

declare(strict_types=1);

namespace App\Requirement\Entity;

use App\Customer\Entity\Customer;
use App\Lead\Entity\Lead;
use App\Requirement\Enum\MessageRole;
use App\Requirement\Repository\ConversationRepository;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * "Describe your project" chat between a visitor/customer and the AI assistant.
 * The public token is the only identifier exposed to the browser.
 */
#[ORM\Entity(repositoryClass: ConversationRepository::class)]
#[ORM\Table(name: 'conversation')]
#[ORM\HasLifecycleCallbacks]
class Conversation
{
    use TimestampableTrait;

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

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

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $sector = null;

    #[ORM\Column(length: 5)]
    private string $locale;

    #[ORM\Column(length: 10)]
    private string $status = self::STATUS_OPEN;

    /** @var array<string, mixed> assistant memory, e.g. {"pending_question": "pdf_contracts"} */
    #[ORM\Column(type: Types::JSON)]
    private array $context = [];

    /** @var Collection<int, ConversationMessage> */
    #[ORM\OneToMany(targetEntity: ConversationMessage::class, mappedBy: 'conversation', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => \SortDirection::Ascending])]
    private Collection $messages;

    public function __construct(string $locale = 'fr')
    {
        $this->token = bin2hex(random_bytes(16));
        $this->locale = $locale;
        $this->messages = new ArrayCollection();
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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function close(): void
    {
        $this->status = self::STATUS_CLOSED;
    }

    public function isOpen(): bool
    {
        return self::STATUS_OPEN === $this->status;
    }

    /** @return array<string, mixed> */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function setContext(array $context): void
    {
        $this->context = $context;
    }

    /** @return Collection<int, ConversationMessage> */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function addMessage(MessageRole $role, string $content, array $metadata = []): ConversationMessage
    {
        $message = new ConversationMessage($this, $role, $content, $metadata);
        $this->messages->add($message);
        $this->touch();

        return $message;
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    public function transcript(int $limit = 30): array
    {
        $messages = array_slice($this->messages->toArray(), -$limit);

        return array_values(array_map(static fn (ConversationMessage $m) => [
            'role' => $m->getRole()->value,
            'content' => $m->getContent(),
        ], $messages));
    }

    public function countUserMessages(): int
    {
        return $this->messages->filter(static fn (ConversationMessage $m) => MessageRole::User === $m->getRole())->count();
    }
}
