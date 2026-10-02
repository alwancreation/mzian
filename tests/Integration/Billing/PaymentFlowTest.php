<?php

declare(strict_types=1);

namespace App\Tests\Integration\Billing;

use App\Billing\Entity\Invoice;
use App\Billing\Entity\Payment;
use App\Billing\Entity\Subscription;
use App\Billing\Enum\InvoiceStatus;
use App\Billing\Enum\PaymentStatus;
use App\Billing\Enum\SubscriptionStatus;
use App\Billing\Payment\Dto\CheckoutUrls;
use App\Billing\Payment\PaymentWebhookProcessor;
use App\Billing\Payment\Provider\MockPaymentProvider;
use App\Billing\Service\PaymentService;
use App\Notification\Entity\Notification;
use App\Notification\Enum\NotificationType;
use App\Order\Entity\Order;
use App\Order\Enum\OrderStatus;
use App\Project\Enum\ProjectStatus;
use App\Provider\ProviderRegistry;
use App\Shared\Entity\WebhookEvent;
use App\Shared\Webhook\WebhookSignature;
use App\Tests\Support\CommerceFixtureTrait;
use App\Tests\Support\Factory;
use App\Tests\Support\PlatformFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PaymentFlowTest extends KernelTestCase
{
    use CommerceFixtureTrait;
    use PlatformFixtureTrait;

    private PaymentService $payments;
    private PaymentWebhookProcessor $webhooks;
    private EntityManagerInterface $em;
    private Factory $factory;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->setUpPlatform();
        $container = static::getContainer();
        $this->payments = $container->get(PaymentService::class);
        $this->webhooks = $container->get(PaymentWebhookProcessor::class);
        $this->em = $container->get(EntityManagerInterface::class);
        $this->factory = new Factory($this->em, $container->get(UserPasswordHasherInterface::class));
    }

    private function urls(): CheckoutUrls
    {
        return new CheckoutUrls('https://mzian.test/ok', 'https://mzian.test/cancel');
    }

    private function order(?string $plan = null): Order
    {
        return $this->placeOrder($this->factory->customer(), $plan);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mockWebhook(string $type, array $data, ?string $secret = 'test-webhook-secret', string $id = 'evt_test_1'): Request
    {
        $payload = (string) json_encode(['id' => $id, 'type' => $type, 'data' => $data]);

        return Request::create('/webhooks/payment/mock_payment', 'POST', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_MZIAN_SIGNATURE' => null !== $secret ? WebhookSignature::sign($payload, $secret) : ''], content: $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function succeededData(Payment $payment, ?int $amount = null): array
    {
        return ['payment_key' => $payment->getIdempotencyKey(), 'reference' => $payment->getProviderReference(), 'amount' => $amount ?? $payment->getAmount(), 'currency' => $payment->getCurrency()];
    }

    public function testOrderingFreezesTheQuoteAndNotifiesTheCustomer(): void
    {
        $order = $this->order('pro');

        self::assertSame(OrderStatus::PendingPayment, $order->getStatus());
        self::assertSame(ProjectStatus::Ordered, $order->getProject()->getStatus());
        self::assertSame($order->getQuote()->getSellingPrice(), $order->getTotal());
        self::assertSame(4900, $order->getRecurringMonthly(), 'The customer chose the Pro plan.');
        $oneTime = array_filter($order->getItems()->toArray(), static fn ($i) => !$i->isRecurring());
        self::assertSame($order->getTotal(), array_sum(array_map(static fn ($i) => $i->getTotal(), $oneTime)), 'Order lines add up to the total.');
        self::assertCount(1, array_filter($order->getItems()->toArray(), static fn ($i) => $i->isRecurring()));
        self::assertMatchesRegularExpression('/^ORD-\d{4}-[A-Z0-9]{6}$/', $order->getNumber());
        self::assertSame(1, $this->em->getRepository(Notification::class)->count(['type' => NotificationType::OrderReceived]));
    }

    public function testSignedMockWebhookPaysInvoicesAndSubmitsForApproval(): void
    {
        $order = $this->order();
        $payment = $this->payments->start($order, 'mock_payment', $this->urls());
        self::assertStringContainsString('/paiement-simule/'.$payment->getIdempotencyKey(), (string) $payment->getCheckoutUrl());
        self::assertSame($payment, $this->payments->start($order, 'mock_payment', $this->urls()), 'A pending checkout is reused.');

        $result = $this->webhooks->process('mock_payment', $this->mockWebhook('payment.succeeded', $this->succeededData($payment)));

        self::assertSame(['status' => 200, 'result' => 'processed'], $result);
        self::assertSame(PaymentStatus::Succeeded, $payment->getStatus());
        self::assertTrue($order->isPaid());
        self::assertSame(ProjectStatus::PendingAdminApproval, $order->getProject()->getStatus(), 'Paid projects wait for the human approval.');

        $invoice = $this->em->getRepository(Invoice::class)->findOneBy(['order' => $order]);
        self::assertNotNull($invoice);
        self::assertSame(InvoiceStatus::Paid, $invoice->getStatus());
        self::assertSame($order->getTotal(), $invoice->getTotal());
        self::assertMatchesRegularExpression('/^INV-\d{4}-00001$/', $invoice->getNumber());

        $subscription = $this->em->getRepository(Subscription::class)->findOneBy(['project' => $order->getProject()]);
        self::assertSame(SubscriptionStatus::Pending, $subscription?->getStatus(), 'The plan starts at delivery.');
        self::assertSame(1, $this->em->getRepository(Notification::class)->count(['type' => NotificationType::PaymentConfirmed]));
        self::assertGreaterThanOrEqual(1, $this->em->getRepository(Notification::class)->count(['type' => NotificationType::ApprovalRequired]));

        // The gateway retries the same event: nothing is paid, invoiced or notified twice.
        $again = $this->webhooks->process('mock_payment', $this->mockWebhook('payment.succeeded', $this->succeededData($payment)));
        self::assertSame('duplicate', $again['result']);
        $replay = $this->webhooks->process('mock_payment', $this->mockWebhook('payment.succeeded', $this->succeededData($payment), id: 'evt_test_other'));
        self::assertSame('processed', $replay['result']);
        self::assertSame(1, $this->em->getRepository(Invoice::class)->count([]));
        self::assertSame(1, $this->em->getRepository(Notification::class)->count(['type' => NotificationType::PaymentConfirmed]));
    }

    public function testForgedOrTamperedWebhooksChangeNothing(): void
    {
        $order = $this->order();
        $payment = $this->payments->start($order, 'mock_payment', $this->urls());

        $forged = $this->webhooks->process('mock_payment', $this->mockWebhook('payment.succeeded', $this->succeededData($payment), 'attacker-secret'));
        self::assertSame(['status' => 401, 'result' => 'invalid_signature'], $forged);
        $unsigned = $this->webhooks->process('mock_payment', $this->mockWebhook('payment.succeeded', $this->succeededData($payment), null));
        self::assertSame(401, $unsigned['status']);
        self::assertSame(404, $this->webhooks->process('unknown_gateway', $this->mockWebhook('payment.succeeded', []))['status']);
        self::assertSame(0, $this->em->getRepository(WebhookEvent::class)->count([]));
        self::assertSame(PaymentStatus::Pending, $payment->getStatus());

        // Correctly signed but with another amount (e.g. a manipulated checkout): rejected, admins alerted.
        $this->webhooks->process('mock_payment', $this->mockWebhook('payment.succeeded', $this->succeededData($payment, 100)));
        self::assertSame(PaymentStatus::Failed, $payment->getStatus());
        self::assertStringContainsString('Amount mismatch', (string) $payment->getFailureReason());
        self::assertFalse($order->isPaid());
        self::assertSame(ProjectStatus::Ordered, $order->getProject()->getStatus());
        self::assertGreaterThanOrEqual(1, $this->em->getRepository(Notification::class)->count(['type' => NotificationType::AdminAttentionRequired]));
    }

    public function testDeclinedPaymentCanBeRetried(): void
    {
        $order = $this->order();
        $payment = $this->payments->start($order, 'mock_payment', $this->urls());
        $provider = $this->payments->provider('mock_payment');
        $event = static::getContainer()->get(MockPaymentProvider::class)->simulatedEvent($provider, $payment, false);
        $request = Request::create('/webhooks/payment/mock_payment', 'POST', server: ['HTTP_MZIAN_SIGNATURE' => $event['signature']], content: $event['payload']);

        self::assertSame('processed', $this->webhooks->process('mock_payment', $request)['result']);
        self::assertSame(PaymentStatus::Failed, $payment->getStatus());
        self::assertSame('Card declined (simulation)', $payment->getFailureReason());

        $retry = $this->payments->start($order, 'mock_payment', $this->urls());
        self::assertNotSame($payment, $retry);
        self::assertStringEndsWith('-2', $retry->getIdempotencyKey());
    }

    public function testBankTransferIsConfirmedByAnAdministratorOnly(): void
    {
        static::getContainer()->get(ProviderRegistry::class)->findByCode('bank_transfer')?->setEnabled(true);
        $this->em->flush();
        $order = $this->order();
        $payment = $this->payments->start($order, 'bank_transfer', $this->urls());

        self::assertTrue($payment->getMetadata()['offline']);
        self::assertSame('https://mzian.test/ok', $payment->getCheckoutUrl(), 'The customer is sent to the instructions page.');
        self::assertSame(401, $this->webhooks->process('bank_transfer', $this->mockWebhook('payment.succeeded', $this->succeededData($payment)))['status'], 'No webhook can confirm a bank transfer.');

        $admin = $this->factory->admin();
        $this->as(self::adminActor($admin), fn () => $this->payments->confirmOffline($payment, 'Transfer received'));
        self::assertTrue($order->isPaid());
        self::assertSame(ProjectStatus::PendingAdminApproval, $order->getProject()->getStatus());
    }

    public function testRefundMarksPaymentAndOrderRefunded(): void
    {
        $order = $this->order();
        $payment = $this->payments->start($order, 'mock_payment', $this->urls());
        $this->webhooks->process('mock_payment', $this->mockWebhook('payment.succeeded', $this->succeededData($payment)));

        $refunded = $this->payments->refund($order, 'Project rejected');

        self::assertSame($payment, $refunded);
        self::assertSame(PaymentStatus::Refunded, $payment->getStatus());
        self::assertSame(OrderStatus::Refunded, $order->getStatus());
        self::assertStringStartsWith('mock_re_', (string) $payment->getMetadata()['refund']);
    }
}
