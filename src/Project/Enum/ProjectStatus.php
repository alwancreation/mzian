<?php

declare(strict_types=1);

namespace App\Project\Enum;

/**
 * Places of the project state machine (see App\Project\Workflow\ProjectWorkflowDefinition).
 */
enum ProjectStatus: string
{
    case Draft = 'DRAFT';
    case Analyzing = 'ANALYZING';
    case Quoted = 'QUOTED';
    case Ordered = 'ORDERED';
    case Paid = 'PAID';
    case PendingAdminApproval = 'PENDING_ADMIN_APPROVAL';
    case ChangesRequested = 'CHANGES_REQUESTED';
    case Approved = 'APPROVED';
    case Provisioning = 'PROVISIONING';
    case HostingReady = 'HOSTING_READY';
    case DomainReady = 'DOMAIN_READY';
    case Development = 'DEVELOPMENT';
    case Testing = 'TESTING';
    case Deploying = 'DEPLOYING';
    case Deployed = 'DEPLOYED';
    case Qa = 'QA';
    case Delivery = 'DELIVERY';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';
    case WaitingAdminApproval = 'WAITING_ADMIN_APPROVAL';

    /**
     * States in which the AI agents are allowed to work (after the human approval).
     *
     * @return list<self>
     */
    public static function automationStates(): array
    {
        return [
            self::Approved, self::Provisioning, self::HostingReady, self::DomainReady, self::Development,
            self::Testing, self::Deploying, self::Deployed, self::Qa, self::Delivery,
        ];
    }

    /**
     * @return list<self>
     */
    public static function preSaleStates(): array
    {
        return [self::Draft, self::Analyzing, self::Quoted, self::Ordered, self::Paid, self::PendingAdminApproval, self::ChangesRequested];
    }

    public function isAutomation(): bool
    {
        return \in_array($this, self::automationStates(), true);
    }

    public function isTerminal(): bool
    {
        return self::Completed === $this || self::Cancelled === $this;
    }

    public function needsAdmin(): bool
    {
        return \in_array($this, [self::PendingAdminApproval, self::WaitingAdminApproval, self::Failed], true);
    }

    public function progress(): int
    {
        return match ($this) {
            self::Draft => 2,
            self::Analyzing => 5,
            self::Quoted => 10,
            self::Ordered => 15,
            self::Paid => 20,
            self::PendingAdminApproval, self::ChangesRequested => 25,
            self::Approved => 30,
            self::Provisioning => 35,
            self::HostingReady => 42,
            self::DomainReady => 50,
            self::Development => 60,
            self::Testing => 72,
            self::Deploying => 80,
            self::Deployed => 85,
            self::Qa => 90,
            self::Delivery => 96,
            self::Completed => 100,
            self::Failed, self::Cancelled, self::WaitingAdminApproval => 0,
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Draft, self::Analyzing => 'badge-gray',
            self::Quoted, self::Ordered => 'badge-blue',
            self::Paid, self::PendingAdminApproval, self::ChangesRequested, self::WaitingAdminApproval => 'badge-amber',
            self::Completed => 'badge-green',
            self::Failed, self::Cancelled => 'badge-red',
            default => 'badge-violet',
        };
    }

    public function translationKey(): string
    {
        return 'project.status.'.strtolower($this->value);
    }
}
