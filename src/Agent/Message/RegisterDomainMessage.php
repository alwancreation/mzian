<?php

declare(strict_types=1);

namespace App\Agent\Message;

/**
 * Domain agent: registers (or connects) the domain and configures the DNS.
 */
final class RegisterDomainMessage extends AbstractPipelineMessage
{
    public static function operation(): string
    {
        return 'register_domain';
    }
}
