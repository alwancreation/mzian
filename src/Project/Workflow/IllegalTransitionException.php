<?php

declare(strict_types=1);

namespace App\Project\Workflow;

/**
 * A transition was refused (wrong state, actor not allowed, precondition not met).
 */
final class IllegalTransitionException extends \DomainException
{
    /**
     * @param list<string> $reasons
     */
    public function __construct(
        public readonly string $transition,
        public readonly string $fromStatus,
        public readonly array $reasons = [],
    ) {
        parent::__construct(\sprintf('Transition "%s" is not allowed from %s%s', $transition, $fromStatus, [] !== $reasons ? ': '.implode(' ', $reasons) : '.'));
    }
}
