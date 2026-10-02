<?php

declare(strict_types=1);

namespace App\Notification\Message;

final readonly class SendNotificationMessage
{
    public function __construct(public int $notificationId)
    {
    }
}
