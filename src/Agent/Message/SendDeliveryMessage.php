<?php

declare(strict_types=1);

namespace App\Agent\Message;

/**
 * Delivery agent: delivery documentation, subscription, customer notification.
 */
final class SendDeliveryMessage extends AbstractPipelineMessage
{
    public static function operation(): string
    {
        return 'send_delivery';
    }
}
