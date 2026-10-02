<?php

declare(strict_types=1);

namespace App\Lead\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'lead_activity')]
#[ORM\Index(name: 'idx_lead_activity_type', fields: ['type'])]
class LeadActivity
{
    public const CREATED = 'created';
    public const QUESTIONNAIRE_STARTED = 'questionnaire_started';
    public const CONVERSATION_STARTED = 'conversation_started';
    public const REQUIREMENT_SUBMITTED = 'requirement_submitted';
    public const QUOTE_ISSUED = 'quote_issued';
    public const ORDER_CREATED = 'order_created';
    public const PAYMENT_RECEIVED = 'payment_received';
    public const PROJECT_DELIVERED = 'project_delivered';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Lead::class, inversedBy: 'activities')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Lead $lead;

    #[ORM\Column(length: 50)]
    private string $type;

    #[ORM\Column(length: 255)]
    private string $description;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $metadata;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(Lead $lead, string $type, string $description, array $metadata = [])
    {
        $this->lead = $lead;
        $this->type = $type;
        $this->description = mb_substr($description, 0, 255);
        $this->metadata = $metadata;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLead(): Lead
    {
        return $this->lead;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    /** @return array<string, mixed> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
