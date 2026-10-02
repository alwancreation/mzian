<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing;

use App\Pricing\PriceLineType;
use App\Pricing\PricingEngine;
use App\Pricing\PricingPolicy;
use App\Pricing\PricingRequest;
use PHPUnit\Framework\TestCase;

final class PricingEngineTest extends TestCase
{
    private PricingEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new PricingEngine();
    }

    /**
     * Specification example: Hosting 40 + Domain 12 + AI 5 + Infra 10 + Development 120
     * + minimum margin 50 = 237 (cost 187, margin 50).
     */
    public function testSpecificationExample(): void
    {
        $policy = new PricingPolicy(minimumMargin: 5000, targetMargin: 0, paymentFeePercent: 0, paymentFeeFixed: 0, infrastructureCost: 1000, emailCostPerAccount: 0, aiCost: ['low' => 500, 'medium' => 500, 'high' => 500]);
        $breakdown = $this->engine->calculate(new PricingRequest(
            'car_rental_management', 'Car Rental Management', 12000, 'low',
            hosting: ['code' => 'starter', 'label' => 'Hosting 1 year', 'price' => 4000],
            domain: ['name' => 'atlas-cars.ma', 'label' => 'Domain', 'price' => 1200],
        ), $policy);

        self::assertSame(23700, $breakdown->sellingPrice);
        self::assertSame(18700, $breakdown->costPrice);
        self::assertSame(5000, $breakdown->margin);
        self::assertSame(21.1, $breakdown->marginPercentage);
        self::assertSame(23700, array_sum(array_map(static fn ($l) => $l->price, $breakdown->customerLines())), 'Customer lines add up to the price');
        self::assertSame(4000, $breakdown->line('hosting')?->price, 'Hosting is shown at cost');
    }

    public function testMinimumMarginIsAlwaysGuaranteedWithFeesAndRounding(): void
    {
        foreach ([0, 1, 999, 4567, 12345, 99999] as $base) {
            foreach ([0.0, 1.5, 2.9, 4.0] as $fee) {
                $policy = new PricingPolicy(minimumMargin: 5000, targetMargin: 0, paymentFeePercent: $fee, paymentFeeFixed: 30, rounding: 100);
                $breakdown = $this->engine->calculate(new PricingRequest('x', 'X', $base), $policy);

                self::assertGreaterThanOrEqual(5000, $breakdown->margin, "base {$base}, fee {$fee}");
                self::assertSame($breakdown->sellingPrice - $breakdown->costPrice, $breakdown->margin);
                self::assertSame(0, $breakdown->sellingPrice % 100, 'Rounded to whole units');
            }
        }
    }

    public function testTargetMarginAndRate(): void
    {
        $policy = new PricingPolicy(minimumMargin: 5000, targetMargin: 8000, paymentFeePercent: 0, paymentFeeFixed: 0, infrastructureCost: 0, aiCost: ['low' => 0, 'medium' => 0, 'high' => 0]);
        self::assertSame(8000, $this->engine->calculate(new PricingRequest('x', 'X', 10000), $policy)->margin);

        $policy = new PricingPolicy(minimumMargin: 5000, targetMargin: 0, targetMarginRate: 40, paymentFeePercent: 0, paymentFeeFixed: 0, infrastructureCost: 0, aiCost: ['low' => 0, 'medium' => 0, 'high' => 0]);
        self::assertSame(20000, $this->engine->calculate(new PricingRequest('x', 'X', 50000), $policy)->margin, '40% of a 500 cost');
    }

    public function testPaymentFeesAreCoveredByThePrice(): void
    {
        $policy = new PricingPolicy(minimumMargin: 5000, targetMargin: 0, paymentFeePercent: 2.9, paymentFeeFixed: 30, infrastructureCost: 0, aiCost: ['low' => 0, 'medium' => 0, 'high' => 0], rounding: 1);
        $breakdown = $this->engine->calculate(new PricingRequest('x', 'X', 10000), $policy);

        $fees = $breakdown->costOf(PriceLineType::PaymentFees);
        self::assertSame((int) ceil($breakdown->sellingPrice * 0.029) + 30, $fees);
        self::assertGreaterThanOrEqual(5000, $breakdown->sellingPrice - 10000 - $fees);
    }

    public function testComplexityOptionsAndCustomFeatures(): void
    {
        $policy = new PricingPolicy(minimumMargin: 5000, targetMargin: 0, paymentFeePercent: 0, paymentFeeFixed: 0, infrastructureCost: 0, aiCost: ['low' => 300, 'medium' => 500, 'high' => 800], customFeaturePrice: 8000);
        $low = $this->engine->calculate(new PricingRequest('x', 'X', 20000, 'low'), $policy);
        $high = $this->engine->calculate(new PricingRequest('x', 'X', 20000, 'high', [['code' => 'pdf', 'label' => 'PDF contracts', 'price' => 4000]], [['code' => 'drones', 'label' => 'drones']]), $policy);

        self::assertSame(20000 + 300 + 5000, $low->sellingPrice);
        self::assertSame(32000 + 800 + 4000 + 8000 + 5000, $high->sellingPrice);
        self::assertSame(4000, $high->line('option.pdf')?->price);
        self::assertSame('Custom development: drones', $high->line('custom.drones')?->label);
    }

    public function testInternalLinesAreHiddenFromCustomersAndSubscriptionIsRecurring(): void
    {
        $breakdown = $this->engine->calculate(new PricingRequest('x', 'X', 10000, subscription: ['code' => 'business', 'label' => 'Mzian Business', 'monthly' => 2900]), new PricingPolicy());
        $visibleCodes = array_map(static fn ($l) => $l->code, $breakdown->customerLines());

        self::assertNotContains('margin', $visibleCodes);
        self::assertNotContains('ai', $visibleCodes);
        self::assertNotContains('payment_fees', $visibleCodes);
        self::assertNotContains('subscription.business', $visibleCodes, 'Recurring lines are not part of the one-time total');
        self::assertSame(2900, $breakdown->recurringMonthly);
    }

    public function testPolicyFromSettingsConvertsMajorUnits(): void
    {
        $policy = PricingPolicy::fromSettings(['minimum_margin' => 50, 'target_margin' => 80, 'payment_fee_fixed' => 0.30, 'ai_cost' => ['low' => 3, 'medium' => 5, 'high' => 8], 'rounding' => 1]);

        self::assertSame(5000, $policy->minimumMargin);
        self::assertSame(8000, $policy->targetMargin);
        self::assertSame(30, $policy->paymentFeeFixed);
        self::assertSame(500, $policy->aiCostFor('medium'));
        self::assertSame(100, $policy->rounding);
    }

    public function testBudgetIncludesTheBuffer(): void
    {
        $policy = new PricingPolicy(budgetBufferPercent: 15);
        $breakdown = $this->engine->calculate(new PricingRequest('x', 'X', 10000), $policy);

        self::assertSame((int) ceil($breakdown->costPrice * 1.15), PricingEngine::budgetFor($breakdown, $policy));
    }
}
