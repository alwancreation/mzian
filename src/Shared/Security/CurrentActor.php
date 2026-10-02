<?php

declare(strict_types=1);

namespace App\Shared\Security;

use App\Security\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Resolves "who is acting right now": the authenticated human (admin/customer),
 * or an explicit actor set by background code (agents, webhooks, system jobs).
 */
final class CurrentActor
{
    /** @var list<Actor> */
    private array $stack = [];

    public function __construct(private readonly Security $security)
    {
    }

    public function get(): Actor
    {
        if ([] !== $this->stack) {
            return $this->stack[array_key_last($this->stack)];
        }

        $user = $this->security->getUser();
        if ($user instanceof User) {
            return $user->isAdmin()
                ? Actor::admin((int) $user->getId(), $user->getFullName())
                : Actor::customer((int) $user->getId(), $user->getFullName());
        }

        return \PHP_SAPI === 'cli' ? Actor::system() : Actor::visitor();
    }

    /**
     * Runs $callback with $actor as current actor (nested calls are supported).
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function runAs(Actor $actor, callable $callback): mixed
    {
        $this->stack[] = $actor;
        try {
            return $callback();
        } finally {
            array_pop($this->stack);
        }
    }

    public function user(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }
}
