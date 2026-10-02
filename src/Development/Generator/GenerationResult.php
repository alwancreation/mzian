<?php

declare(strict_types=1);

namespace App\Development\Generator;

final readonly class GenerationResult
{
    /**
     * @param list<string>         $files     relative paths
     * @param list<string>         $languages
     * @param list<string>         $pages     public pages (relative to public/)
     * @param list<string>         $entities
     * @param array<string, mixed> $coverage  implemented / configuration / custom features
     * @param array<string, mixed> $manifest  content of mzian.json
     */
    public function __construct(
        public string $directory,
        public array $files,
        public array $languages,
        public array $pages,
        public array $entities,
        public array $coverage,
        public array $manifest,
    ) {
    }
}
