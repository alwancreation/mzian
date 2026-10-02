<?php

declare(strict_types=1);

namespace App\Agent\Exception;

/**
 * An agent operation failed. Transient = retried automatically (up to the
 * agent's max attempts, then an administrator decides); permanent = FAILED.
 */
class AgentException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(string $message, public readonly bool $retryable, public readonly array $metadata = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function transient(string $message, array $metadata = [], ?\Throwable $previous = null): self
    {
        return new self($message, true, $metadata, $previous);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function permanent(string $message, array $metadata = [], ?\Throwable $previous = null): self
    {
        return new self($message, false, $metadata, $previous);
    }
}
