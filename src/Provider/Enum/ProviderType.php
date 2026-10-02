<?php

declare(strict_types=1);

namespace App\Provider\Enum;

enum ProviderType: string
{
    case Hosting = 'hosting';
    case Domain = 'domain';
    case Payment = 'payment';
    case Ai = 'ai';
    case Deployment = 'deployment';
    case Repository = 'repository';
    case Notification = 'notification';
}
