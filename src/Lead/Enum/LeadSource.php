<?php

declare(strict_types=1);

namespace App\Lead\Enum;

enum LeadSource: string
{
    case Google = 'google';
    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case TikTok = 'tiktok';
    case Direct = 'direct';
    case Referral = 'referral';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google',
            self::Facebook => 'Facebook',
            self::Instagram => 'Instagram',
            self::TikTok => 'TikTok',
            self::Direct => 'Direct',
            self::Referral => 'Referral',
            self::Other => 'Other',
        };
    }
}
