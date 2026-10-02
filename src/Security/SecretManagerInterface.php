<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Secret manager abstraction: encrypts secrets at rest (provider API keys,
 * delivered customer credentials). Swap the implementation to use Vault,
 * AWS KMS, GCP Secret Manager... without touching the callers.
 */
interface SecretManagerInterface
{
    public function encrypt(string $plaintext): string;

    /**
     * @throws \RuntimeException when the payload was tampered with or the key is wrong
     */
    public function decrypt(string $ciphertext): string;
}
