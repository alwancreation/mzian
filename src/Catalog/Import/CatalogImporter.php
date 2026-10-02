<?php

declare(strict_types=1);

namespace App\Catalog\Import;

use App\Billing\Entity\SubscriptionPlan;
use App\Billing\Enum\BillingInterval;
use App\Billing\Repository\SubscriptionPlanRepository;
use App\Catalog\Entity\Question;
use App\Catalog\Entity\Sector;
use App\Catalog\Entity\Solution;
use App\Catalog\Entity\SolutionFeature;
use App\Catalog\Enum\QuestionType;
use App\Catalog\Enum\SolutionCategory;
use App\Catalog\Repository\QuestionRepository;
use App\Catalog\Repository\SectorRepository;
use App\Catalog\Repository\SolutionRepository;
use App\Catalog\Service\CatalogProvider;
use App\Shared\Settings\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Imports config/mzian/catalog.yaml and questionnaire.yaml into the database.
 * Idempotent: by default only missing items are created (admin edits are kept);
 * with $update = true existing items are overwritten by the YAML values.
 */
final readonly class CatalogImporter
{
    private const DEFAULT_YES_NO = [
        'yes' => ['fr' => 'Oui', 'en' => 'Yes', 'ar' => 'نعم'],
        'no' => ['fr' => 'Non', 'en' => 'No', 'ar' => 'لا'],
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private SectorRepository $sectors,
        private SolutionRepository $solutions,
        private QuestionRepository $questions,
        private SubscriptionPlanRepository $plans,
        private SettingsService $settings,
        private CatalogProvider $catalog,
        #[Autowire('%kernel.project_dir%/config/mzian')]
        private string $configDir,
    ) {
    }

    /**
     * @return array{sectors: int, solutions: int, features: int, questions: int, plans: int}
     */
    public function import(bool $update = false): array
    {
        $catalog = Yaml::parseFile($this->configDir.'/catalog.yaml');
        $questionnaire = Yaml::parseFile($this->configDir.'/questionnaire.yaml');
        $stats = ['sectors' => 0, 'solutions' => 0, 'features' => 0, 'questions' => 0, 'plans' => 0];

        $sectorEntities = [];
        foreach ($catalog['sectors'] as $data) {
            $sector = $this->sectors->findOneBy(['code' => $data['code']]);
            $isNew = null === $sector;
            if ($isNew) {
                $sector = new Sector($data['code'], $data['name']);
                $this->em->persist($sector);
                ++$stats['sectors'];
            }
            if ($isNew || $update) {
                $sector->update($data['name'], $data['description'] ?? [], $data['slugs'] ?? [], $data['icon'] ?? '✨', $data['default_solution'] ?? null, (int) ($data['position'] ?? 0));
            }
            $sectorEntities[$sector->getCode()] = $sector;
        }

        foreach ($catalog['solutions'] as $position => $data) {
            $solution = $this->solutions->findOneBy(['code' => $data['code']]);
            $isNew = null === $solution;
            if ($isNew) {
                $solution = new Solution($data['code'], $data['slugs']['fr'], $data['name'], SolutionCategory::from($data['category']), self::cents($data['base_price']), (int) $data['estimated_development_days'], $data['application_template']);
                $this->em->persist($solution);
                ++$stats['solutions'];
            }
            if ($isNew || $update) {
                $solution->update(
                    $data['name'],
                    $data['short_description'] ?? [],
                    $data['description'] ?? [],
                    $data['slugs'] ?? [],
                    SolutionCategory::from($data['category']),
                    self::cents($data['base_price']),
                    (int) $data['estimated_development_days'],
                    self::cents($data['maintenance_price'] ?? 0),
                    $data['hosting_requirements'] ?? [],
                    $data['domain_requirements'] ?? [],
                    $data['application_template'],
                    $data['sectors'] ?? [],
                    $data['icon'] ?? '🌐',
                    (bool) ($data['featured'] ?? false),
                    ($position + 1) * 10,
                );
            }
            foreach ($data['features'] ?? [] as $featurePosition => $featureData) {
                $feature = $solution->getFeature($featureData['code']);
                $featureIsNew = null === $feature;
                if ($featureIsNew) {
                    $feature = new SolutionFeature($solution, $featureData['code'], $featureData['name'], (bool) $featureData['included'], self::cents($featureData['price'] ?? 0));
                    ++$stats['features'];
                }
                if ($featureIsNew || $update) {
                    $feature->update($featureData['name'], $featureData['description'] ?? [], (bool) $featureData['included'], self::cents($featureData['price'] ?? 0), (int) ($featureData['weight'] ?? 1), true, ($featurePosition + 1) * 10);
                }
            }
        }
        $this->em->flush();

        foreach ($questionnaire['questions'] as $data) {
            $sector = null !== ($data['sector'] ?? null) ? ($sectorEntities[$data['sector']] ?? null) : null;
            if (null !== ($data['sector'] ?? null) && null === $sector) {
                throw new \InvalidArgumentException(\sprintf('Question "%s" references unknown sector "%s".', $data['code'], $data['sector']));
            }
            $question = $this->questions->findOneBy(['code' => $data['code'], 'sector' => $sector]);
            $isNew = null === $question;
            $type = QuestionType::from($data['type']);
            if ($isNew) {
                $question = new Question($data['code'], $sector, $type, $data['label']);
                $this->em->persist($question);
                ++$stats['questions'];
            }
            if ($isNew || $update) {
                $question->update($type, $data['label'], $data['help'] ?? [], $this->options($type, $data), $data['condition'] ?? null, (int) ($data['position'] ?? 0), (bool) ($data['required'] ?? true));
            }
        }

        foreach ($catalog['subscription_plans'] ?? [] as $data) {
            $plan = $this->plans->findOneBy(['code' => $data['code']]);
            $isNew = null === $plan;
            $interval = BillingInterval::from($data['interval']);
            if ($isNew) {
                $plan = new SubscriptionPlan($data['code'], $data['name'], self::cents($data['price']), $interval);
                $this->em->persist($plan);
                ++$stats['plans'];
            }
            if ($isNew || $update) {
                $plan->update($data['name'], $data['description'] ?? [], self::cents($data['price']), $interval, $data['features'] ?? [], $data['includes'] ?? [], (bool) ($data['recommended'] ?? false), true, (int) ($data['position'] ?? 0));
            }
        }
        $this->em->flush();

        if ($update || !$this->settings->has('analysis')) {
            $analysis = $this->settings->get('analysis');
            $analysis['solution_rules'] = $catalog['solution_rules'] ?? [];
            $this->settings->set('analysis', $analysis, 'catalog-import');
        }

        $this->catalog->invalidate();

        return $stats;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array{value: string, label: array<string, string>, features?: list<string>}>
     */
    private function options(QuestionType $type, array $data): array
    {
        if (QuestionType::Boolean === $type) {
            return [
                ['value' => 'yes', 'label' => self::DEFAULT_YES_NO['yes'], 'features' => $data['features_if_yes'] ?? []],
                ['value' => 'no', 'label' => self::DEFAULT_YES_NO['no'], 'features' => $data['features_if_no'] ?? []],
            ];
        }

        return array_map(static fn (array $o) => [
            'value' => (string) $o['value'],
            'label' => $o['label'],
            'features' => $o['features'] ?? [],
        ], $data['options'] ?? []);
    }

    private static function cents(int|float|string $major): int
    {
        return (int) round((float) $major * 100);
    }
}
