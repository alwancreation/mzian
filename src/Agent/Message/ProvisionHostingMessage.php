<?php

declare(strict_types=1);

namespace App\Agent\Message;

/**
 * Hosting agent: buys/creates the hosting account and the website space.
 */
final class ProvisionHostingMessage extends AbstractPipelineMessage
{
    public static function operation(): string
    {
        return 'provision_hosting';
    }
}
