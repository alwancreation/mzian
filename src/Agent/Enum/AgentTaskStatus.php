<?php

declare(strict_types=1);

namespace App\Agent\Enum;

enum AgentTaskStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
