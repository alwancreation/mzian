<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Notification\Entity\Notification;
use App\Notification\Enum\NotificationType;
use App\Notification\Message\SendNotificationMessage;
use App\Notification\MessageHandler\SendNotificationHandler;
use App\Notification\NotificationService;
use App\Tests\Support\Factory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class NotificationServiceTest extends KernelTestCase
{
    public function testNotificationIsTranslatedQueuedAndSentOnce(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $factory = new Factory($container->get(EntityManagerInterface::class), $container->get(UserPasswordHasherInterface::class));
        $customer = $factory->customer('salma@example.com');
        $customer->getUser()->setLocale('en');

        $notification = $container->get(NotificationService::class)->notifyUser($customer->getUser(), NotificationType::PaymentConfirmed, [
            'order' => 'MZ-2026-00001', 'amount' => '$237.00', 'project' => 'Atlas Cars', 'url' => 'http://localhost/en/account',
        ]);

        self::assertSame('Payment confirmed — MZ-2026-00001', $notification->getSubject());
        self::assertStringContainsString('$237.00', $notification->getBody());

        /** @var InMemoryTransport $transport */
        $transport = $container->get('messenger.transport.notifications');
        self::assertCount(1, $transport->getSent());
        self::assertInstanceOf(SendNotificationMessage::class, $transport->getSent()[0]->getMessage());

        $handler = $container->get(SendNotificationHandler::class);
        $handler(new SendNotificationMessage((int) $notification->getId()));
        $handler(new SendNotificationMessage((int) $notification->getId()));

        self::assertSame(Notification::STATUS_SENT, $notification->getStatus());
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertEmailAddressContains($email, 'To', 'salma@example.com');
        self::assertEmailHtmlBodyContains($email, 'Atlas Cars');
    }
}
