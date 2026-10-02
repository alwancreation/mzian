<?php

declare(strict_types=1);

namespace App\Security\EventListener;

use App\Security\RuntimeSecretsChecker;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Defense in depth (the entrypoint already runs mzian:security:check): in
 * production, no request is served with missing or development secrets.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4096)]
final readonly class InsecureProductionListener
{
    public function __construct(
        private RuntimeSecretsChecker $checker,
        private LoggerInterface $logger,
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if ('prod' !== $this->environment || !$event->isMainRequest() || [] === $problems = $this->checker->problems()) {
            return;
        }
        $this->logger->critical('Refusing to serve requests: insecure runtime secrets.', ['problems' => $problems]);

        throw new ServiceUnavailableHttpException(null, 'The platform is not configured.');
    }
}
