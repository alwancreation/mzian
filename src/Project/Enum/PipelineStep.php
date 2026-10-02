<?php

declare(strict_types=1);

namespace App\Project\Enum;

/**
 * Ordered automation steps executed by the agents after the admin approval.
 *
 * Order rationale: QA checks the *deployed* application (HTTPS, domain, pages,
 * assets), so it necessarily runs after the deployment.
 */
enum PipelineStep: string
{
    case Hosting = 'hosting';
    case Domain = 'domain';
    case Development = 'development';
    case Testing = 'testing';
    case Deployment = 'deployment';
    case Qa = 'qa';
    case Delivery = 'delivery';

    /**
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [self::Hosting, self::Domain, self::Development, self::Testing, self::Deployment, self::Qa, self::Delivery];
    }

    public function position(): int
    {
        return (int) array_search($this, self::ordered(), true);
    }

    public function next(): ?self
    {
        return self::ordered()[$this->position() + 1] ?? null;
    }

    public function agentCode(): string
    {
        return $this->value;
    }

    /** State the project must be in before the step starts. */
    public function requiredStatus(): ProjectStatus
    {
        return match ($this) {
            self::Hosting => ProjectStatus::Approved,
            self::Domain => ProjectStatus::HostingReady,
            self::Development => ProjectStatus::DomainReady,
            self::Testing => ProjectStatus::Development,
            self::Deployment => ProjectStatus::Testing,
            self::Qa => ProjectStatus::Deployed,
            self::Delivery => ProjectStatus::Qa,
        };
    }

    /** Transition applied when the step starts (null = the step runs in the required state). */
    public function startTransition(): ?string
    {
        return match ($this) {
            self::Hosting => 'start_provisioning',
            self::Domain => null,
            self::Development => 'start_development',
            self::Testing => 'start_testing',
            self::Deployment => 'start_deployment',
            self::Qa => 'start_qa',
            self::Delivery => 'start_delivery',
        };
    }

    /** State while the step is running. */
    public function runningStatus(): ProjectStatus
    {
        return match ($this) {
            self::Hosting => ProjectStatus::Provisioning,
            self::Domain => ProjectStatus::HostingReady,
            self::Development => ProjectStatus::Development,
            self::Testing => ProjectStatus::Testing,
            self::Deployment => ProjectStatus::Deploying,
            self::Qa => ProjectStatus::Qa,
            self::Delivery => ProjectStatus::Delivery,
        };
    }

    /** Transition applied when the step succeeds (null = the next step's start moves the state). */
    public function completeTransition(): ?string
    {
        return match ($this) {
            self::Hosting => 'hosting_ready',
            self::Domain => 'domain_ready',
            self::Deployment => 'deployed',
            self::Delivery => 'complete',
            default => null,
        };
    }

    /** Critical quality gates can never be skipped, even by an administrator. */
    public function isSkippable(): bool
    {
        return !\in_array($this, [self::Testing, self::Qa, self::Deployment, self::Development], true);
    }

    public function translationKey(): string
    {
        return 'pipeline.step.'.$this->value;
    }
}
