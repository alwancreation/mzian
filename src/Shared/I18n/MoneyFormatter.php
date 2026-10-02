<?php

declare(strict_types=1);

namespace App\Shared\I18n;

/**
 * Formats amounts stored in minor units: format(23700, 'USD', 'fr') => "237,00 $US".
 */
final class MoneyFormatter
{
    public static function format(int $amount, string $currency, string $locale, bool $decimals = true): string
    {
        $formatter = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
        if (!$decimals) {
            $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 0);
        }

        return (string) $formatter->formatCurrency($amount / 100, $currency);
    }
}
