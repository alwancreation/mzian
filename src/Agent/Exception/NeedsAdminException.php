<?php

declare(strict_types=1);

namespace App\Agent\Exception;

/**
 * The agent must stop and wait for a human decision (budget above the
 * automation rules, domain to choose, custom work...): WAITING_ADMIN_APPROVAL.
 */
final class NeedsAdminException extends AgentException
{
    /**
     * @param array<string, mixed> $metadata e.g. {"spending": {"category": "hosting", "amount": 12000}}
     */
    public function __construct(string $message, array $metadata = [])
    {
        parent::__construct($message, false, $metadata);
    }
}
