<?php

declare(strict_types=1);

namespace App\Shared\Twig;

use App\Shared\I18n\LocalizedText;
use App\Shared\I18n\MoneyFormatter;
use App\Shared\Routing\LocalizedRoute;
use App\Shared\Settings\SettingsService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

final readonly class AppExtension
{
    public function __construct(
        private RequestStack $requestStack,
        private UrlGeneratorInterface $urlGenerator,
        private SettingsService $settings,
    ) {
    }

    /**
     * Currency of the platform prices (Admin > Pricing).
     */
    #[AsTwigFunction('platform_currency')]
    public function platformCurrency(): string
    {
        return (string) ($this->settings->get('pricing')['currency'] ?? 'USD');
    }

    /**
     * Formats an amount in minor units: 23700|money('USD') => "$237.00" / "237,00 $US".
     * Without a currency, the platform currency is used.
     */
    #[AsTwigFilter('money')]
    public function money(?int $amount, ?string $currency = null, bool $decimals = true): string
    {
        $currency ??= $this->platformCurrency();

        return MoneyFormatter::format($amount ?? 0, $currency, $this->locale(), $decimals);
    }

    /**
     * @param array<string, string>|string|null $values
     */
    #[AsTwigFilter('localized')]
    public function localized(array|string|null $values): string
    {
        return LocalizedText::pick($values, $this->locale());
    }

    #[AsTwigFilter('duration')]
    public function duration(?int $seconds): string
    {
        if (null === $seconds) {
            return '—';
        }
        if ($seconds < 60) {
            return $seconds.'s';
        }
        if ($seconds < 3600) {
            return \sprintf('%dm %02ds', intdiv($seconds, 60), $seconds % 60);
        }

        return \sprintf('%dh %02dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }

    #[AsTwigFunction('is_rtl')]
    public function isRtl(): bool
    {
        return \in_array($this->locale(), LocalizedRoute::RTL_LOCALES, true);
    }

    /**
     * URLs of the current page in every enabled locale (language switcher + hreflang).
     *
     * @param array<string, array<string, mixed>> $overrides per-locale route params (e.g. localized slugs)
     *
     * @return array<string, string>
     */
    #[AsTwigFunction('locale_urls')]
    public function localeUrls(array $overrides = [], bool $absolute = false): array
    {
        $request = $this->requestStack->getMainRequest();
        if (null === $request) {
            return [];
        }
        $route = $request->attributes->get('_canonical_route') ?? $request->attributes->get('_route');
        $params = (array) $request->attributes->get('_route_params', []);
        $urls = [];
        foreach (LocalizedRoute::LOCALES as $locale) {
            try {
                $urls[$locale] = $this->urlGenerator->generate(
                    (string) $route,
                    array_merge($params, $overrides[$locale] ?? [], ['_locale' => $locale]),
                    $absolute ? UrlGeneratorInterface::ABSOLUTE_URL : UrlGeneratorInterface::ABSOLUTE_PATH,
                );
            } catch (\Throwable) {
                $urls[$locale] = $this->urlGenerator->generate('home', ['_locale' => $locale]);
            }
        }

        return $urls;
    }

    private function locale(): string
    {
        return $this->requestStack->getCurrentRequest()?->getLocale() ?? LocalizedRoute::DEFAULT_LOCALE;
    }
}
