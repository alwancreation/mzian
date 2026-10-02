<?php

declare(strict_types=1);

namespace App\Agent;

/**
 * What an agent may be allowed to do (Admin > Agents). Least privilege: each
 * agent only gets the permissions of its own job. Decisions reserved to human
 * administrators can never be granted to an agent.
 */
final class AgentPermission
{
    public const HOSTING_PROVISION = 'hosting.provision';
    public const DOMAIN_REGISTER = 'domain.register';
    public const DOMAIN_DNS = 'domain.dns';
    public const REPOSITORY_WRITE = 'repository.write';
    public const APPLICATION_GENERATE = 'application.generate';
    public const AI_CONTENT = 'ai.content';
    public const TESTS_RUN = 'tests.run';
    public const DEPLOYMENT_DEPLOY = 'deployment.deploy';
    public const QA_RUN = 'qa.run';
    public const DELIVERY_SEND = 'delivery.send';
    public const BUDGET_SPEND = 'budget.spend';
    public const CREDENTIALS_STORE = 'credentials.store';
    public const CREDENTIALS_HASH = 'credentials.hash';

    public const ALL = [
        self::HOSTING_PROVISION, self::DOMAIN_REGISTER, self::DOMAIN_DNS, self::REPOSITORY_WRITE,
        self::APPLICATION_GENERATE, self::AI_CONTENT, self::TESTS_RUN, self::DEPLOYMENT_DEPLOY,
        self::QA_RUN, self::DELIVERY_SEND, self::BUDGET_SPEND, self::CREDENTIALS_STORE, self::CREDENTIALS_HASH,
    ];

    /** Human-only decisions: refused for any agent, whatever the configuration says. */
    public const FORBIDDEN = [
        'project.approve', 'project.reject', 'project.cancel', 'project.resume', 'budget.change',
        'provider.change', 'payment.refund', 'credentials.reveal', 'user.manage', 'settings.change',
    ];

    /**
     * @param list<string> $permissions
     *
     * @throws \InvalidArgumentException
     */
    public static function assertGrantable(array $permissions): void
    {
        foreach ($permissions as $permission) {
            if (\in_array($permission, self::FORBIDDEN, true)) {
                throw new \InvalidArgumentException(\sprintf('"%s" is reserved to human administrators and cannot be granted to an agent.', $permission));
            }
            if (!\in_array($permission, self::ALL, true)) {
                throw new \InvalidArgumentException(\sprintf('Unknown agent permission "%s".', $permission));
            }
        }
    }
}
