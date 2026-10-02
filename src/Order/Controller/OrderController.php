<?php

declare(strict_types=1);

namespace App\Order\Controller;

use App\Billing\Payment\Dto\CheckoutUrls;
use App\Billing\Repository\InvoiceRepository;
use App\Billing\Repository\PaymentRepository;
use App\Billing\Service\PaymentService;
use App\Order\Entity\Order;
use App\Provider\Exception\ProviderException;
use App\Security\Voter\CustomerResourceVoter;
use App\Shared\Controller\CsrfGuardTrait;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Customer area: order detail (payment status, bank transfer instructions) and new payment attempts.
 */
#[IsGranted('ROLE_CUSTOMER')]
final class OrderController extends AbstractController
{
    use CsrfGuardTrait;

    public function __construct(
        private readonly PaymentService $payments,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(['fr' => '/fr/compte/commandes/{number}', 'en' => '/en/account/orders/{number}', 'ar' => '/ar/account/orders/{number}'], name: 'account_order', requirements: ['number' => 'ORD-[0-9]{4}-[A-Z0-9]{6}'], methods: ['GET'])]
    public function show(#[MapEntity(mapping: ['number' => 'number'])] Order $order, PaymentRepository $payments, InvoiceRepository $invoices): Response
    {
        $this->denyAccessUnlessGranted(CustomerResourceVoter::VIEW, $order);
        $attempts = $payments->findBy(['order' => $order], ['id' => 'DESC']);
        $offline = null;
        foreach ($attempts as $payment) {
            if (true === ($payment->getMetadata()['offline'] ?? false) && !$payment->getStatus()->isFinal()) {
                $offline = ['payment' => $payment, 'provider' => $this->payments->provider($payment->getProvider())];
                break;
            }
        }

        return $this->render('order/show.html.twig', [
            'order' => $order,
            'project' => $order->getProject(),
            'payments' => $attempts,
            'offline' => $offline,
            'invoice' => $invoices->findOneBy(['order' => $order]),
            'providers' => $order->getStatus()->isPayable() ? $this->payments->availableProviders() : [],
        ]);
    }

    #[Route(['fr' => '/fr/compte/commandes/{number}/paiement', 'en' => '/en/account/orders/{number}/payment', 'ar' => '/ar/account/orders/{number}/payment'], name: 'account_order_pay', requirements: ['number' => 'ORD-[0-9]{4}-[A-Z0-9]{6}'], methods: ['POST'])]
    public function pay(Request $request, #[MapEntity(mapping: ['number' => 'number'])] Order $order): Response
    {
        $this->denyAccessUnlessGranted(CustomerResourceVoter::VIEW, $order);
        $this->denyUnlessCsrfValid('pay-'.$order->getNumber(), $request);
        if (!$order->getStatus()->isPayable()) {
            return $this->redirectToRoute('account_order', ['number' => $order->getNumber()]);
        }
        $providerCode = $request->getPayload()->getString('payment_provider');
        if (!\in_array($providerCode, array_map(static fn ($p) => $p->getCode(), $this->payments->availableProviders()), true)) {
            $this->addFlash('error', $this->translator->trans('checkout.error.payment_method'));

            return $this->redirectToRoute('account_order', ['number' => $order->getNumber()]);
        }

        try {
            $payment = $this->payments->start($order, $providerCode, new CheckoutUrls(
                $this->generateUrl('account_order', ['number' => $order->getNumber(), 'paid' => 1], UrlGeneratorInterface::ABSOLUTE_URL),
                $this->generateUrl('account_order', ['number' => $order->getNumber()], UrlGeneratorInterface::ABSOLUTE_URL),
            ));
        } catch (ProviderException) {
            $this->addFlash('error', $this->translator->trans('checkout.error.payment_unavailable'));

            return $this->redirectToRoute('account_order', ['number' => $order->getNumber()]);
        }

        return $this->redirect((string) $payment->getCheckoutUrl());
    }
}
