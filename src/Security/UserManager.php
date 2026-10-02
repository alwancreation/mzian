<?php

declare(strict_types=1);

namespace App\Security;

use App\Admin\Entity\Admin;
use App\Customer\Entity\Customer;
use App\Lead\Repository\LeadRepository;
use App\Security\Entity\ApiToken;
use App\Security\Entity\User;
use App\Security\Repository\UserRepository;
use App\Shared\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creates accounts (customers, administrators) and API tokens.
 */
final readonly class UserManager
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $users,
        private LeadRepository $leads,
        private UserPasswordHasherInterface $hasher,
        private AuditLogger $audit,
    ) {
    }

    public function createCustomer(
        string $email,
        string $plainPassword,
        string $firstName,
        ?string $lastName = null,
        ?string $companyName = null,
        ?string $phone = null,
        string $locale = 'fr',
    ): Customer {
        $this->assertEmailAvailable($email);

        $user = new User($email, $firstName, $lastName);
        $user->setPassword($this->hasher->hashPassword($user, $plainPassword));
        $user->setRoles([UserRole::CUSTOMER]);
        $user->setLocale($locale);

        $customer = new Customer($user);
        $customer->setCompanyName($companyName);
        $customer->setPhone($phone);

        // A lead captured before registration becomes linked to the new customer.
        foreach ($this->leads->findBy(['email' => $user->getEmail()]) as $lead) {
            $lead->attachCustomer($customer);
            $customer->setSector($customer->getSector() ?? $lead->getSector());
            $customer->setCity($customer->getCity() ?? $lead->getCity());
        }

        $this->em->persist($user);
        $this->em->persist($customer);
        $this->audit->log('customer.registered', $user, newValue: ['email' => $user->getEmail()]);
        $this->em->flush();

        return $customer;
    }

    public function createAdmin(string $email, string $plainPassword, string $displayName, bool $superAdmin = false): Admin
    {
        $this->assertEmailAvailable($email);

        $parts = explode(' ', $displayName, 2);
        $user = new User($email, $parts[0], $parts[1] ?? null);
        $user->setPassword($this->hasher->hashPassword($user, $plainPassword));
        $user->setRoles($superAdmin ? [UserRole::SUPER_ADMIN] : [UserRole::ADMIN]);
        $user->setLocale('en');

        $admin = new Admin($user, $displayName);
        $this->em->persist($user);
        $this->em->persist($admin);
        $this->audit->log('admin.created', $user, newValue: ['email' => $user->getEmail(), 'roles' => $user->getStoredRoles()]);
        $this->em->flush();

        return $admin;
    }

    public function changePassword(User $user, string $plainPassword): void
    {
        $user->setPassword($this->hasher->hashPassword($user, $plainPassword));
        $this->audit->log('security.password_changed', $user);
        $this->em->flush();
    }

    /**
     * @return array{0: ApiToken, 1: string} token entity and clear value (shown once)
     */
    public function createApiToken(User $user, string $name, ?\DateTimeImmutable $expiresAt = null): array
    {
        [$token, $clear] = ApiToken::generate($user, $name, $expiresAt);
        $this->em->persist($token);
        $this->audit->log('security.api_token_created', $user, metadata: ['name' => $name, 'prefix' => $token->getTokenPrefix()]);
        $this->em->flush();

        return [$token, $clear];
    }

    public function revokeApiToken(ApiToken $token): void
    {
        $token->revoke();
        $this->audit->log('security.api_token_revoked', $token->getUser(), metadata: ['prefix' => $token->getTokenPrefix()]);
        $this->em->flush();
    }

    private function assertEmailAvailable(string $email): void
    {
        if (null !== $this->users->findOneBy(['email' => mb_strtolower(trim($email))])) {
            throw new \DomainException(\sprintf('An account already exists for "%s".', $email));
        }
    }
}
