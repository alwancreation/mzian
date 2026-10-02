<?php

declare(strict_types=1);

namespace App\Notification\MessageHandler;

use App\Notification\Entity\Notification;
use App\Notification\Message\SendNotificationMessage;
use App\Notification\Provider\NotificationProviderInterface;
use App\Notification\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Idempotent: an already sent notification is never sent twice on retry.
 */
#[AsMessageHandler]
final readonly class SendNotificationHandler
{
    /**
     * @param iterable<NotificationProviderInterface> $providers
     */
    public function __construct(
        private NotificationRepository $notifications,
        private EntityManagerInterface $em,
        #[AutowireIterator('mzian.notification_provider')]
        private iterable $providers,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendNotificationMessage $message): void
    {
        $notification = $this->notifications->find($message->notificationId);
        if (null === $notification || Notification::STATUS_SENT === $notification->getStatus()) {
            return;
        }

        foreach ($this->providers as $provider) {
            if ($provider->supports($notification->getChannel())) {
                try {
                    $provider->send($notification);
                    $notification->markSent();
                    $this->em->flush();
                } catch (\Throwable $e) {
                    $notification->markFailed($e->getMessage());
                    $this->em->flush();
                    $this->logger->error('Notification delivery failed', ['notification' => $notification->getId(), 'error' => $e->getMessage()]);

                    throw $e; // retried by Messenger
                }

                return;
            }
        }

        // In-app only channel: nothing to deliver, the feed shows it.
        $notification->markSent();
        $this->em->flush();
    }
}
