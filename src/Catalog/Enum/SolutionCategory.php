<?php

declare(strict_types=1);

namespace App\Catalog\Enum;

enum SolutionCategory: string
{
    case Website = 'website';
    case Ecommerce = 'ecommerce';
    case Booking = 'booking';
    case Management = 'management';
    case Crm = 'crm';
    case Custom = 'custom';
}
