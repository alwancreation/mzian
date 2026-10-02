<?php

declare(strict_types=1);

namespace App\Agent\Message;

/**
 * Deployment agent: puts the tested version online.
 */
final class DeployApplicationMessage extends AbstractPipelineMessage
{
    public static function operation(): string
    {
        return 'deploy_application';
    }
}
