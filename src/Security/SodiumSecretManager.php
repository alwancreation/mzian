<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Authenticated encryption with libsodium secretbox (XSalsa20-Poly1305).
 * Output format: "v1:" . base64(nonce . ciphertext).
 */
#[AsAlias(SecretManagerInterface::class)]
final class SodiumSecretManager implements SecretManagerInterface
{
    private const VERSION = 'v1:';

    private ?string $key = null;

    public function __construct(
        #[Autowire('%env(MZIAN_ENCRYPTION_KEY)%')]
        private readonly string $base64Key,
    ) {
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->key());

        return self::VERSION.base64_encode($nonce.$cipher);
    }

    public function decrypt(string $ciphertext): string
    {
        if (!str_starts_with($ciphertext, self::VERSION)) {
            throw new \RuntimeException('Unsupported secret format.');
        }
        $decoded = base64_decode(substr($ciphertext, \strlen(self::VERSION)), true);
        if (false === $decoded || \strlen($decoded) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Malformed secret.');
        }
        $nonce = substr($decoded, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($decoded, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key());
        if (false === $plain) {
            throw new \RuntimeException('Secret could not be decrypted (wrong key or tampered value).');
        }

        return $plain;
    }

    private function key(): string
    {
        if (null !== $this->key) {
            return $this->key;
        }
        $key = base64_decode($this->base64Key, true);
        if (false === $key || \SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($key)) {
            throw new \RuntimeException('MZIAN_ENCRYPTION_KEY must be a base64 encoded 32-byte key.');
        }

        return $this->key = $key;
    }
}
