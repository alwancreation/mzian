<?php

declare(strict_types=1);

namespace App\Tests\Unit\I18n;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Every key of the French (reference) catalogues must exist in English and Arabic.
 */
final class TranslationCompletenessTest extends TestCase
{
    public function testAllLocalesHaveTheSameKeys(): void
    {
        $dir = \dirname(__DIR__, 3).'/translations';
        foreach (glob($dir.'/*.fr.yaml') ?: [] as $reference) {
            $domain = basename($reference, '.fr.yaml');
            $expected = self::flatten(Yaml::parseFile($reference));
            foreach (['en', 'ar'] as $locale) {
                $file = \sprintf('%s/%s.%s.yaml', $dir, $domain, $locale);
                self::assertFileExists($file);
                $actual = self::flatten(Yaml::parseFile($file));
                self::assertSame([], array_values(array_diff(array_keys($expected), array_keys($actual))), "Missing keys in {$domain}.{$locale}");
                self::assertSame([], array_values(array_diff(array_keys($actual), array_keys($expected))), "Extra keys in {$domain}.{$locale}");
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $path = '' === $prefix ? (string) $key : $prefix.'.'.$key;
            if (\is_array($value)) {
                $result += self::flatten($value, $path);
            } else {
                $result[$path] = (string) $value;
            }
        }

        return $result;
    }
}
