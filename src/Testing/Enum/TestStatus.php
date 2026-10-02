<?php

declare(strict_types=1);

namespace App\Testing\Enum;

enum TestStatus: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case Warning = 'warning';
    case Skipped = 'skipped';

    public function badge(): string
    {
        return match ($this) {
            self::Passed => 'badge-green',
            self::Failed => 'badge-red',
            self::Warning => 'badge-amber',
            self::Skipped => 'badge-gray',
        };
    }
}
