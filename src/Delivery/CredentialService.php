<?php

declare(strict_types=1);

namespace App\Delivery;

use App\Project\Entity\Project;
use App\Project\Entity\ProjectCredential;
use App\Security\SecretManagerInterface;
use App\Shared\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Stores delivered credentials encrypted and reveals them only on explicit request.
 * Secrets never reach logs, audit entries, e-mails or AI prompts.
 */
final readonly class CredentialService
{
    public function __construct(
        private SecretManagerInterface $secrets,
        private EntityManagerInterface $em,
        private AuditLogger $audit,
    ) {
    }

    public function store(Project $project, string $type, string $label, string $username, string $secret, ?string $url = null): ProjectCredential
    {
        foreach ($project->getCredentials() as $existing) {
            if ($existing->getType() === $type && $existing->getUsername() === $username) {
                return $existing; // idempotent on retries
            }
        }
        $credential = new ProjectCredential($project, $type, $label, $username, $this->secrets->encrypt($secret), $url);
        $this->em->persist($credential);
        $this->audit->log('credential.stored', $project, metadata: ['type' => $type, 'label' => $label, 'username' => $username]);

        return $credential;
    }

    public function reveal(ProjectCredential $credential): string
    {
        $plain = $this->secrets->decrypt($credential->getEncryptedSecret());
        $credential->recordReveal();
        $this->audit->log('credential.revealed', $credential->getProject(), metadata: ['credential' => $credential->getId(), 'label' => $credential->getLabel()]);
        $this->em->flush();

        return $plain;
    }

    public function find(Project $project, string $type): ?ProjectCredential
    {
        foreach ($project->getCredentials() as $credential) {
            if ($credential->getType() === $type) {
                return $credential;
            }
        }

        return null;
    }

    /**
     * One-way hash of a stored secret, for the deployed application's login
     * (app/config.php). The clear value never leaves this method.
     */
    public function passwordHash(ProjectCredential $credential): string
    {
        $plain = $this->secrets->decrypt($credential->getEncryptedSecret());
        try {
            return password_hash($plain, \PASSWORD_DEFAULT);
        } finally {
            sodium_memzero($plain);
        }
    }

    public static function generatePassword(int $length = 20): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#%*-_';
        $password = '';
        for ($i = 0; $i < $length; ++$i) {
            $password .= $alphabet[random_int(0, \strlen($alphabet) - 1)];
        }

        return $password;
    }
}
