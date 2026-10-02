<?php

declare(strict_types=1);

namespace App\Provider\Enum;

enum ProvisioningMethod: string
{
    case Api = 'api';
    case Manual = 'manual';
    case Mock = 'mock';
}
