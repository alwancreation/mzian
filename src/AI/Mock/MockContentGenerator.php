<?php

declare(strict_types=1);

namespace App\AI\Mock;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Deterministic website copy (content_generation schema) built from translated
 * templates and the customer's data. Real AI providers write richer copy.
 */
final readonly class MockContentGenerator
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * @param array<string, mixed> $business {name, sector_name, city, locale, features: list<string>, solution}
     *
     * @return array<string, mixed>
     */
    public function generate(array $business): array
    {
        $locale = (string) ($business['locale'] ?? 'fr');
        $name = (string) ($business['name'] ?? 'Mzian');
        $city = (string) ($business['city'] ?? '');
        $sector = (string) ($business['sector_name'] ?? '');
        $params = ['%name%' => $name, '%city%' => '' !== $city ? $city : $this->translator->trans('content.your_city', [], null, $locale), '%sector%' => mb_strtolower($sector)];

        $services = [];
        foreach (\array_slice((array) ($business['features'] ?? []), 0, 6) as $feature) {
            $services[] = [
                'title' => mb_substr((string) $feature, 0, 80),
                'description' => mb_substr($this->translator->trans('content.service_description', ['%service%' => mb_strtolower((string) $feature), '%name%' => $name], null, $locale), 0, 300),
            ];
        }
        if ([] === $services) {
            $services[] = ['title' => $sector ?: $name, 'description' => $this->translator->trans('content.service_description', ['%service%' => mb_strtolower($sector ?: $name), '%name%' => $name], null, $locale)];
        }

        return [
            'tagline' => mb_substr($this->translator->trans('content.tagline', $params, null, $locale), 0, 140),
            'about' => mb_substr($this->translator->trans('content.about', $params, null, $locale), 0, 1200),
            'services' => $services,
            'call_to_action' => mb_substr($this->translator->trans('content.cta', $params, null, $locale), 0, 60),
            'seo_title' => mb_substr($this->translator->trans('content.seo_title', $params, null, $locale), 0, 70),
            'seo_description' => mb_substr($this->translator->trans('content.seo_description', $params, null, $locale), 0, 170),
        ];
    }
}
