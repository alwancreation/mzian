<?php

declare(strict_types=1);

namespace App\AI\Analysis;

use App\Catalog\Entity\Solution;
use App\Catalog\Enum\SolutionCategory;
use App\Catalog\Service\CatalogProvider;
use App\Shared\Settings\SettingsService;
use Psr\Log\LoggerInterface;
use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Deterministic requirement analysis based on the catalog and the (admin editable)
 * solution rules. It powers the mock AI provider and is the safety net when a real
 * AI provider is unavailable or returns unusable output.
 */
final class RuleBasedAnalyzer
{
    private const COMPLEXITY_MULTIPLIERS = ['low' => 1.0, 'medium' => 1.3, 'high' => 1.7];

    private ExpressionLanguage $expressions;

    public function __construct(
        private readonly CatalogProvider $catalog,
        private readonly SettingsService $settings,
        private readonly TextSignalExtractor $extractor,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $aiLogger,
    ) {
        $this->expressions = new ExpressionLanguage();
        $this->expressions->addFunction(new ExpressionFunction(
            'has',
            static fn (string $f) => \sprintf('in_array(%s, $features, true)', $f),
            static fn (array $vars, string $f) => \in_array($f, $vars['features'], true),
        ));
    }

    /**
     * @return array<string, mixed> schema-shaped analysis (see requirement_analysis.schema.json)
     */
    public function analyze(RequirementInput $input): array
    {
        $signals = $this->extractor->extract($input->description);
        $sector = $input->businessType ?? $signals->sector;

        $requested = array_merge($input->requestedFeatures, $signals->wanted());
        $refused = array_merge($input->refusedFeatures, $signals->refused());
        $requested = array_values(array_unique(array_diff($requested, $refused)));

        $known = $this->catalog->knownFeatureCodes();
        $markers = array_values(array_filter($requested, static fn (string $f) => str_starts_with($f, 'no_')));
        $unknown = array_values(array_diff($requested, $known, $markers));

        $solution = $this->chooseSolution($sector, $requested, $unknown);

        [$features, $unsupported] = $this->mapFeatures($solution, array_values(array_diff($requested, $markers)));
        $complexity = $this->complexity($solution, $features, $unsupported, $input->numbers + $signals->numbers);
        $days = (int) ceil($solution->getEstimatedDevelopmentDays() * self::COMPLEXITY_MULTIPLIERS[$complexity] + 0.5 * \count($unsupported));

        $hosting = $solution->getHostingRequirements();
        $risks = [];
        if ([] !== $unsupported) {
            $risks[] = 'Features not covered by the template (custom work): '.implode(', ', $unsupported);
        }
        if ('high' === $complexity) {
            $risks[] = 'High complexity: review the scope before approval.';
        }
        if (SolutionCategory::Custom === $solution->getCategory()) {
            $risks[] = 'Custom application: the automated pipeline produces a scaffold, manual development is expected.';
        }

        $confidence = 0.6 + (null !== $sector ? 0.1 : 0) + (\count($requested) >= 2 ? 0.1 : 0) + (mb_strlen($input->description) > 40 ? 0.1 : 0);

        return [
            'solution_type' => self::solutionType($solution->getCategory()),
            'solution' => $solution->getCode(),
            'features' => $features,
            'complexity' => $complexity,
            'estimated_development_days' => max(1, min(120, $days)),
            'hosting_requirements' => [
                'storage_gb' => max(1, (int) ($hosting['storage_gb'] ?? 5)),
                'database' => (bool) ($hosting['database'] ?? false),
                'ssl' => (bool) ($hosting['ssl'] ?? true),
                'email_accounts' => max(0, (int) ($hosting['email_accounts'] ?? 1)),
            ],
            'domain_required' => $solution->isDomainRequired() || null !== $input->domain,
            'recommendation' => $this->recommendation($solution, $features, $unsupported, $days, $input),
            'confidence' => round(min(0.9, $confidence), 2),
            'unsupported_features' => $unsupported,
            'risks' => $risks,
        ];
    }

    /**
     * Maps requested features onto the solution: included features + requested ones the
     * solution offers (directly or through an equivalent). The rest is "unsupported".
     *
     * @param list<string> $requested
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    public function mapFeatures(Solution $solution, array $requested): array
    {
        $offered = array_map(static fn ($f) => $f->getCode(), $solution->getEnabledFeatures());
        $features = array_map(static fn ($f) => $f->getCode(), array_filter($solution->getEnabledFeatures(), static fn ($f) => $f->isIncluded()));
        $unsupported = [];
        $equivalents = $this->extractor->equivalents();
        foreach ($requested as $code) {
            if (\in_array($code, $offered, true)) {
                $features[] = $code;
                continue;
            }
            $equivalent = array_values(array_intersect($equivalents[$code] ?? [], $offered));
            if ([] !== $equivalent) {
                $features[] = $equivalent[0];
                continue;
            }
            $unsupported[] = $code;
        }

        return [array_values(array_unique($features)), array_values(array_unique($unsupported))];
    }

    /**
     * @param list<string> $requested
     * @param list<string> $unknown
     */
    private function chooseSolution(?string $sector, array $requested, array $unknown): Solution
    {
        $rules = $this->settings->get('analysis')['solution_rules'] ?? [];
        $variables = [
            'sector' => $sector ?? '',
            'features' => $requested,
            'features_count' => \count($requested),
            'unknown_count' => \count($unknown),
        ];
        foreach ($rules as $rule) {
            try {
                if ((bool) $this->expressions->evaluate((string) $rule['when'], $variables)) {
                    $solution = $this->catalog->solution((string) $rule['solution']);
                    if (null !== $solution) {
                        return $solution;
                    }
                }
            } catch (\Throwable $e) {
                $this->aiLogger->warning('Invalid solution rule ignored', ['rule' => $rule, 'error' => $e->getMessage()]);
            }
        }

        $default = $this->catalog->solution($this->catalog->sector($sector)?->getDefaultSolutionCode());

        return $default ?? $this->catalog->enabledSolutions()[0] ?? throw new \RuntimeException('The solution catalog is empty: run bin/console mzian:setup.');
    }

    /**
     * @param list<string>       $features
     * @param list<string>       $unsupported
     * @param array<string, int> $numbers
     */
    private function complexity(Solution $solution, array $features, array $unsupported, array $numbers): string
    {
        if (SolutionCategory::Custom === $solution->getCategory()) {
            return 'high';
        }
        $score = 3 * \count($unsupported);
        foreach ($features as $code) {
            $feature = $solution->getFeature($code);
            if (null !== $feature && !$feature->isIncluded()) {
                $score += $feature->getComplexityWeight();
            }
        }
        if (($numbers['fleet_size'] ?? 0) > 50 || ($numbers['rooms_count'] ?? 0) > 30 || ($numbers['products_count'] ?? 0) > 500 || ($numbers['staff_count'] ?? 0) > 15) {
            $score += 3;
        }

        return match (true) {
            $score <= 3 => 'low',
            $score <= 9 => 'medium',
            default => 'high',
        };
    }

    /**
     * @param list<string> $features
     * @param list<string> $unsupported
     */
    private function recommendation(Solution $solution, array $features, array $unsupported, int $days, RequirementInput $input): string
    {
        $names = [];
        foreach ($features as $code) {
            $names[] = $solution->getFeature($code)?->getName($input->locale) ?? $code;
        }
        $text = $this->translator->trans('analysis.recommendation', [
            '%solution%' => $solution->getName($input->locale),
            '%business%' => $input->businessName ?? $this->translator->trans('analysis.your_business', [], null, $input->locale),
            '%features%' => implode(', ', \array_slice($names, 0, 8)),
            '%days%' => $days,
        ], null, $input->locale);
        if ([] !== $unsupported) {
            $text .= ' '.$this->translator->trans('analysis.custom_features', ['%features%' => implode(', ', $unsupported)], null, $input->locale);
        }

        return $text;
    }

    public static function solutionType(SolutionCategory $category): string
    {
        return match ($category) {
            SolutionCategory::Website => 'website',
            SolutionCategory::Ecommerce => 'ecommerce',
            SolutionCategory::Booking => 'booking',
            SolutionCategory::Management => 'web_application',
            SolutionCategory::Crm => 'crm',
            SolutionCategory::Custom => 'custom',
        };
    }
}
