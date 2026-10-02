<?php

declare(strict_types=1);

namespace App\Billing\Enum;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case RequiresAction = 'requires_action';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case Cancelled = 'cancelled';

    public function isFinal(): bool
    {
        return \in_array($this, [self::Succeeded, self::Failed, self::Refunded, self::Cancelled], true);
    }

    public function badge(): string
    {
        return match ($this) {
            self::Pending, self::RequiresAction => 'badge-amber',
            self::Succeeded => 'badge-green',
            self::Failed => 'badge-red',
            self::Refunded, self::Cancelled => 'badge-gray',
        };
    }
}
