<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Refuses to run in production with missing or well-known secrets: the values
 * committed in .env.dev / .env.test are deliberately labelled "dev-only" /
 * "test-only" and must never protect real data.
 */
final class RuntimeSecretsChecker
{
    private const MARKERS = ['change-me', 'changeme', 'dev-only', 'test-only', 'insecure', 'example'];

    /** @var list<string>|null */
    private ?array $problems = null;

    public function __construct(
        #[Autowire('%env(string:default::APP_SECRET)%')]
        private readonly string $appSecret,
        #[Autowire('%env(string:default::MZIAN_ENCRYPTION_KEY)%')]
        private readonly string $encryptionKey,
        #[Autowire('%env(string:default::MZIAN_WEBHOOK_SECRET)%')]
        private readonly string $webhookSecret,
    ) {
    }

    /**
     * Problems found, never including the secret values themselves.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        if (null !== $this->problems) {
            return $this->problems;
        }
        $problems = [];
        if (\strlen($this->appSecret) < 32 || $this->isWellKnown($this->appSecret)) {
            $problems[] = 'APP_SECRET must be a random value of at least 32 characters (e.g. `openssl rand -hex 32`).';
        }
        $key = base64_decode($this->encryptionKey, true);
        if (false === $key || \SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($key) || $this->isWellKnown($key) || $this->isWellKnown($this->encryptionKey)) {
            $problems[] = 'MZIAN_ENCRYPTION_KEY must be a random 32-byte key, base64 encoded (`php -r "echo base64_encode(random_bytes(32));"`).';
        }
        if (\strlen($this->webhookSecret) < 24 || $this->isWellKnown($this->webhookSecret)) {
            $problems[] = 'MZIAN_WEBHOOK_SECRET must be a random value of at least 24 characters.';
        }

        return $this->problems = $problems;
    }

    private function isWellKnown(string $value): bool
    {
        $value = strtolower($value);
        foreach (self::MARKERS as $marker) {
            if (str_contains($value, $marker)) {
                return true;
            }
        }

        return false;
    }
}
