<?php

declare(strict_types=1);

namespace App\Billing\Controller;

use App\Billing\Entity\Payment;
use App\Billing\Enum\PaymentStatus;
use App\Billing\Payment\PaymentWebhookProcessor;
use App\Billing\Payment\Provider\MockPaymentProvider;
use App\Billing\Service\PaymentService;
use App\Security\Voter\CustomerResourceVoter;
use App\Shared\Controller\CsrfGuardTrait;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Checkout page of the mock payment gateway (development/tests): no card, no
 * money. The outcome is sent back as a SIGNED webhook processed exactly like a
 * real gateway notification.
 */
#[IsGranted('ROLE_CUSTOMER')]
final class MockPaymentController extends AbstractController
{
    use CsrfGuardTrait;

    #[Route(['fr' => '/fr/paiement-simule/{key}', 'en' => '/en/mock-payment/{key}', 'ar' => '/ar/mock-payment/{key}'], name: 'payment_mock_checkout', requirements: ['key' => 'pay-ORD-[0-9]{4}-[A-Z0-9]{6}-[0-9]+'], methods: ['GET', 'POST'])]
    public function checkout(
        Request $request,
        #[MapEntity(mapping: ['key' => 'idempotencyKey'])] Payment $payment,
        PaymentService $payments,
        MockPaymentProvider $driver,
        PaymentWebhookProcessor $webhooks,
        TranslatorInterface $translator,
    ): Response {
        $order = $payment->getOrder();
        $this->denyAccessUnlessGranted(CustomerResourceVoter::VIEW, $order);
        $provider = $payments->provider($payment->getProvider());
        if ('mock' !== $provider->getDriver()) {
            throw $this->createNotFoundException();
        }
        if (PaymentStatus::Pending !== $payment->getStatus()) {
            return $this->redirectToRoute('account_order', ['number' => $order->getNumber()]);
        }

        if ($request->isMethod('POST')) {
            $this->denyUnlessCsrfValid('mock-pay-'.$payment->getIdempotencyKey(), $request);
            $success = 'success' === $request->getPayload()->getString('outcome');
            $event = $driver->simulatedEvent($provider, $payment, $success);
            $notification = Request::create('/webhooks/payment/'.$provider->getCode(), 'POST', server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_'.strtoupper(str_replace('-', '_', MockPaymentProvider::SIGNATURE_HEADER)) => $event['signature'],
                'REMOTE_ADDR' => '127.0.0.1',
            ], content: $event['payload']);
            $result = $webhooks->process($provider->getCode(), $notification);
            $this->addFlash(Response::HTTP_OK === $result['status'] && $success ? 'success' : 'error', $translator->trans($success ? 'payment.mock.succeeded' : 'payment.mock.failed'));

            return $this->redirectToRoute('account_order', ['number' => $order->getNumber()]);
        }

        return $this->render('payment/mock_checkout.html.twig', ['payment' => $payment, 'order' => $order]);
    }
}
