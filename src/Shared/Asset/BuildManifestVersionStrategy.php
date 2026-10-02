<?php

declare(strict_types=1);

namespace App\Shared\Asset;

use Symfony\Component\Asset\VersionStrategy\VersionStrategyInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Resolves "build/app.css" to its fingerprinted name using public/build/manifest.json
 * (written by assets/build.mjs). Falls back to the plain path when assets have not
 * been built yet (e.g. in the PHP test-suite), instead of failing the whole page.
 */
final class BuildManifestVersionStrategy implements VersionStrategyInterface
{
    /** @var array<string, string>|null */
    private ?array $manifest = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/public/build/manifest.json')]
        private readonly string $manifestPath,
    ) {
    }

    public function getVersion(string $path): string
    {
        return $this->applyVersion($path);
    }

    public function applyVersion(string $path): string
    {
        $manifest = $this->loadManifest();
        $key = ltrim($path, '/');

        return $manifest[$key] ?? $path;
    }

    /**
     * @return array<string, string>
     */
    private function loadManifest(): array
    {
        if (null !== $this->manifest) {
            return $this->manifest;
        }

        if (!is_file($this->manifestPath)) {
            return $this->manifest = [];
        }

        $decoded = json_decode((string) file_get_contents($this->manifestPath), true);

        return $this->manifest = \is_array($decoded) ? array_filter($decoded, 'is_string') : [];
    }
}
