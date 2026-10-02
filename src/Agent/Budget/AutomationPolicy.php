<?php

declare(strict_types=1);

namespace App\Agent\Budget;

/**
 * Automation policy (Admin > Settings, "automation_policy"): above these limits an
 * agent stops and an administrator decides. Amounts in minor units.
 */
final readonly class AutomationPolicy
{
    public function __construct(
        public int $maxHostingCost = 10000,
        public int $maxDomainCost = 3000,
        public int $maxMonthlyCost = 3000,
        public int $requireAdminApprovalAbove = 10000,
        public int $maxAttempts = 3,
        public int $qaMinScore = 70,
    ) {
    }

    /**
     * @param array<string, mixed> $settings major units
     */
    public static function fromSettings(array $settings): self
    {
        $cents = static fn (mixed $v, float $default): int => (int) round((float) ($v ?? $default) * 100);

        return new self(
            $cents($settings['max_hosting_cost'] ?? null, 100),
            $cents($settings['max_domain_cost'] ?? null, 30),
            $cents($settings['max_monthly_cost'] ?? null, 30),
            $cents($settings['require_admin_approval_above'] ?? null, 100),
            max(1, (int) ($settings['max_attempts'] ?? 3)),
            min(100, max(0, (int) ($settings['qa_min_score'] ?? 70))),
        );
    }
}
