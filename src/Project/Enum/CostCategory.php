<?php

declare(strict_types=1);

namespace App\Project\Enum;

enum CostCategory: string
{
    case Hosting = 'hosting';
    case Domain = 'domain';
    case Email = 'email';
    case Ai = 'ai';
    case Infrastructure = 'infrastructure';
    case Development = 'development';
    case PaymentFees = 'payment_fees';
    case Other = 'other';
}
