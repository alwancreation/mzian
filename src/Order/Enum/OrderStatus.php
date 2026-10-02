<?php

declare(strict_types=1);

namespace App\Order\Enum;

enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    public function isPayable(): bool
    {
        return self::PendingPayment === $this;
    }

    public function badge(): string
    {
        return match ($this) {
            self::PendingPayment => 'badge-amber',
            self::Paid => 'badge-green',
            self::Cancelled => 'badge-gray',
            self::Refunded => 'badge-red',
        };
    }
}
