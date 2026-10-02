<?php

declare(strict_types=1);

namespace App\Shared\Enum;

/**
 * Who performed an action. Agents are a distinct actor type: they never inherit
 * the permissions of a human administrator.
 */
enum ActorType: string
{
    case Customer = 'customer';
    case Admin = 'admin';
    case Agent = 'agent';
    case System = 'system';
    case Visitor = 'visitor';
    case Webhook = 'webhook';

    public function isHuman(): bool
    {
        return \in_array($this, [self::Customer, self::Admin, self::Visitor], true);
    }
}
