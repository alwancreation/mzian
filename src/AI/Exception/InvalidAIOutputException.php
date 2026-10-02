<?php

declare(strict_types=1);

namespace App\AI\Exception;

/**
 * The model answered, but not with data we can trust (not JSON, schema violation,
 * unknown catalog reference...). The output is rejected, never used as-is.
 */
final class InvalidAIOutputException extends AIException
{
    /**
     * @param list<string> $errors
     */
    public function __construct(string $message, public readonly array $errors = [], ?string $provider = null)
    {
        parent::__construct($message.([] !== $errors ? ': '.implode('; ', \array_slice($errors, 0, 5)) : ''), false, $provider);
    }
}
