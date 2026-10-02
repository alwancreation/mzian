<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Project\Entity\Project;
use App\Security\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * A customer can only see/act on their own projects; administrators see all of them.
 *
 * @extends Voter<string, Project>
 */
final class ProjectVoter extends Voter
{
    public const VIEW = 'PROJECT_VIEW';
    public const REVEAL_CREDENTIALS = 'PROJECT_REVEAL_CREDENTIALS';
    public const MANAGE = 'PROJECT_MANAGE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Project && \in_array($attribute, [self::VIEW, self::REVEAL_CREDENTIALS, self::MANAGE], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $isOwner = null !== $subject->getCustomer() && $subject->getCustomer()->getUser() === $user;

        return match ($attribute) {
            self::VIEW => $isOwner || $user->isAdmin(),
            // Delivered credentials are for the customer only (admins have the provider consoles).
            self::REVEAL_CREDENTIALS => $isOwner,
            self::MANAGE => $user->isAdmin(),
            default => false,
        };
    }
}
