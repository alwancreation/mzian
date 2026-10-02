<?php

declare(strict_types=1);

namespace App\Agent\Enum;

enum AgentRunStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Retrying = 'retrying';
    case WaitingAdmin = 'waiting_admin';

    public function badge(): string
    {
        return match ($this) {
            self::Running => 'badge-violet',
            self::Succeeded => 'badge-green',
            self::Failed => 'badge-red',
            self::Retrying, self::WaitingAdmin => 'badge-amber',
        };
    }
}
