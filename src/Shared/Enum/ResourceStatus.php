<?php

declare(strict_types=1);

namespace App\Shared\Enum;

/**
 * Lifecycle of an external resource (hosting account, hosting site...).
 */
enum ResourceStatus: string
{
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Failed = 'failed';
    case Suspended = 'suspended';
    case Deleted = 'deleted';
}
