<?php

declare(strict_types=1);

namespace App\Requirement\Entity;

use App\Requirement\Enum\MessageRole;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'conversation_message')]
class ConversationMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Conversation::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Conversation $conversation;

    #[ORM\Column(length: 12, enumType: MessageRole::class)]
    private MessageRole $role;

    #[ORM\Column(type: Types::TEXT)]
    private string $content;

    /** @var array<string, mixed> provider, model, token usage... */
    #[ORM\Column(type: Types::JSON)]
    private array $metadata;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(Conversation $conversation, MessageRole $role, string $content, array $metadata = [])
    {
        $this->conversation = $conversation;
        $this->role = $role;
        $this->content = $content;
        $this->metadata = $metadata;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConversation(): Conversation
    {
        return $this->conversation;
    }

    public function getRole(): MessageRole
    {
        return $this->role;
    }

    public function getContent(): string
    {
        return $this->content;
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
