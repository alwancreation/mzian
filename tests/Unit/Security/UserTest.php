<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\Entity\User;
use App\Security\UserRole;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testEveryUserHasRoleUser(): void
    {
        $user = new User('a@b.c', 'A');
        self::assertSame([UserRole::USER], $user->getRoles());
    }

    public function testUnknownRolesAreIgnored(): void
    {
        $user = new User('a@b.c', 'A');
        $user->setRoles([UserRole::CUSTOMER, 'ROLE_HACKER']);

        self::assertSame([UserRole::CUSTOMER], $user->getStoredRoles());
    }

    public function testAgentRoleCanNeverBeCombinedWithAdministratorRoles(): void
    {
        $user = new User('agent@mzian.local', 'Agent');
        $user->setRoles([UserRole::AGENT]);

        $this->expectException(\InvalidArgumentException::class);
        $user->addRole(UserRole::ADMIN);
    }

    public function testPasswordHashIsNotSerialized(): void
    {
        $user = new User('a@b.c', 'A');
        $user->setPassword('$2y$13$abcdefghijklmnopqrstuuJ0vVf1v8d7Yy7E1C3Uq2rZ1nqW3wq8y');

        self::assertStringNotContainsString('abcdefghijklmnopqrstuu', serialize($user));
    }
}
