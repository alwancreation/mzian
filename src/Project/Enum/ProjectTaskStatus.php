<?php

declare(strict_types=1);

namespace App\Project\Enum;

enum ProjectTaskStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case WaitingAdmin = 'waiting_admin';
    case Skipped = 'skipped';
    case Cancelled = 'cancelled';

    public function isDone(): bool
    {
        return self::Succeeded === $this || self::Skipped === $this;
    }

    public function badge(): string
    {
        return match ($this) {
            self::Pending => 'badge-gray',
            self::Running => 'badge-violet',
            self::Succeeded => 'badge-green',
            self::Failed => 'badge-red',
            self::WaitingAdmin => 'badge-amber',
            self::Skipped, self::Cancelled => 'badge-gray',
        };
    }
}
