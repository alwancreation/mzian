<?php

declare(strict_types=1);

namespace App\Agent\Message;

/**
 * A job of the automation pipeline, handled asynchronously by the workers
 * (routed to the "async" transport). Only identifiers travel in the queue:
 * the handler reloads the project, so a retried or duplicated message is safe.
 */
abstract class AbstractPipelineMessage
{
    final public function __construct(
        public readonly int $projectId,
        public readonly int $attempt = 1,
    ) {
    }

    /** Operation performed by the agent (also the idempotency key suffix). */
    abstract public static function operation(): string;

    public function retry(): static
    {
        return new static($this->projectId, $this->attempt + 1);
    }
}
