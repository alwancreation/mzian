<?php

declare(strict_types=1);

namespace App\Tests\Unit\Lead;

use App\Lead\Enum\LeadSource;
use App\Lead\Service\LeadSourceDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LeadSourceDetectorTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, string>, ?string, LeadSource}>
     */
    public static function cases(): iterable
    {
        yield 'utm google' => [['utm_source' => 'google'], null, LeadSource::Google];
        yield 'gclid' => [['gclid' => 'abc'], null, LeadSource::Google];
        yield 'utm facebook' => [['utm_source' => 'facebook_ads'], null, LeadSource::Facebook];
        yield 'utm instagram' => [['utm_source' => 'Instagram'], null, LeadSource::Instagram];
        yield 'utm tiktok' => [['utm_source' => 'tiktok'], null, LeadSource::TikTok];
        yield 'utm unknown' => [['utm_source' => 'newsletter'], null, LeadSource::Other];
        yield 'referrer google' => [[], 'https://www.google.co.ma/search?q=site', LeadSource::Google];
        yield 'referrer instagram' => [[], 'https://l.instagram.com/', LeadSource::Instagram];
        yield 'referrer other site' => [[], 'https://blog.example.org/post', LeadSource::Referral];
        yield 'own site' => [[], 'https://mzian.net/fr/', LeadSource::Direct];
        yield 'nothing' => [[], null, LeadSource::Direct];
    }

    /**
     * @param array<string, string> $utm
     */
    #[DataProvider('cases')]
    public function testDetection(array $utm, ?string $referrer, LeadSource $expected): void
    {
        self::assertSame($expected, (new LeadSourceDetector())->detect($utm, $referrer, 'mzian.net'));
    }
}
