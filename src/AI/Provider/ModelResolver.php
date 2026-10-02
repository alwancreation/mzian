<?php

declare(strict_types=1);

namespace App\AI\Provider;

use App\Provider\Entity\Provider;

/**
 * Model of a provider: settings "model" > env var named by "model_env" > "default_model".
 */
final class ModelResolver
{
    public static function resolve(Provider $provider): string
    {
        $settings = $provider->getSettings();
        if (\is_string($settings['model'] ?? null) && '' !== $settings['model']) {
            return $settings['model'];
        }
        $env = $settings['model_env'] ?? null;
        if (\is_string($env)) {
            $value = $_SERVER[$env] ?? $_ENV[$env] ?? getenv($env);
            if (\is_string($value) && '' !== $value) {
                return $value;
            }
        }

        return (string) ($settings['default_model'] ?? 'unknown');
    }
}
