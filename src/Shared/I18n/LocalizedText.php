<?php

declare(strict_types=1);

namespace App\Shared\I18n;

/**
 * Helpers for database content stored as {"fr": "...", "en": "...", "ar": "..."}.
 */
final class LocalizedText
{
    public const FALLBACK_LOCALE = 'fr';

    private function __construct()
    {
    }

    /**
     * @param array<string, string>|string|null $values
     */
    public static function pick(array|string|null $values, ?string $locale = null): string
    {
        if (null === $values) {
            return '';
        }
        if (\is_string($values)) {
            return $values;
        }

        $locale ??= self::FALLBACK_LOCALE;

        return $values[$locale] ?? $values[self::FALLBACK_LOCALE] ?? (string) (reset($values) ?: '');
    }

    /**
     * @param array<string, string>|string $values
     *
     * @return array<string, string>
     */
    public static function normalize(array|string $values): array
    {
        return \is_string($values) ? [self::FALLBACK_LOCALE => $values] : $values;
    }
}
