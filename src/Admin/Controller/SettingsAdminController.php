<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Agent\Budget\AutomationPolicy;
use App\Shared\Settings\SettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\CurrencyType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TimezoneType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Admin > Settings: the automation policy (what agents may spend without a human)
 * and the defaults of the generated applications. Super administrators only can
 * change them; every change is audited (SettingsService).
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/settings', defaults: ['_locale' => 'en'])]
final class SettingsAdminController extends AbstractController
{
    private const POLICY_AMOUNTS = [
        'max_hosting_cost' => ['Maximum hosting cost', 'Per purchase. Above it, the agent stops and asks an administrator.'],
        'max_domain_cost' => ['Maximum domain cost', 'Per domain and per year.'],
        'max_monthly_cost' => ['Maximum recurring cost', 'Per month (hosting renewals, services).'],
        'require_admin_approval_above' => ['Explicit approval above', 'Any single spending above this amount needs an administrator, whatever its type.'],
    ];

    public function __construct(private readonly SettingsService $settings)
    {
    }

    #[Route('', name: 'admin_settings', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $canEdit = $this->isGranted('ROLE_SUPER_ADMIN');
        $currency = (string) ($this->settings->get('pricing')['currency'] ?? 'USD');
        $policy = $this->policyForm($this->settings->get('automation_policy'), $currency, !$canEdit);
        $generation = $this->generationForm($this->settings->get('generation'), !$canEdit);

        foreach (['automation_policy' => $policy, 'generation' => $generation] as $key => $form) {
            $form->handleRequest($request);
            if (!$form->isSubmitted()) {
                continue;
            }
            $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');
            if ($form->isValid()) {
                /** @var array<string, mixed> $data */
                $data = $form->getData();
                $value = 'automation_policy' === $key ? $this->policySettings($data) : ['currency' => (string) $data['currency'], 'timezone' => (string) $data['timezone']];
                $this->settings->set($key, array_replace($this->settings->get($key), $value), $this->getUser()?->getUserIdentifier());
                $this->addFlash('success', 'automation_policy' === $key ? 'Automation policy saved: agents apply it from their next operation.' : 'Generation defaults saved: used by the next generated applications.');

                return $this->redirectToRoute('admin_settings');
            }
        }

        return $this->render('admin/settings/index.html.twig', [
            'policy_form' => $policy,
            'generation_form' => $generation,
            'can_edit' => $canEdit,
            'policy' => AutomationPolicy::fromSettings($this->settings->get('automation_policy')),
            'currency' => $currency,
            'overridden' => ['automation_policy' => $this->settings->has('automation_policy'), 'generation' => $this->settings->has('generation')],
        ]);
    }

    /**
     * @param array<string, mixed> $settings major units
     *
     * @return FormInterface<array<string, mixed>>
     */
    private function policyForm(array $settings, string $currency, bool $readOnly): FormInterface
    {
        $data = ['qa_min_score' => (int) ($settings['qa_min_score'] ?? 70)];
        foreach (array_keys(self::POLICY_AMOUNTS) as $key) {
            $data[$key] = (float) ($settings[$key] ?? 0);
        }
        $builder = $this->container->get('form.factory')->createNamedBuilder('automation_policy', FormType::class, $data);
        foreach (self::POLICY_AMOUNTS as $key => [$label, $help]) {
            $builder->add($key, MoneyType::class, ['label' => $label, 'help' => $help, 'currency' => $currency, 'disabled' => $readOnly, 'constraints' => [new Assert\NotNull(), new Assert\Range(min: 0, max: 100000)]]);
        }
        $builder->add('qa_min_score', IntegerType::class, ['label' => 'Minimum QA score (0-100)', 'help' => 'Critical checks (tests, availability, administration, HTTPS on real hosting) must pass whatever the score.', 'disabled' => $readOnly, 'constraints' => [new Assert\NotNull(), new Assert\Range(min: 0, max: 100)]]);

        return $builder->getForm();
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return FormInterface<array<string, mixed>>
     */
    private function generationForm(array $settings, bool $readOnly): FormInterface
    {
        $data = ['currency' => (string) ($settings['currency'] ?? 'MAD'), 'timezone' => (string) ($settings['timezone'] ?? 'UTC')];

        return $this->container->get('form.factory')->createNamedBuilder('generation', FormType::class, $data)
            ->add('currency', CurrencyType::class, ['label' => 'Currency of the generated websites', 'help' => 'Prices of the customer\'s own products and services (menus, rentals...).', 'disabled' => $readOnly, 'preferred_choices' => ['MAD', 'EUR', 'USD'], 'constraints' => [new Assert\NotBlank(), new Assert\Currency()]])
            ->add('timezone', TimezoneType::class, ['label' => 'Time zone', 'disabled' => $readOnly, 'preferred_choices' => ['Africa/Casablanca', 'Europe/Paris', 'UTC'], 'constraints' => [new Assert\NotBlank(), new Assert\Timezone()]])
            ->getForm();
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, int|float>
     */
    private function policySettings(array $data): array
    {
        $value = ['qa_min_score' => (int) $data['qa_min_score']];
        foreach (array_keys(self::POLICY_AMOUNTS) as $key) {
            $value[$key] = round((float) $data[$key], 2);
        }

        return $value;
    }
}
