<?php

declare(strict_types=1);

namespace App\Provider\Exception;

/**
 * Error raised by an external provider.
 * "retryable" = transient (timeout, 5xx, rate limit): the job may be retried safely.
 */
class ProviderException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = true,
        public readonly ?string $provider = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function permanent(string $message, ?string $provider = null, ?\Throwable $previous = null): self
    {
        return new self($message, false, $provider, $previous);
    }

    public static function transient(string $message, ?string $provider = null, ?\Throwable $previous = null): self
    {
        return new self($message, true, $provider, $previous);
    }
}
