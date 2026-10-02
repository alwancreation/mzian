<?php

declare(strict_types=1);

namespace App\Pricing;

enum PriceLineType: string
{
    case Development = 'development';
    case Feature = 'feature';
    case Hosting = 'hosting';
    case Domain = 'domain';
    case Email = 'email';
    case Infrastructure = 'infrastructure';
    case Ai = 'ai';
    case PaymentFees = 'payment_fees';
    case Margin = 'margin';
    case Maintenance = 'maintenance';
    case Subscription = 'subscription';
}
