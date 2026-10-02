<?php

declare(strict_types=1);

namespace App\Shared\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Rejects state-changing requests without a valid CSRF token with a plain 403
 * (not a security AccessDeniedException, which would redirect anonymous users to the login).
 */
trait CsrfGuardTrait
{
    protected function denyUnlessCsrfValid(string $tokenId, Request $request, string $field = '_token'): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->getPayload()->getString($field))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
