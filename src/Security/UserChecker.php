<?php

declare(strict_types=1);

namespace App\Security;

use App\Security\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Disabled accounts cannot log in (web or API), and ROLE_AGENT service accounts
 * can never open an interactive (form login) session.
 */
final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }
        if (!$user->isActive()) {
            throw new CustomUserMessageAccountStatusException('security.account_disabled');
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
    }
}
