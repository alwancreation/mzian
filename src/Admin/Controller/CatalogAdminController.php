<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Form\JsonTextareaType;
use App\Admin\Form\LocalizedTextType;
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
use App\Shared\Audit\AuditLogger;
use App\Shared\Controller\CsrfGuardTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Admin > Solution Catalog: solutions, features and prices, sectors, questionnaire, plans.
 * Every change is audited and invalidates the catalog cache.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/catalog', defaults: ['_locale' => 'en'])]
final class CatalogAdminController extends AbstractController
{
    use CsrfGuardTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CatalogProvider $catalog,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('', name: 'admin_catalog', methods: ['GET'])]
    public function index(Request $request, SolutionRepository $solutions, SectorRepository $sectors, QuestionRepository $questions, SubscriptionPlanRepository $plans): Response
    {
        $allQuestions = $questions->findBy([], ['position' => 'ASC', 'id' => 'ASC']);
        $grouped = [];
        foreach ($allQuestions as $question) {
            $grouped[$question->getSector()?->getCode() ?? '_all'][] = $question;
        }

        return $this->render('admin/catalog/index.html.twig', [
            'tab' => $request->query->getString('tab', 'solutions'),
            'solutions' => $solutions->findBy([], ['position' => 'ASC']),
            'sectors' => $sectors->findBy([], ['position' => 'ASC']),
            'questions' => $grouped,
            'plans' => $plans->findBy([], ['position' => 'ASC']),
        ]);
    }

    #[Route('/solutions/{id}', name: 'admin_catalog_solution', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function solution(Request $request, Solution $solution, SectorRepository $sectors): Response
    {
        $sectorChoices = [];
        foreach ($sectors->findBy([], ['position' => 'ASC']) as $sector) {
            $sectorChoices[$sector->getName('en')] = $sector->getCode();
        }
        $data = [
            'name' => $solution->getNameTranslations() + ['fr' => '', 'en' => '', 'ar' => ''],
            'shortDescription' => $solution->getShortDescriptionTranslations() + ['fr' => '', 'en' => '', 'ar' => ''],
            'description' => $solution->getDescriptionTranslations() + ['fr' => '', 'en' => '', 'ar' => ''],
            'slugs' => $solution->getSlugs() + ['fr' => $solution->getSlug(), 'en' => '', 'ar' => ''],
            'category' => $solution->getCategory(),
            'basePrice' => $solution->getBasePrice() / 100,
            'estimatedDevelopmentDays' => $solution->getEstimatedDevelopmentDays(),
            'maintenancePrice' => $solution->getMaintenancePrice() / 100,
            'applicationTemplate' => $solution->getApplicationTemplate(),
            'sectors' => $solution->getSectors(),
            'hostingRequirements' => $solution->getHostingRequirements(),
            'domainRequirements' => $solution->getDomainRequirements(),
            'icon' => $solution->getIcon(),
            'featured' => $solution->isFeatured(),
            'enabled' => $solution->isEnabled(),
            'position' => $solution->getPosition(),
        ];

        $form = $this->createFormBuilder($data)
            ->add('name', LocalizedTextType::class, ['label' => 'Name'])
            ->add('shortDescription', LocalizedTextType::class, ['label' => 'Short description', 'required' => false])
            ->add('description', LocalizedTextType::class, ['label' => 'Description', 'multiline' => true, 'required' => false])
            ->add('slugs', LocalizedTextType::class, ['label' => 'URL slugs'])
            ->add('category', EnumType::class, ['class' => SolutionCategory::class])
            ->add('basePrice', MoneyType::class, ['currency' => 'USD', 'label' => 'Base price (development & setup)', 'constraints' => [new Assert\PositiveOrZero()]])
            ->add('estimatedDevelopmentDays', IntegerType::class, ['constraints' => [new Assert\Positive()]])
            ->add('maintenancePrice', MoneyType::class, ['currency' => 'USD', 'label' => 'Maintenance / month', 'constraints' => [new Assert\PositiveOrZero()]])
            ->add('applicationTemplate', TextType::class, ['constraints' => [new Assert\Regex('/^[a-z0-9-]+$/')]])
            ->add('sectors', ChoiceType::class, ['choices' => $sectorChoices, 'multiple' => true, 'expanded' => false, 'required' => false, 'attr' => ['size' => 6]])
            ->add('hostingRequirements', JsonTextareaType::class)
            ->add('domainRequirements', JsonTextareaType::class)
            ->add('icon', TextType::class)
            ->add('position', IntegerType::class)
            ->add('featured', CheckboxType::class, ['required' => false])
            ->add('enabled', CheckboxType::class, ['required' => false])
            ->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $old = ['base_price' => $solution->getBasePrice(), 'days' => $solution->getEstimatedDevelopmentDays(), 'maintenance' => $solution->getMaintenancePrice(), 'enabled' => $solution->isEnabled()];
            $d = $form->getData();
            $solution->update(
                array_filter($d['name']), array_filter($d['shortDescription']), array_filter($d['description']), array_filter($d['slugs']),
                $d['category'], (int) round($d['basePrice'] * 100), (int) $d['estimatedDevelopmentDays'], (int) round($d['maintenancePrice'] * 100),
                $d['hostingRequirements'], $d['domainRequirements'], $d['applicationTemplate'], array_values($d['sectors']), $d['icon'], (bool) $d['featured'], (int) $d['position'],
            );
            $solution->setEnabled((bool) $d['enabled']);
            $this->audit->log('catalog.solution_updated', $solution, $old, ['base_price' => $solution->getBasePrice(), 'days' => $solution->getEstimatedDevelopmentDays(), 'maintenance' => $solution->getMaintenancePrice(), 'enabled' => $solution->isEnabled()]);
            $this->em->flush();
            $this->catalog->invalidate();
            $this->addFlash('success', 'Solution saved.');

            return $this->redirectToRoute('admin_catalog_solution', ['id' => $solution->getId()]);
        }

        return $this->render('admin/catalog/solution.html.twig', ['solution' => $solution, 'form' => $form], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    /**
     * Inline update of the features table (included / price / weight / enabled) and feature creation.
     */
    #[Route('/solutions/{id}/features', name: 'admin_catalog_features', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function features(Request $request, Solution $solution): Response
    {
        $this->denyUnlessCsrfValid('features-'.$solution->getId(), $request);
        $rows = $request->request->all()['features'] ?? [];
        foreach ($solution->getFeatures() as $feature) {
            $row = $rows[$feature->getId()] ?? null;
            if (!\is_array($row)) {
                continue;
            }
            $name = ['fr' => (string) ($row['name_fr'] ?? ''), 'en' => (string) ($row['name_en'] ?? ''), 'ar' => (string) ($row['name_ar'] ?? '')];
            $feature->update(array_filter($name) ?: ['fr' => $feature->getCode()], [], isset($row['included']), max(0, (int) round((float) ($row['price'] ?? 0) * 100)), (int) ($row['weight'] ?? 1), isset($row['enabled']), (int) ($row['position'] ?? 0));
        }
        $newCode = trim($request->getPayload()->getString('new_code'));
        if ('' !== $newCode) {
            if (!preg_match('/^[a-z0-9_]{2,80}$/', $newCode) || null !== $solution->getFeature($newCode)) {
                $this->addFlash('error', 'Invalid or duplicated feature code.');
            } else {
                $feature = new SolutionFeature($solution, $newCode, ['fr' => $request->getPayload()->getString('new_name') ?: $newCode], false, max(0, (int) round((float) $request->getPayload()->getString('new_price') * 100)));
                $this->em->persist($feature);
            }
        }
        $this->audit->log('catalog.features_updated', $solution);
        $this->em->flush();
        $this->catalog->invalidate();
        $this->addFlash('success', 'Features saved.');

        return $this->redirectToRoute('admin_catalog_solution', ['id' => $solution->getId()]);
    }

    #[Route('/sectors/{id}', name: 'admin_catalog_sector', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function sector(Request $request, Sector $sector, SolutionRepository $solutions): Response
    {
        $solutionChoices = [];
        foreach ($solutions->findBy([], ['position' => 'ASC']) as $solution) {
            $solutionChoices[$solution->getName('en')] = $solution->getCode();
        }
        $form = $this->createFormBuilder([
            'name' => $sector->getNameTranslations() + ['fr' => '', 'en' => '', 'ar' => ''],
            'description' => ['fr' => $sector->getDescription('fr'), 'en' => $sector->getDescription('en'), 'ar' => $sector->getDescription('ar')],
            'slugs' => $sector->getSlugs() + ['fr' => '', 'en' => '', 'ar' => ''],
            'icon' => $sector->getIcon(),
            'defaultSolution' => $sector->getDefaultSolutionCode(),
            'position' => $sector->getPosition(),
            'enabled' => $sector->isEnabled(),
        ])
            ->add('name', LocalizedTextType::class)
            ->add('description', LocalizedTextType::class, ['multiline' => true, 'required' => false])
            ->add('slugs', LocalizedTextType::class)
            ->add('icon', TextType::class)
            ->add('defaultSolution', ChoiceType::class, ['choices' => $solutionChoices, 'required' => false])
            ->add('position', IntegerType::class)
            ->add('enabled', CheckboxType::class, ['required' => false])
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $d = $form->getData();
            $sector->update(array_filter($d['name']), array_filter($d['description']), array_filter($d['slugs']), $d['icon'], $d['defaultSolution'], (int) $d['position']);
            $sector->setEnabled((bool) $d['enabled']);
            $this->audit->log('catalog.sector_updated', $sector);
            $this->em->flush();
            $this->catalog->invalidate();
            $this->addFlash('success', 'Sector saved.');

            return $this->redirectToRoute('admin_catalog', ['tab' => 'sectors']);
        }

        return $this->render('admin/catalog/form.html.twig', ['title' => 'Sector: '.$sector->getName('en'), 'form' => $form, 'back' => $this->generateUrl('admin_catalog', ['tab' => 'sectors'])]);
    }

    #[Route('/questions/new', name: 'admin_catalog_question_new', methods: ['GET', 'POST'])]
    #[Route('/questions/{id}', name: 'admin_catalog_question', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function question(Request $request, SectorRepository $sectors, ?Question $question = null): Response
    {
        $sectorChoices = ['All sectors' => ''];
        foreach ($sectors->findBy([], ['position' => 'ASC']) as $sector) {
            $sectorChoices[$sector->getName('en')] = $sector->getCode();
        }
        $form = $this->createFormBuilder([
            'code' => $question?->getCode(),
            'sector' => $question?->getSector()?->getCode() ?? $request->query->getString('sector'),
            'type' => $question?->getType() ?? QuestionType::Boolean,
            'label' => $question ? ['fr' => $question->getLabel('fr'), 'en' => $question->getLabel('en'), 'ar' => $question->getLabel('ar')] : ['fr' => '', 'en' => '', 'ar' => ''],
            'help' => $question ? ['fr' => $question->getHelp('fr'), 'en' => $question->getHelp('en'), 'ar' => $question->getHelp('ar')] : ['fr' => '', 'en' => '', 'ar' => ''],
            'options' => $question?->getOptions() ?? [
                ['value' => 'yes', 'label' => ['fr' => 'Oui', 'en' => 'Yes', 'ar' => 'نعم'], 'features' => []],
                ['value' => 'no', 'label' => ['fr' => 'Non', 'en' => 'No', 'ar' => 'لا'], 'features' => []],
            ],
            'condition' => $question?->getCondition(),
            'position' => $question?->getPosition() ?? 50,
            'required' => $question?->isRequired() ?? true,
            'enabled' => $question?->isEnabled() ?? true,
        ])
            ->add('code', TextType::class, ['disabled' => null !== $question, 'constraints' => [new Assert\NotBlank(), new Assert\Regex('/^[a-z0-9_]{2,80}$/')]])
            ->add('sector', ChoiceType::class, ['choices' => $sectorChoices, 'disabled' => null !== $question, 'required' => false])
            ->add('type', EnumType::class, ['class' => QuestionType::class])
            ->add('label', LocalizedTextType::class)
            ->add('help', LocalizedTextType::class, ['required' => false])
            ->add('options', JsonTextareaType::class, ['help' => 'List of {"value", "label": {"fr","en","ar"}, "features": [...]}. Ignored for number/text questions.'])
            ->add('condition', TextType::class, ['required' => false, 'help' => "ExpressionLanguage, e.g. answers['contracts'] == 'yes' or has('online_payments')", 'attr' => ['class' => 'font-mono', 'dir' => 'ltr']])
            ->add('position', IntegerType::class)
            ->add('required', CheckboxType::class, ['required' => false])
            ->add('enabled', CheckboxType::class, ['required' => false])
            ->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $d = $form->getData();
            $condition = trim((string) $d['condition']);
            $valid = true;
            if ('' !== $condition && null !== $error = self::lintCondition($condition)) {
                $form->get('condition')->addError(new FormError('Invalid expression: '.$error));
                $valid = false;
            }
            foreach ($d['options'] as $option) {
                if (!\is_array($option) || !isset($option['value'], $option['label']) || !\is_array($option['label'])) {
                    $form->get('options')->addError(new FormError('Each option needs "value" and a "label" translation map.'));
                    $valid = false;
                    break;
                }
            }
            if ($valid) {
                if (null === $question) {
                    $sector = '' !== (string) $d['sector'] ? $sectors->findOneBy(['code' => $d['sector']]) : null;
                    $question = new Question((string) $d['code'], $sector, $d['type'], array_filter($d['label']));
                    $this->em->persist($question);
                }
                $question->update($d['type'], array_filter($d['label']), array_filter($d['help']), array_values($d['options']), $condition ?: null, (int) $d['position'], (bool) $d['required']);
                $question->setEnabled((bool) $d['enabled']);
                $this->audit->log('catalog.question_saved', $question, newValue: ['code' => $question->getCode(), 'condition' => $question->getCondition()]);
                $this->em->flush();
                $this->catalog->invalidate();
                $this->addFlash('success', 'Question saved.');

                return $this->redirectToRoute('admin_catalog', ['tab' => 'questions']);
            }
        }

        return $this->render('admin/catalog/form.html.twig', [
            'title' => null !== $question ? 'Question: '.$question->getCode() : 'New question',
            'form' => $form,
            'back' => $this->generateUrl('admin_catalog', ['tab' => 'questions']),
        ], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/plans/{id}', name: 'admin_catalog_plan', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function plan(Request $request, SubscriptionPlan $plan): Response
    {
        $form = $this->createFormBuilder([
            'name' => ['fr' => $plan->getName('fr'), 'en' => $plan->getName('en'), 'ar' => $plan->getName('ar')],
            'description' => ['fr' => $plan->getDescription('fr'), 'en' => $plan->getDescription('en'), 'ar' => $plan->getDescription('ar')],
            'price' => $plan->getPrice() / 100,
            'interval' => $plan->getInterval(),
            'includes' => $plan->getIncludes(),
            'recommended' => $plan->isRecommended(),
            'enabled' => $plan->isEnabled(),
            'position' => $plan->getPosition(),
        ])
            ->add('name', LocalizedTextType::class)
            ->add('description', LocalizedTextType::class, ['required' => false])
            ->add('price', MoneyType::class, ['currency' => 'USD', 'constraints' => [new Assert\PositiveOrZero()]])
            ->add('interval', EnumType::class, ['class' => BillingInterval::class])
            ->add('includes', ChoiceType::class, ['multiple' => true, 'expanded' => true, 'choices' => ['Hosting' => 'hosting', 'Maintenance' => 'maintenance', 'Support' => 'support', 'AI usage' => 'ai_usage', 'Premium features' => 'premium_features']])
            ->add('recommended', CheckboxType::class, ['required' => false])
            ->add('enabled', CheckboxType::class, ['required' => false])
            ->add('position', IntegerType::class)
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $d = $form->getData();
            $features = [];
            for ($i = 0; $i < 10; ++$i) {
                $feature = array_filter(['fr' => $plan->getFeatures('fr')[$i] ?? null, 'en' => $plan->getFeatures('en')[$i] ?? null, 'ar' => $plan->getFeatures('ar')[$i] ?? null]);
                if ([] !== $feature) {
                    $features[] = $feature;
                }
            }
            $old = ['price' => $plan->getPrice()];
            $plan->update(array_filter($d['name']), array_filter($d['description']), (int) round($d['price'] * 100), $d['interval'], $features, array_values($d['includes']), (bool) $d['recommended'], (bool) $d['enabled'], (int) $d['position']);
            $this->audit->log('catalog.plan_updated', $plan, $old, ['price' => $plan->getPrice()]);
            $this->em->flush();
            $this->addFlash('success', 'Plan saved.');

            return $this->redirectToRoute('admin_catalog', ['tab' => 'plans']);
        }

        return $this->render('admin/catalog/form.html.twig', ['title' => 'Plan: '.$plan->getName('en'), 'form' => $form, 'back' => $this->generateUrl('admin_catalog', ['tab' => 'plans'])]);
    }

    /**
     * Validates a questionnaire condition (syntax + allowed variables/functions).
     */
    public static function lintCondition(string $condition): ?string
    {
        $language = new ExpressionLanguage();
        $language->addFunction(new ExpressionFunction('has', static fn ($f) => $f, static fn (array $v, $f) => false));
        try {
            $language->lint($condition, ['answers', 'features']);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }
}
