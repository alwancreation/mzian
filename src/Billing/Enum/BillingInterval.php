<?php

declare(strict_types=1);

namespace App\Billing\Enum;

enum BillingInterval: string
{
    case Month = 'month';
    case Year = 'year';

    public function months(): int
    {
        return self::Year === $this ? 12 : 1;
    }
}
