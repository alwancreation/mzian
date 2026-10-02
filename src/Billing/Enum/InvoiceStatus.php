<?php

declare(strict_types=1);

namespace App\Billing\Enum;

enum InvoiceStatus: string
{
    case Issued = 'issued';
    case Paid = 'paid';
    case Void = 'void';
}
