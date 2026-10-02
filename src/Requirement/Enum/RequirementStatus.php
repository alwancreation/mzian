<?php

declare(strict_types=1);

namespace App\Requirement\Enum;

enum RequirementStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Analyzed = 'analyzed';
    case Failed = 'failed';
}
