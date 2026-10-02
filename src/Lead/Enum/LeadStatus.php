<?php

declare(strict_types=1);

namespace App\Lead\Enum;

enum LeadStatus: string
{
    case New = 'new';
    case Engaged = 'engaged';
    case Quoted = 'quoted';
    case Converted = 'converted';
    case Lost = 'lost';

    public function badge(): string
    {
        return match ($this) {
            self::New => 'badge-blue',
            self::Engaged => 'badge-violet',
            self::Quoted => 'badge-amber',
            self::Converted => 'badge-green',
            self::Lost => 'badge-gray',
        };
    }
}
