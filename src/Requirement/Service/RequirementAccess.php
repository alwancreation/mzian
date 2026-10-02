<?php

declare(strict_types=1);

namespace App\Requirement\Service;

use App\Requirement\Entity\Requirement;
use App\Security\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A requirement (personal data) is only reachable from the browser session that
 * created it, or by its customer. The random token alone is not enough.
 */
final readonly class RequirementAccess
{
    private const SESSION_KEY = 'mzian_requirements';

    public function __construct(
        private RequestStack $requestStack,
        private Security $security,
    ) {
    }

    public function grant(Requirement $requirement): void
    {
        $session = $this->requestStack->getSession();
        $tokens = (array) $session->get(self::SESSION_KEY, []);
        $tokens[] = $requirement->getToken();
        $session->set(self::SESSION_KEY, \array_slice(array_values(array_unique($tokens)), -20));
    }

    public function canAccess(Requirement $requirement): bool
    {
        $user = $this->security->getUser();
        if ($user instanceof User && ($user->isAdmin() || (null !== $requirement->getCustomer() && $requirement->getCustomer()->getUser() === $user))) {
            return true;
        }
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request || !$request->hasSession()) {
            return false;
        }

        return \in_array($requirement->getToken(), (array) $request->getSession()->get(self::SESSION_KEY, []), true);
    }

    public function denyUnlessAccessible(Requirement $requirement): void
    {
        if (!$this->canAccess($requirement)) {
            throw new NotFoundHttpException();
        }
    }
}
