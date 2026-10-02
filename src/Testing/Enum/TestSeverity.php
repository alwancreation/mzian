<?php

declare(strict_types=1);

namespace App\Testing\Enum;

enum TestSeverity: string
{
    /** A failure blocks the delivery. */
    case Critical = 'critical';
    /** A failure is reported as a warning. */
    case Major = 'major';
    case Minor = 'minor';
}
