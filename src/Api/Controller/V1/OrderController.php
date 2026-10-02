<?php

declare(strict_types=1);

namespace App\Api\Controller\V1;

use App\Api\Dto\OrderRequest;
use App\Api\Presenter\CommercePresenter;
use App\Billing\Payment\Dto\CheckoutUrls;
use App\Billing\Service\PaymentService;
use App\Customer\Entity\Customer;
use App\Order\Entity\Order;
use App\Order\Repository\OrderRepository;
use App\Order\Repository\QuoteRepository;
use App\Order\Service\OrderService;
use App\Provider\Exception\ProviderException;
use App\Security\Entity\User;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Orders: place an order from a quote and get the payment checkout URL.
 * The project starts only after payment AND an administrator's approval.
 */
#[Route('/api/v1/orders')]
final class OrderController extends AbstractController
{
    #[Route('', name: 'api_order_list', methods: ['GET'])]
    public function list(OrderRepository $orders): JsonResponse
    {
        return $this->json(['orders' => array_map(CommercePresenter::order(...), $orders->findForCustomer($this->customer()))]);
    }

    #[Route('', name: 'api_order_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] OrderRequest $payload, QuoteRepository $quotes, OrderService $orders, PaymentService $payments): JsonResponse
    {
        $customer = $this->customer();
        $quote = $quotes->findOneBy(['token' => $payload->quote]);
        if (null === $quote || (null !== $quote->getProject()->getCustomer() && $quote->getProject()->getCustomer() !== $customer)) {
            throw $this->createNotFoundException('Quote not found.');
        }
        if (!\in_array($payload->paymentProvider, array_map(static fn ($p) => $p->getCode(), $payments->availableProviders()), true)) {
            throw new UnprocessableEntityHttpException('Unknown payment provider.');
        }

        try {
            $order = $orders->place($quote, $customer, $payload->subscription);
        } catch (\DomainException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }
        try {
            $payment = $payments->start($order, $payload->paymentProvider, new CheckoutUrls(
                $this->generateUrl('account_order', ['number' => $order->getNumber(), 'paid' => 1, '_locale' => $quote->getProject()->getLocale()], UrlGeneratorInterface::ABSOLUTE_URL),
                $this->generateUrl('account_order', ['number' => $order->getNumber(), '_locale' => $quote->getProject()->getLocale()], UrlGeneratorInterface::ABSOLUTE_URL),
            ));
        } catch (ProviderException $e) {
            throw new ServiceUnavailableHttpException(60, 'Payment provider unavailable, retry later. The order is saved: '.$order->getNumber(), $e);
        }

        return $this->json(CommercePresenter::order($order) + ['payment' => [
            'status' => $payment->getStatus()->value,
            'provider' => $payment->getProvider(),
            'checkout_url' => $payment->getCheckoutUrl(),
            'offline' => (bool) ($payment->getMetadata()['offline'] ?? false),
        ]], 201);
    }

    #[Route('/{number}', name: 'api_order_show', requirements: ['number' => 'ORD-[0-9]{4}-[A-Z0-9]{6}'], methods: ['GET'])]
    public function show(#[MapEntity(mapping: ['number' => 'number'])] Order $order): JsonResponse
    {
        if ($order->getCustomer() !== $this->customer()) {
            throw $this->createNotFoundException('Order not found.');
        }

        return $this->json(CommercePresenter::order($order));
    }

    private function customer(): Customer
    {
        $user = $this->getUser();

        return ($user instanceof User ? $user->getCustomer() : null) ?? throw $this->createAccessDeniedException('A customer API token is required.');
    }
}
