<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Admin\Entity\Admin;
use App\Customer\Entity\Customer;
use App\Project\Entity\Project;
use App\Requirement\Entity\Requirement;
use App\Security\Entity\User;
use App\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Small object factory for tests (persisted + flushed).
 */
final class Factory
{
    public const PASSWORD = 'correct-horse-battery';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    public function customer(string $email = 'customer@example.com', string $firstName = 'Karim'): Customer
    {
        $user = new User($email, $firstName, 'Test');
        $user->setPassword($this->hasher->hashPassword($user, self::PASSWORD));
        $user->setRoles([UserRole::CUSTOMER]);
        $customer = new Customer($user);
        $customer->setCompanyName($firstName.' SARL');
        $this->em->persist($user);
        $this->em->persist($customer);
        $this->em->flush();

        return $customer;
    }

    public function admin(string $email = 'admin@example.com', bool $super = false): User
    {
        $user = new User($email, 'Ada', 'Admin');
        $user->setPassword($this->hasher->hashPassword($user, self::PASSWORD));
        $user->setRoles([$super ? UserRole::SUPER_ADMIN : UserRole::ADMIN]);
        $this->em->persist($user);
        $this->em->persist(new Admin($user, 'Ada Admin'));
        $this->em->flush();

        return $user;
    }

    public function project(?Customer $customer = null, string $reference = 'PRJ-T-0001'): Project
    {
        $requirement = new Requirement('fr');
        $requirement->setSector('car_rental');
        $project = new Project($reference, 'mzian-client-'.strtolower(substr(md5($reference), 0, 6)), 'Atlas Cars', $requirement);
        $project->setCustomer($customer);
        $this->em->persist($requirement);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }
}
