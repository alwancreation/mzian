<?php

declare(strict_types=1);

namespace App\Order\Enum;

enum QuoteStatus: string
{
    case Issued = 'issued';
    case Accepted = 'accepted';
    case Superseded = 'superseded';
    case Expired = 'expired';
}
