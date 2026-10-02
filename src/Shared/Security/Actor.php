<?php

declare(strict_types=1);

namespace App\Shared\Security;

use App\Shared\Enum\ActorType;

/**
 * Immutable description of who performs an operation (used by audit logs,
 * project events and state machine guards).
 */
final readonly class Actor
{
    public function __construct(
        public ActorType $type,
        public ?string $id = null,
        public string $name = 'system',
    ) {
    }

    public static function system(string $name = 'system'): self
    {
        return new self(ActorType::System, null, $name);
    }

    public static function agent(string $agentCode): self
    {
        return new self(ActorType::Agent, $agentCode, $agentCode.'_agent');
    }

    public static function admin(int|string $userId, string $name): self
    {
        return new self(ActorType::Admin, (string) $userId, $name);
    }

    public static function customer(int|string $userId, string $name): self
    {
        return new self(ActorType::Customer, (string) $userId, $name);
    }

    public static function visitor(): self
    {
        return new self(ActorType::Visitor, null, 'visitor');
    }

    public static function webhook(string $provider): self
    {
        return new self(ActorType::Webhook, $provider, $provider.'_webhook');
    }

    public function isAdmin(): bool
    {
        return ActorType::Admin === $this->type;
    }

    public function isAgent(): bool
    {
        return ActorType::Agent === $this->type;
    }

    public function label(): string
    {
        return \sprintf('%s:%s', $this->type->value, $this->name);
    }
}
