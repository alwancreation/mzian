<?php

declare(strict_types=1);

namespace App\Lead\Service;

use App\Lead\Enum\LeadSource;

/**
 * Maps utm_source / referrer host to a LeadSource (Google, Facebook, Instagram, TikTok...).
 */
final class LeadSourceDetector
{
    private const RULES = [
        'google' => LeadSource::Google,
        'gclid' => LeadSource::Google,
        'facebook' => LeadSource::Facebook,
        'fb.' => LeadSource::Facebook,
        'fbclid' => LeadSource::Facebook,
        'instagram' => LeadSource::Instagram,
        'ig' => LeadSource::Instagram,
        'tiktok' => LeadSource::TikTok,
    ];

    /**
     * @param array<string, string> $utm
     */
    public function detect(array $utm, ?string $referrer, ?string $ownHost = null): LeadSource
    {
        $utmSource = mb_strtolower($utm['utm_source'] ?? '');
        if ('' !== $utmSource) {
            foreach (self::RULES as $needle => $source) {
                if ($utmSource === $needle || str_contains($utmSource, $needle)) {
                    return $source;
                }
            }
            if (\in_array($utmSource, ['referral', 'partner', 'affiliate'], true)) {
                return LeadSource::Referral;
            }

            return LeadSource::Other;
        }
        if (isset($utm['gclid'])) {
            return LeadSource::Google;
        }
        if (isset($utm['fbclid'])) {
            return LeadSource::Facebook;
        }

        $host = null !== $referrer ? mb_strtolower((string) parse_url($referrer, \PHP_URL_HOST)) : '';
        if ('' === $host || (null !== $ownHost && str_ends_with($host, mb_strtolower($ownHost)))) {
            return LeadSource::Direct;
        }
        foreach (['google.' => LeadSource::Google, 'facebook.' => LeadSource::Facebook, 'fb.' => LeadSource::Facebook, 'instagram.' => LeadSource::Instagram, 'tiktok.' => LeadSource::TikTok] as $needle => $source) {
            if (str_contains($host, $needle)) {
                return $source;
            }
        }

        return LeadSource::Referral;
    }
}
