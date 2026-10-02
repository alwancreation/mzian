<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Billing\Entity\Invoice;
use App\Billing\Entity\Subscription;
use App\Order\Entity\Order;
use App\Security\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Ownership check for customer documents (orders, invoices, subscriptions).
 *
 * @extends Voter<string, Order|Invoice|Subscription>
 */
final class CustomerResourceVoter extends Voter
{
    public const VIEW = 'CUSTOMER_RESOURCE_VIEW';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::VIEW === $attribute && ($subject instanceof Order || $subject instanceof Invoice || $subject instanceof Subscription);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return $user->isAdmin() || $subject->getCustomer()->getUser() === $user;
    }
}
