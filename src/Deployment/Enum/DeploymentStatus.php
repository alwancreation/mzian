<?php

declare(strict_types=1);

namespace App\Deployment\Enum;

enum DeploymentStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case RolledBack = 'rolled_back';
}
