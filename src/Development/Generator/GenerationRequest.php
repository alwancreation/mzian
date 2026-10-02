<?php

declare(strict_types=1);

namespace App\Development\Generator;

/**
 * Everything needed to generate a customer application.
 */
final readonly class GenerationRequest
{
    /**
     * @param list<string>                        $features catalog feature codes of the project
     * @param array<string, array<string, mixed>> $content  locale => website copy (content_generation schema)
     */
    public function __construct(
        public string $template,
        public string $slug,
        public string $businessName,
        public ?string $city,
        public string $locale,
        public array $features,
        public array $content,
        /** Absolute URL the site will be published at (canonical, sitemap). */
        public string $baseUrl,
        public string $currency,
        public ?string $phone = null,
        public ?string $email = null,
        public string $version = '1.0.0',
    ) {
    }
}
