<?php

declare(strict_types=1);

namespace App\Security\EventSubscriber;

use App\Security\Entity\User;
use App\Shared\Audit\AuditLogger;
use App\Shared\Security\Actor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Audits interactive logins (success + failure) and records the last login date.
 */
final readonly class LoginSubscriber
{
    public function __construct(
        private EntityManagerInterface $em,
        private AuditLogger $audit,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[AsEventListener]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User || 'main' !== $event->getFirewallName()) {
            return;
        }
        $user->recordLogin();
        $actor = $user->isAdmin() ? Actor::admin((int) $user->getId(), $user->getFullName()) : Actor::customer((int) $user->getId(), $user->getFullName());
        $this->audit->log('security.login', $user, actor: $actor);
        $this->em->flush();

        // Administrators land on the admin dashboard unless they were heading somewhere specific.
        $response = $event->getResponse();
        if ($user->isAdmin() && $response instanceof RedirectResponse
            && str_contains($response->getTargetUrl(), parse_url($this->urlGenerator->generate('account_dashboard', ['_locale' => $event->getRequest()->getLocale()]), \PHP_URL_PATH) ?: '/account')) {
            $event->setResponse(new RedirectResponse($this->urlGenerator->generate('admin_dashboard')));
        }
    }

    #[AsEventListener]
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        if ('main' !== $event->getFirewallName()) {
            return;
        }
        $email = (string) $event->getRequest()->request->get('email', '');
        $this->audit->log('security.login_failed', metadata: ['email' => mb_substr($email, 0, 180)], actor: Actor::visitor());
        $this->em->flush();
    }
}
