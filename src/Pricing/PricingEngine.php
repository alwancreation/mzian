<?php

declare(strict_types=1);

namespace App\Pricing;

/**
 * Computes the customer price from costs and the pricing policy.
 * Independent from the AI: the AI may recommend a solution, it never sets the price.
 *
 *   development = base price x complexity multiplier
 *   cost        = development + options + custom features + hosting + domain + e-mail
 *               + infrastructure + AI + payment fees
 *   margin      = max(minimum margin, target margin, cost x target rate)
 *   price       = (cost before fees + margin + fixed fee) / (1 - fee %), rounded up
 *
 * The margin can only grow through rounding: it is never below the minimum margin.
 */
final class PricingEngine
{
    public function calculate(PricingRequest $request, PricingPolicy $policy): PriceBreakdown
    {
        $lines = [];
        $development = (int) round($request->basePrice * $policy->multiplier($request->complexity));

        $optionsTotal = 0;
        $optionLines = [];
        foreach ($request->options as $option) {
            $optionLines[] = new PriceLine('option.'.$option['code'], $option['label'], PriceLineType::Feature, $option['price'], $option['price'], true);
            $optionsTotal += $option['price'];
        }
        foreach ($request->customFeatures as $custom) {
            $optionLines[] = new PriceLine('custom.'.$custom['code'], \sprintf($request->customFeatureLabel, $custom['label']), PriceLineType::Feature, $policy->customFeaturePrice, $policy->customFeaturePrice, true);
            $optionsTotal += $policy->customFeaturePrice;
        }

        $hosting = $request->hosting['price'] ?? 0;
        $domain = $request->domain['price'] ?? 0;
        $email = $policy->emailCostPerAccount * max(0, $request->emailAccounts);
        $infrastructure = $policy->infrastructureCost;
        $ai = $policy->aiCostFor($request->complexity);

        $costBeforeFees = $development + $optionsTotal + $hosting + $domain + $email + $infrastructure + $ai;
        $margin = max($policy->minimumMargin, $policy->targetMargin, (int) ceil($costBeforeFees * $policy->targetMarginRate / 100));

        $rate = $policy->paymentFeePercent / 100;
        $price = (int) ceil(($costBeforeFees + $margin + $policy->paymentFeeFixed) / (1 - $rate));
        $price = (int) (ceil($price / $policy->rounding) * $policy->rounding);
        $fees = (int) ceil($price * $rate) + ($price > 0 ? $policy->paymentFeeFixed : 0);
        $cost = $costBeforeFees + $fees;
        $margin = $price - $cost;

        // Rounding of the fee can cost a cent: keep the guarantee strictly.
        while ($margin < $policy->minimumMargin) {
            $price += $policy->rounding;
            $fees = (int) ceil($price * $rate) + $policy->paymentFeeFixed;
            $cost = $costBeforeFees + $fees;
            $margin = $price - $cost;
        }

        $passThrough = $hosting + $domain + $email + $optionsTotal;
        $lines[] = new PriceLine('solution', $request->solutionLabel, PriceLineType::Development, $development, $price - $passThrough, true);
        array_push($lines, ...$optionLines);
        if (null !== $request->hosting) {
            $lines[] = new PriceLine('hosting', $request->hosting['label'], PriceLineType::Hosting, $hosting, $hosting, true);
        }
        if (null !== $request->domain) {
            $lines[] = new PriceLine('domain', $request->domain['label'], PriceLineType::Domain, $domain, $domain, true);
        }
        if ($email > 0) {
            $lines[] = new PriceLine('email', $request->emailLabel, PriceLineType::Email, $email, $email, true);
        }
        $lines[] = new PriceLine('infrastructure', 'Infrastructure (CI, monitoring, backups)', PriceLineType::Infrastructure, $infrastructure, 0, false);
        $lines[] = new PriceLine('ai', 'AI / API usage (estimate)', PriceLineType::Ai, $ai, 0, false);
        $lines[] = new PriceLine('payment_fees', 'Payment fees', PriceLineType::PaymentFees, $fees, 0, false);
        $lines[] = new PriceLine('margin', 'Mzian margin', PriceLineType::Margin, 0, $margin, false);

        $recurring = 0;
        if (null !== $request->subscription) {
            $recurring = $request->subscription['monthly'];
            $lines[] = new PriceLine('subscription.'.$request->subscription['code'], $request->subscription['label'], PriceLineType::Subscription, 0, $recurring, true, true);
        }

        return new PriceBreakdown(
            $lines,
            $policy->currency,
            $cost,
            $price,
            $margin,
            $price > 0 ? round($margin * 100 / $price, 2) : 0.0,
            $recurring,
            ['policy' => $policy->toArray(), 'request' => get_object_vars($request)],
        );
    }

    /**
     * Budget the agents may spend on a project: expected cost + safety buffer.
     */
    public static function budgetFor(PriceBreakdown $breakdown, PricingPolicy $policy): int
    {
        return (int) ceil($breakdown->costPrice * (1 + $policy->budgetBufferPercent / 100));
    }
}
