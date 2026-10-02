<?php

declare(strict_types=1);

namespace App\Notification\Provider;

use App\Notification\Entity\Notification;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Delivery channel of a notification (e-mail, SMS, WhatsApp, Slack...).
 * Add a channel by implementing this interface: it is auto-registered.
 */
#[AutoconfigureTag('mzian.notification_provider')]
interface NotificationProviderInterface
{
    public function supports(string $channel): bool;

    /**
     * @throws \Throwable when the delivery fails (the job is retried)
     */
    public function send(Notification $notification): void;
}
