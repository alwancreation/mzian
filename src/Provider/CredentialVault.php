<?php

declare(strict_types=1);

namespace App\Provider;

use App\Provider\Entity\Provider;
use App\Provider\Entity\ProviderCredential;
use App\Security\SecretManagerInterface;
use App\Shared\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Single entry point to provider secrets.
 *
 * Resolution order: encrypted ProviderCredential (admin) > environment variable
 * declared in the provider settings ("env": {"api_key": "OPENAI_API_KEY"}).
 * Secrets are only decrypted in memory, for the provider driver. They are never
 * logged, audited in clear, returned by the API or included in an AI prompt.
 */
final readonly class CredentialVault
{
    public function __construct(
        private SecretManagerInterface $secrets,
        private EntityManagerInterface $em,
        private AuditLogger $audit,
    ) {
    }

    public function get(Provider $provider, string $name): ?string
    {
        $credential = $provider->getCredential($name);
        if (null !== $credential) {
            $credential->markUsed();

            return $this->secrets->decrypt($credential->getEncryptedValue());
        }

        $envName = $provider->getSettings()['env'][$name] ?? null;
        if (\is_string($envName) && '' !== $envName) {
            $value = $_SERVER[$envName] ?? $_ENV[$envName] ?? getenv($envName);
            if (\is_string($value) && '' !== $value) {
                return $value;
            }
        }

        return null;
    }

    public function has(Provider $provider, string $name): bool
    {
        return null !== $this->get($provider, $name);
    }

    /**
     * Stores (or rotates) a secret, encrypted. The clear value is never logged.
     */
    public function store(Provider $provider, string $name, string $clearValue): void
    {
        $encrypted = $this->secrets->encrypt($clearValue);
        $credential = $provider->getCredential($name);
        if (null === $credential) {
            $credential = new ProviderCredential($provider, $name, $encrypted);
            $this->em->persist($credential);
            $action = 'provider.credential_created';
        } else {
            $credential->rotate($encrypted);
            $action = 'provider.credential_rotated';
        }
        $this->audit->log($action, $provider, metadata: ['credential' => $name, 'fingerprint' => substr(hash('sha256', $clearValue), 0, 8)]);
        $this->em->flush();
    }

    public function remove(Provider $provider, string $name): void
    {
        $credential = $provider->getCredential($name);
        if (null !== $credential) {
            $provider->getCredentials()->removeElement($credential);
            $this->em->remove($credential);
            $this->audit->log('provider.credential_removed', $provider, metadata: ['credential' => $name]);
            $this->em->flush();
        }
    }
}
