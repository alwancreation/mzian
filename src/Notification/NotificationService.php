<?php

declare(strict_types=1);

namespace App\Notification;

use App\Admin\Repository\AdminRepository;
use App\Notification\Entity\Notification;
use App\Notification\Enum\NotificationType;
use App\Notification\Message\SendNotificationMessage;
use App\Project\Entity\Project;
use App\Security\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Creates notifications (persisted = in-app feed) and queues their e-mail delivery.
 * Subjects/bodies are translated in the recipient's language.
 * Never put secrets (passwords, tokens) in a notification: link to the customer area instead.
 */
final readonly class NotificationService
{
    public function __construct(
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private TranslatorInterface $translator,
        private AdminRepository $admins,
        #[Autowire('%env(MZIAN_ADMIN_EMAIL)%')]
        private string $fallbackAdminEmail,
    ) {
    }

    /**
     * @param array<string, scalar|null> $parameters translation parameters (%project%, %amount%...) + optional "url"
     */
    public function notifyUser(User $user, NotificationType $type, array $parameters = [], ?Project $project = null): Notification
    {
        return $this->create($user, $user->getEmail(), $user->getLocale(), $type, $parameters, $project);
    }

    /**
     * Notifies every administrator who opted in (or MZIAN_ADMIN_EMAIL when there is none).
     *
     * @param array<string, scalar|null> $parameters
     *
     * @return list<Notification>
     */
    public function notifyAdmins(NotificationType $type, array $parameters = [], ?Project $project = null): array
    {
        $notifications = [];
        foreach ($this->admins->findBy(['receivesApprovalNotifications' => true]) as $admin) {
            if ($admin->getUser()->isActive()) {
                $notifications[] = $this->create($admin->getUser(), $admin->getUser()->getEmail(), 'en', $type, $parameters, $project);
            }
        }
        if ([] === $notifications && '' !== $this->fallbackAdminEmail) {
            $notifications[] = $this->create(null, $this->fallbackAdminEmail, 'en', $type, $parameters, $project);
        }

        return $notifications;
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    private function create(?User $user, string $email, string $locale, NotificationType $type, array $parameters, ?Project $project): Notification
    {
        $translationParams = [];
        foreach ($parameters as $key => $value) {
            $translationParams['%'.$key.'%'] = (string) $value;
        }
        $subject = $this->translator->trans('notification.'.$type->value.'.subject', $translationParams, 'notifications', $locale);
        $body = $this->translator->trans('notification.'.$type->value.'.body', $translationParams, 'notifications', $locale);
        $context = $parameters;
        $context['action_label'] = $this->translator->trans('notification.action', [], 'notifications', $locale);

        $notification = new Notification($user, $email, $type, 'email', $subject, $body, $context, $project);
        $this->em->persist($notification);
        $this->em->flush();
        $this->bus->dispatch(new SendNotificationMessage((int) $notification->getId()));

        return $notification;
    }
}
