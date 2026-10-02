<?php

declare(strict_types=1);

namespace App\Billing\Service;

use App\Billing\Entity\Payment;
use App\Billing\Entity\Subscription;
use App\Billing\Enum\PaymentStatus;
use App\Billing\Payment\Dto\CheckoutUrls;
use App\Billing\Payment\Dto\PaymentEvent;
use App\Billing\Payment\PaymentProviderInterface;
use App\Billing\Repository\PaymentRepository;
use App\Billing\Repository\SubscriptionRepository;
use App\Lead\Entity\LeadActivity;
use App\Lead\Enum\LeadStatus;
use App\Lead\Service\LeadService;
use App\Notification\Enum\NotificationType;
use App\Notification\NotificationService;
use App\Order\Entity\Order;
use App\Project\Workflow\ProjectStateMachine;
use App\Provider\Entity\Provider;
use App\Provider\Enum\ProviderType;
use App\Provider\Exception\ProviderException;
use App\Provider\ProviderRegistry;
use App\Shared\Audit\AuditLogger;
use App\Shared\I18n\MoneyFormatter;
use App\Shared\Webhook\InvalidWebhookException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/**
 * Payments: checkout sessions, verified provider events, manual confirmation and
 * refunds. Idempotent: an event received twice never pays, invoices or notifies twice.
 *
 * On success: order PAID → invoice → (pending) subscription → project PAID →
 * PENDING_ADMIN_APPROVAL. Nothing is built before an administrator approves.
 */
final readonly class PaymentService
{
    public function __construct(
        private ProviderRegistry $providers,
        #[AutowireLocator('mzian.payment_provider', defaultIndexMethod: 'getDriver')]
        private ContainerInterface $drivers,
        private PaymentRepository $payments,
        private SubscriptionRepository $subscriptions,
        private InvoiceService $invoices,
        private ProjectStateMachine $stateMachine,
        private NotificationService $notifications,
        private LeadService $leads,
        private AuditLogger $audit,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<Provider>
     */
    public function availableProviders(): array
    {
        return array_values(array_filter($this->providers->enabled(ProviderType::Payment), fn (Provider $p) => $this->drivers->has($p->getDriver())));
    }

    public function provider(string $code): Provider
    {
        $provider = $this->providers->findByCode($code);
        if (null === $provider || ProviderType::Payment !== $provider->getType() || !$provider->isEnabled()) {
            throw ProviderException::permanent(\sprintf('Payment method "%s" is not available.', $code));
        }

        return $provider;
    }

    public function driver(Provider $provider): PaymentProviderInterface
    {
        if (!$this->drivers->has($provider->getDriver())) {
            throw ProviderException::permanent(\sprintf('No payment driver "%s" is installed.', $provider->getDriver()), $provider->getCode());
        }

        return $this->drivers->get($provider->getDriver());
    }

    /**
     * Starts (or resumes) a payment attempt and returns it with its checkout URL.
     *
     * @throws ProviderException
     */
    public function start(Order $order, string $providerCode, CheckoutUrls $urls): Payment
    {
        if (!$order->getStatus()->isPayable()) {
            throw new \DomainException(\sprintf('Order %s cannot be paid (%s).', $order->getNumber(), $order->getStatus()->value));
        }
        $provider = $this->provider($providerCode);
        $existing = $this->payments->findOneBy(['order' => $order, 'provider' => $provider->getCode(), 'status' => PaymentStatus::Pending], ['id' => 'DESC']);
        if (null !== $existing && null !== $existing->getCheckoutUrl()) {
            return $existing;
        }

        $attempt = $this->payments->count(['order' => $order]) + 1;
        $payment = new Payment($order, $provider->getCode(), \sprintf('pay-%s-%d', $order->getNumber(), $attempt));
        $this->em->persist($payment);
        try {
            $session = $this->driver($provider)->createCheckout($provider, $payment, $urls);
        } catch (ProviderException $e) {
            $payment->markFailed($e->getMessage());
            $this->em->flush();
            throw $e;
        }
        $payment->setProviderReference($session->providerReference);
        $payment->setCheckoutUrl($session->redirectUrl);
        $payment->setMethod($session->method);
        $payment->mergeMetadata(['offline' => $session->offline]);
        $this->audit->log('payment.started', $payment, null, ['provider' => $provider->getCode(), 'amount' => $payment->getAmount(), 'currency' => $payment->getCurrency()], ['order' => $order->getNumber()]);
        $this->em->flush();

        return $payment;
    }

    /**
     * Applies a verified provider event. Returns the payment concerned (null if unknown).
     *
     * @throws InvalidWebhookException when the event targets a payment of another provider
     */
    public function handle(Provider $provider, PaymentEvent $event): ?Payment
    {
        if (PaymentEvent::IGNORED === $event->type) {
            return null;
        }
        $payment = $this->find($provider, $event);
        if (null === $payment) {
            $this->logger->warning('Payment event for an unknown payment', ['provider' => $provider->getCode(), 'event' => $event->eventId]);

            return null;
        }

        match ($event->type) {
            PaymentEvent::SUCCEEDED => $this->succeed($payment, $event->amount, $event->currency, $event->summary + ['event' => $event->eventId]),
            PaymentEvent::FAILED => $this->fail($payment, $event->failureReason ?? 'Payment failed'),
            PaymentEvent::REFUNDED => $this->markRefunded($payment, ['event' => $event->eventId]),
            default => null,
        };

        return $payment;
    }

    /**
     * An administrator confirms an offline payment (bank transfer received).
     */
    public function confirmOffline(Payment $payment, string $note = ''): void
    {
        if (true !== ($payment->getMetadata()['offline'] ?? false)) {
            throw new \DomainException('Only offline payments (bank transfer) are confirmed manually.');
        }
        $this->succeed($payment, null, null, ['confirmed_manually' => true, 'note' => mb_substr($note, 0, 255)]);
    }

    /**
     * Refunds the successful payment of an order (e.g. project rejected by an administrator).
     *
     * @throws ProviderException
     */
    public function refund(Order $order, string $reason): ?Payment
    {
        $payment = $this->payments->findOneBy(['order' => $order, 'status' => PaymentStatus::Succeeded]);
        if (null === $payment) {
            return null;
        }
        $provider = $this->provider($payment->getProvider());
        $result = $this->driver($provider)->refund($provider, $payment, $payment->getAmount(), 'refund-'.$payment->getIdempotencyKey());
        $this->markRefunded($payment, ['refund' => $result->reference, 'refund_pending' => $result->pending, 'reason' => mb_substr($reason, 0, 255)]);

        return $payment;
    }

    private function find(Provider $provider, PaymentEvent $event): ?Payment
    {
        $payment = null;
        if (null !== $event->paymentKey) {
            $payment = $this->payments->findOneBy(['idempotencyKey' => $event->paymentKey]);
        }
        if (null === $payment && null !== $event->providerReference) {
            $payment = $this->payments->findOneBy(['provider' => $provider->getCode(), 'providerReference' => $event->providerReference]);
        }
        if (null !== $payment && $payment->getProvider() !== $provider->getCode()) {
            throw new InvalidWebhookException(\sprintf('Event of "%s" targets a payment of "%s".', $provider->getCode(), $payment->getProvider()));
        }

        return $payment;
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function succeed(Payment $payment, ?int $amount, ?string $currency, array $summary): void
    {
        if (PaymentStatus::Succeeded === $payment->getStatus()) {
            return; // already processed (webhook retried)
        }
        if (null !== $amount && ($amount !== $payment->getAmount() || strtoupper((string) $currency) !== $payment->getCurrency())) {
            $payment->markFailed(\sprintf('Amount mismatch: received %d %s, expected %d %s.', $amount, (string) $currency, $payment->getAmount(), $payment->getCurrency()));
            $this->audit->log('payment.amount_mismatch', $payment, null, ['amount' => $amount, 'currency' => $currency], ['order' => $payment->getOrder()->getNumber()]);
            $this->em->flush();
            $this->notifications->notifyAdmins(NotificationType::AdminAttentionRequired, ['project' => $payment->getOrder()->getProject()->getName(), 'reason' => 'payment amount mismatch on order '.$payment->getOrder()->getNumber()], $payment->getOrder()->getProject());

            return;
        }

        $order = $payment->getOrder();
        $project = $order->getProject();
        $payment->markSucceeded();
        $payment->mergeMetadata($summary);
        if (!$order->isPaid()) {
            $order->markPaid();
        }
        foreach ($this->payments->findBy(['order' => $order, 'status' => [PaymentStatus::Pending, PaymentStatus::RequiresAction]]) as $other) {
            if ($other !== $payment) {
                $other->cancel();
            }
        }
        $invoice = $this->invoices->issueForOrder($order, $payment);
        $plan = $order->getQuote()->getSubscriptionPlan();
        if (null !== $plan && null === $this->subscriptions->findOneBy(['project' => $project])) {
            // Starts at delivery (activated by the delivery agent).
            $this->em->persist(new Subscription($order->getCustomer(), $project, $plan, $order->getCurrency(), $payment->getProvider()));
        }
        $this->audit->log('payment.succeeded', $payment, null, ['amount' => $payment->getAmount(), 'currency' => $payment->getCurrency()], ['order' => $order->getNumber(), 'invoice' => $invoice->getNumber()]);

        $this->stateMachine->applyIfPossible($project, 'pay', \sprintf('Payment received (%s)', $payment->getProvider()), ['payment' => $payment->getIdempotencyKey()], false);
        $this->stateMachine->applyIfPossible($project, 'submit_for_approval', null, [], false);
        if (null !== $project->getLead()) {
            $this->leads->track($project->getLead(), LeadActivity::PAYMENT_RECEIVED, 'Payment received for '.$order->getNumber(), ['order' => $order->getNumber(), 'amount' => $payment->getAmount()], LeadStatus::Converted);
        }
        $this->em->flush();

        $user = $order->getCustomer()->getUser();
        $parameters = ['order' => $order->getNumber(), 'project' => $project->getName(), 'customer' => $order->getCustomer()->getDisplayName()];
        $this->notifications->notifyUser($user, NotificationType::PaymentConfirmed, $parameters + ['amount' => MoneyFormatter::format($payment->getAmount(), $payment->getCurrency(), $user->getLocale())], $project);
        $this->notifications->notifyAdmins(NotificationType::ApprovalRequired, $parameters + ['amount' => MoneyFormatter::format($payment->getAmount(), $payment->getCurrency(), 'en')], $project);
    }

    private function fail(Payment $payment, string $reason): void
    {
        if ($payment->getStatus()->isFinal()) {
            return;
        }
        $payment->markFailed($reason);
        $this->audit->log('payment.failed', $payment, null, ['reason' => $reason], ['order' => $payment->getOrder()->getNumber()]);
        $this->em->flush();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function markRefunded(Payment $payment, array $metadata): void
    {
        if (PaymentStatus::Refunded === $payment->getStatus()) {
            return;
        }
        $payment->markRefunded();
        $payment->mergeMetadata($metadata);
        $payment->getOrder()->cancel();
        $this->audit->log('payment.refunded', $payment, null, ['amount' => $payment->getAmount()], ['order' => $payment->getOrder()->getNumber()] + $metadata);
        $this->em->flush();
    }
}
