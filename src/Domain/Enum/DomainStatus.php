<?php

declare(strict_types=1);

namespace App\Domain\Enum;

enum DomainStatus: string
{
    case Pending = 'pending';
    case Registered = 'registered';
    case Active = 'active';
    case External = 'external';
    case Failed = 'failed';
    case Expired = 'expired';
}
