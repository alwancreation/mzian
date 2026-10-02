<?php

declare(strict_types=1);

namespace App\Project\Entity;

use App\Project\Enum\ProjectStatus;
use App\Shared\Enum\ActorType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Timeline of a project: every state transition, admin action and agent event.
 */
#[ORM\Entity]
#[ORM\Table(name: 'project_event')]
#[ORM\Index(name: 'idx_project_event_created', fields: ['createdAt'])]
class ProjectEvent
{
    public const TYPE_TRANSITION = 'transition';
    public const TYPE_ADMIN_ACTION = 'admin_action';
    public const TYPE_AGENT = 'agent';
    public const TYPE_SYSTEM = 'system';
    public const TYPE_CUSTOMER = 'customer';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'events')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\Column(length: 20)]
    private string $type;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $transition;

    #[ORM\Column(length: 40, nullable: true, enumType: ProjectStatus::class)]
    private ?ProjectStatus $fromStatus;

    #[ORM\Column(length: 40, nullable: true, enumType: ProjectStatus::class)]
    private ?ProjectStatus $toStatus;

    #[ORM\Column(length: 20, enumType: ActorType::class)]
    private ActorType $actorType;

    #[ORM\Column(length: 120)]
    private string $actorName;

    #[ORM\Column(length: 500)]
    private string $message;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $metadata;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        Project $project,
        string $type,
        ActorType $actorType,
        string $actorName,
        string $message,
        ?string $transition = null,
        ?ProjectStatus $fromStatus = null,
        ?ProjectStatus $toStatus = null,
        array $metadata = [],
    ) {
        $this->project = $project;
        $this->type = $type;
        $this->actorType = $actorType;
        $this->actorName = mb_substr($actorName, 0, 120);
        $this->message = mb_substr($message, 0, 500);
        $this->transition = $transition;
        $this->fromStatus = $fromStatus;
        $this->toStatus = $toStatus;
        $this->metadata = $metadata;
        $this->createdAt = new \DateTimeImmutable();
        $project->addEvent($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProject(): Project
    {
        return $this->project;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTransition(): ?string
    {
        return $this->transition;
    }

    public function getFromStatus(): ?ProjectStatus
    {
        return $this->fromStatus;
    }

    public function getToStatus(): ?ProjectStatus
    {
        return $this->toStatus;
    }

    public function getActorType(): ActorType
    {
        return $this->actorType;
    }

    public function getActorName(): string
    {
        return $this->actorName;
    }

    public function getMessage(): string
    {
        return $this->message;
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
