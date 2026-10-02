<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Catalog\Service\CatalogProvider;
use App\Pricing\PriceBreakdown;
use App\Pricing\PricingEngine;
use App\Pricing\PricingPolicy;
use App\Pricing\PricingRequest;
use App\Pricing\ProposalBuilder;
use App\Shared\Settings\SettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Admin > Pricing: margin rules and cost assumptions (super admins only can change
 * them), the resulting price of every solution, and a price simulator.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/pricing', defaults: ['_locale' => 'en'])]
final class PricingAdminController extends AbstractController
{
    private const COMPLEXITIES = ['low', 'medium', 'high'];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly ProposalBuilder $proposals,
        private readonly PricingEngine $engine,
        private readonly CatalogProvider $catalog,
    ) {
    }

    #[Route('', name: 'admin_pricing', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $canEdit = $this->isGranted('ROLE_SUPER_ADMIN');
        $form = $this->policyForm($this->settings->get('pricing'), !$canEdit);
        $form->handleRequest($request);
        if ($form->isSubmitted()) {
            $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');
            if ($form->isValid()) {
                $this->settings->set('pricing', $this->toSettings($form->getData()), $this->getUser()?->getUserIdentifier());
                $this->addFlash('success', 'Pricing policy saved. New proposals use it immediately; existing quotes keep their price.');

                return $this->redirectToRoute('admin_pricing');
            }
        }

        $policy = $this->proposals->policy();
        $solutions = [];
        foreach ($this->catalog->enabledSolutions() as $solution) {
            $solutions[] = ['solution' => $solution, 'breakdown' => $this->proposals->startingPrice($solution)];
        }

        return $this->render('admin/pricing/index.html.twig', [
            'form' => $form,
            'can_edit' => $canEdit,
            'policy' => $policy,
            'solutions' => $solutions,
            'simulation' => $this->simulate($request, $policy),
            'all_solutions' => $this->catalog->enabledSolutions(),
            'complexities' => self::COMPLEXITIES,
        ]);
    }

    /**
     * @return array{params: array<string, mixed>, breakdown: PriceBreakdown, budget: int}|null
     */
    private function simulate(Request $request, PricingPolicy $policy): ?array
    {
        $solution = $this->catalog->solution($request->query->getString('solution'));
        if (null === $solution) {
            return null;
        }
        $complexity = \in_array($request->query->getString('complexity'), self::COMPLEXITIES, true) ? $request->query->getString('complexity') : 'low';
        $selected = $request->query->all('options');
        $options = [];
        foreach ($solution->getEnabledFeatures() as $feature) {
            if (!$feature->isIncluded() && \in_array($feature->getCode(), $selected, true)) {
                $options[] = ['code' => $feature->getCode(), 'label' => $feature->getName('en'), 'price' => $feature->getPrice()];
            }
        }
        $customCount = max(0, min(10, $request->query->getInt('custom')));
        $custom = array_map(static fn (int $i) => ['code' => 'custom_'.$i, 'label' => 'Custom feature #'.$i], $customCount > 0 ? range(1, $customCount) : []);
        $hosting = $this->proposals->startingPrice($solution)->line('hosting');
        $domainCost = (int) round($request->query->getInt('domain') * 100);

        $breakdown = $this->engine->calculate(new PricingRequest(
            $solution->getCode(),
            $solution->getName('en'),
            $solution->getBasePrice(),
            $complexity,
            $options,
            $custom,
            null !== $hosting ? ['code' => 'hosting', 'label' => $hosting->label, 'price' => $hosting->cost] : null,
            $domainCost > 0 ? ['name' => 'domain', 'label' => 'Domain (1 year)', 'price' => $domainCost] : null,
            max(0, min(100, $request->query->getInt('emails'))),
        ), $policy);

        return [
            'params' => ['solution' => $solution, 'complexity' => $complexity, 'options' => $selected, 'custom' => $customCount, 'domain' => $domainCost, 'emails' => $request->query->getInt('emails')],
            'breakdown' => $breakdown,
            'budget' => PricingEngine::budgetFor($breakdown, $policy),
        ];
    }

    /**
     * @param array<string, mixed> $settings major units
     *
     * @return FormInterface<array<string, mixed>>
     */
    private function policyForm(array $settings, bool $readOnly): FormInterface
    {
        $currency = (string) ($settings['currency'] ?? 'USD');
        $data = [
            'minimum_margin' => (float) ($settings['minimum_margin'] ?? 0),
            'target_margin' => (float) ($settings['target_margin'] ?? 0),
            'target_margin_rate' => (float) ($settings['target_margin_rate'] ?? 0),
            'payment_fee_percent' => (float) ($settings['payment_fee_percent'] ?? 0),
            'payment_fee_fixed' => (float) ($settings['payment_fee_fixed'] ?? 0),
            'infrastructure_cost' => (float) ($settings['infrastructure_cost'] ?? 0),
            'email_cost_per_account' => (float) ($settings['email_cost_per_account'] ?? 0),
            'custom_feature_price' => (float) ($settings['custom_feature_price'] ?? 0),
            'rounding' => (float) ($settings['rounding'] ?? 1),
            'quote_validity_days' => (int) ($settings['quote_validity_days'] ?? 30),
            'budget_buffer_percent' => (float) ($settings['budget_buffer_percent'] ?? 0),
        ];
        foreach (self::COMPLEXITIES as $level) {
            $data['ai_cost_'.$level] = (float) ($settings['ai_cost'][$level] ?? 0);
            $data['multiplier_'.$level] = (float) ($settings['complexity_multipliers'][$level] ?? 1);
        }

        $money = static fn (string $label, string $help = '') => ['label' => $label, 'currency' => $currency, 'help' => $help, 'disabled' => $readOnly, 'constraints' => [new Assert\NotNull(), new Assert\PositiveOrZero()]];
        $number = static fn (string $label, float $min, float $max, string $help = '') => ['label' => $label, 'help' => $help, 'scale' => 2, 'disabled' => $readOnly, 'constraints' => [new Assert\NotNull(), new Assert\Range(min: $min, max: $max)]];

        $builder = $this->createFormBuilder($data, ['constraints' => [new Assert\Callback(static function (array $data, ExecutionContextInterface $context): void {
            if ($data['target_margin'] < $data['minimum_margin']) {
                $context->buildViolation('The target margin cannot be lower than the minimum margin.')->atPath('[target_margin]')->addViolation();
            }
            if ($data['rounding'] <= 0) {
                $context->buildViolation('The rounding step must be positive.')->atPath('[rounding]')->addViolation();
            }
        })]])
            ->add('minimum_margin', MoneyType::class, $money('Minimum margin', 'Guaranteed on every order, whatever the fees and rounding.'))
            ->add('target_margin', MoneyType::class, $money('Target margin'))
            ->add('target_margin_rate', NumberType::class, $number('Additional margin (% of cost)', 0, 300, '0 = disabled. The highest of minimum, target and rate applies.'))
            ->add('payment_fee_percent', NumberType::class, $number('Payment fees (%)', 0, 20))
            ->add('payment_fee_fixed', MoneyType::class, $money('Payment fees (fixed)'))
            ->add('infrastructure_cost', MoneyType::class, $money('Infrastructure cost per project', 'CI, monitoring, backups, previews.'))
            ->add('email_cost_per_account', MoneyType::class, $money('E-mail account (per year)', 'Only for accounts beyond those included in the hosting plan.'))
            ->add('custom_feature_price', MoneyType::class, $money('Custom feature (no template)'))
            ->add('rounding', MoneyType::class, $money('Round prices up to'))
            ->add('quote_validity_days', IntegerType::class, ['label' => 'Quote validity (days)', 'disabled' => $readOnly, 'constraints' => [new Assert\NotNull(), new Assert\Range(min: 1, max: 365)]])
            ->add('budget_buffer_percent', NumberType::class, $number('Project budget buffer (%)', 0, 100, 'Agents may spend up to the expected cost + this buffer.'));
        foreach (self::COMPLEXITIES as $level) {
            $builder->add('ai_cost_'.$level, MoneyType::class, $money('AI cost — '.$level.' complexity'));
        }
        foreach (self::COMPLEXITIES as $level) {
            $builder->add('multiplier_'.$level, NumberType::class, $number('Development multiplier — '.$level, 0.1, 10));
        }

        return $builder->getForm();
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function toSettings(array $data): array
    {
        $settings = $this->settings->get('pricing');
        foreach (['minimum_margin', 'target_margin', 'target_margin_rate', 'payment_fee_percent', 'payment_fee_fixed', 'infrastructure_cost', 'email_cost_per_account', 'custom_feature_price', 'rounding', 'budget_buffer_percent'] as $key) {
            $settings[$key] = round((float) $data[$key], 2);
        }
        $settings['quote_validity_days'] = (int) $data['quote_validity_days'];
        foreach (self::COMPLEXITIES as $level) {
            $settings['ai_cost'][$level] = round((float) $data['ai_cost_'.$level], 2);
            $settings['complexity_multipliers'][$level] = round((float) $data['multiplier_'.$level], 2);
        }
        // Validates the result: an invalid combination throws before anything is saved.
        PricingPolicy::fromSettings($settings);

        return $settings;
    }
}
