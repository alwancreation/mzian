<?php

declare(strict_types=1);

namespace App\Api\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * JSON answers of the stateless API firewall, in the same format as the other API
 * errors: missing or invalid token (401, with a Bearer challenge) and access denied (403).
 * The reason of an authentication failure is not detailed (no token oracle).
 */
final class ApiSecurityResponder implements AuthenticationEntryPointInterface, AuthenticationFailureHandlerInterface, AccessDeniedHandlerInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return self::unauthorized('Authentication required: send "Authorization: Bearer <token>".');
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return self::unauthorized('Invalid or expired API token.');
    }

    public function handle(Request $request, AccessDeniedException $accessDeniedException): Response
    {
        return new JsonResponse(['error' => ['code' => 403, 'message' => 'Access denied.']], 403);
    }

    private static function unauthorized(string $message): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => 401, 'message' => $message]], 401, ['WWW-Authenticate' => 'Bearer realm="mzian-api"']);
    }
}
