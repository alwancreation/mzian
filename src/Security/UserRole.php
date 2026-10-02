<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Role names. Hierarchy (config/packages/security.yaml):
 *   ROLE_SUPER_ADMIN > ROLE_ADMIN > ROLE_USER
 *   ROLE_CUSTOMER > ROLE_USER
 *   ROLE_AGENT is isolated: it is NOT part of the admin hierarchy.
 */
final class UserRole
{
    public const USER = 'ROLE_USER';
    public const CUSTOMER = 'ROLE_CUSTOMER';
    public const ADMIN = 'ROLE_ADMIN';
    public const SUPER_ADMIN = 'ROLE_SUPER_ADMIN';
    public const AGENT = 'ROLE_AGENT';

    public const ASSIGNABLE = [self::CUSTOMER, self::ADMIN, self::SUPER_ADMIN, self::AGENT];

    private function __construct()
    {
    }
}
