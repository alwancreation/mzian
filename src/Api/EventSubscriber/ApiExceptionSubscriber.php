<?php

declare(strict_types=1);

namespace App\Api\EventSubscriber;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Consistent JSON errors on /api/*: {"error": {"code": 422, "message": "...", "violations": [...]}}.
 * Internal error details are never exposed.
 */
final class ApiExceptionSubscriber
{
    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 10)]
    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }
        $exception = $event->getThrowable();
        if ($exception instanceof AuthenticationException || $exception instanceof AccessDeniedException) {
            return; // handled by the security layer (401/403)
        }

        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
        $error = ['code' => $status, 'message' => $status >= 500 ? 'Internal server error.' : $exception->getMessage()];

        $previous = $exception->getPrevious();
        if ($previous instanceof ValidationFailedException) {
            $error['message'] = 'Validation failed.';
            $error['violations'] = [];
            foreach ($previous->getViolations() as $violation) {
                $error['violations'][] = ['property' => $violation->getPropertyPath(), 'message' => $violation->getMessage()];
            }
        }

        $response = new JsonResponse(['error' => $error], $status);
        if ($exception instanceof HttpExceptionInterface) {
            foreach ($exception->getHeaders() as $name => $value) {
                $response->headers->set($name, $value);
            }
        }
        $event->setResponse($response);
    }
}
