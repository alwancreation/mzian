<?php

declare(strict_types=1);

namespace App\Order\Controller;

use App\Billing\Payment\Dto\CheckoutUrls;
use App\Billing\Repository\SubscriptionPlanRepository;
use App\Billing\Service\PaymentService;
use App\Order\Entity\Quote;
use App\Order\Service\OrderService;
use App\Provider\Exception\ProviderException;
use App\Requirement\Service\RequirementAccess;
use App\Security\Entity\User;
use App\Shared\Controller\CsrfGuardTrait;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Quote → order → payment. An account is required to order (the visitor is sent
 * to the registration page and comes back here); nothing is built before payment
 * AND the administrator approval.
 */
final class CheckoutController extends AbstractController
{
    use CsrfGuardTrait;

    public function __construct(
        private readonly OrderService $orders,
        private readonly PaymentService $payments,
        private readonly RequirementAccess $access,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(['fr' => '/fr/commande/{token}', 'en' => '/en/checkout/{token}', 'ar' => '/ar/checkout/{token}'], name: 'order_checkout', requirements: ['token' => '[a-f0-9]{32}'], methods: ['GET', 'POST'])]
    public function checkout(Request $request, #[MapEntity(mapping: ['token' => 'token'])] Quote $quote, SubscriptionPlanRepository $plans): Response
    {
        $project = $quote->getProject();
        $user = $this->getUser();
        $customer = $user instanceof User ? $user->getCustomer() : null;
        $isOwner = null !== $customer && $project->getCustomer() === $customer;
        if (!$isOwner && !$this->access->canAccess($quote->getRequirement())) {
            throw $this->createNotFoundException();
        }
        if (!$user instanceof User) {
            $lead = $quote->getRequirement()->getLead();

            return $this->redirectToRoute('register', array_filter([
                'target' => $request->getRequestUri(),
                'email' => $lead?->getEmail(),
                'name' => null !== $lead ? explode(' ', (string) $lead->getFullName())[0] : null,
            ]));
        }
        if (null === $customer) {
            throw $this->createAccessDeniedException('Only customer accounts can order.');
        }
        if (null !== $project->getOrder()) {
            return $project->getOrder()->getCustomer() === $customer
                ? $this->redirectToRoute('account_order', ['number' => $project->getOrder()->getNumber()])
                : throw $this->createNotFoundException();
        }
        if (!$quote->isAcceptable() || $project->getCurrentQuote() !== $quote) {
            $this->addFlash('error', $this->translator->trans('checkout.expired'));

            return $this->redirectToRoute('start_analysis', ['token' => $quote->getRequirement()->getToken()]);
        }

        $providers = $this->payments->availableProviders();
        $selectedPlan = $quote->getSubscriptionPlan()?->getCode() ?? OrderService::NO_SUBSCRIPTION;
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->denyUnlessCsrfValid('checkout-'.$quote->getToken(), $request);
            $payload = $request->getPayload();
            $selectedPlan = $payload->getString('subscription', $selectedPlan);
            $providerCode = $payload->getString('payment_provider');
            if (!$payload->getBoolean('accept_terms')) {
                $errors[] = 'checkout.error.terms';
            }
            if (!\in_array($providerCode, array_map(static fn ($p) => $p->getCode(), $providers), true)) {
                $errors[] = 'checkout.error.payment_method';
            }
            if ([] === $errors) {
                try {
                    $order = $this->orders->place($quote, $customer, $selectedPlan);
                } catch (\DomainException) {
                    $this->addFlash('error', $this->translator->trans('checkout.expired'));

                    return $this->redirectToRoute('start_analysis', ['token' => $quote->getRequirement()->getToken()]);
                }
                try {
                    $payment = $this->payments->start($order, $providerCode, $this->checkoutUrls($order->getNumber(), $quote->getToken()));

                    return $this->redirect((string) $payment->getCheckoutUrl());
                } catch (ProviderException) {
                    $this->addFlash('error', $this->translator->trans('checkout.error.payment_unavailable'));

                    return $this->redirectToRoute('account_order', ['number' => $order->getNumber()]);
                }
            }
        }

        return $this->render('order/checkout.html.twig', [
            'quote' => $quote,
            'project' => $project,
            'plans' => $plans->findBy(['enabled' => true], ['position' => 'ASC']),
            'selected_plan' => $selectedPlan,
            'providers' => $providers,
            'errors' => $errors,
        ], new Response(status: [] === $errors ? 200 : 422));
    }

    private function checkoutUrls(string $orderNumber, string $quoteToken): CheckoutUrls
    {
        return new CheckoutUrls(
            $this->generateUrl('account_order', ['number' => $orderNumber, 'paid' => 1], UrlGeneratorInterface::ABSOLUTE_URL),
            $this->generateUrl('account_order', ['number' => $orderNumber], UrlGeneratorInterface::ABSOLUTE_URL),
        );
    }
}
