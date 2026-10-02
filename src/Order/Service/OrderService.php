<?php

declare(strict_types=1);

namespace App\Order\Service;

use App\Billing\Repository\SubscriptionPlanRepository;
use App\Customer\Entity\Customer;
use App\Lead\Entity\LeadActivity;
use App\Lead\Service\LeadService;
use App\Notification\Enum\NotificationType;
use App\Notification\NotificationService;
use App\Order\Entity\Order;
use App\Order\Entity\Quote;
use App\Order\Enum\OrderStatus;
use App\Order\Repository\OrderRepository;
use App\Project\Workflow\ProjectStateMachine;
use App\Shared\Audit\AuditLogger;
use App\Shared\I18n\MoneyFormatter;
use App\Shared\Reference\ReferenceGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns an accepted quote into an order (QUOTED → ORDERED). The order freezes the
 * quote's price; the project only goes further once the payment is confirmed.
 */
final readonly class OrderService
{
    public const NO_SUBSCRIPTION = 'none';

    public function __construct(
        private OrderRepository $orders,
        private SubscriptionPlanRepository $plans,
        private ProjectStateMachine $stateMachine,
        private ReferenceGenerator $references,
        private LeadService $leads,
        private NotificationService $notifications,
        private AuditLogger $audit,
        private TranslatorInterface $translator,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @param string|null $subscriptionCode plan code, "none", or null to keep the recommended plan
     *
     * @throws \DomainException when the quote can no longer be ordered
     */
    public function place(Quote $quote, Customer $customer, ?string $subscriptionCode = null): Order
    {
        $project = $quote->getProject();
        $existing = $project->getOrder();
        if (null !== $existing) {
            if ($existing->getQuote() === $quote && $existing->getCustomer() === $customer && OrderStatus::PendingPayment === $existing->getStatus()) {
                return $existing; // double submit: same pending order
            }
            throw new \DomainException('This project has already been ordered.');
        }
        if (null !== $project->getCustomer() && $project->getCustomer() !== $customer) {
            throw new \DomainException('This quote belongs to another customer.');
        }
        if (!$quote->isAcceptable() || $project->getCurrentQuote() !== $quote) {
            throw new \DomainException('This quote has expired or was replaced. Please refresh the price.');
        }

        if (null !== $subscriptionCode) {
            $plan = self::NO_SUBSCRIPTION === $subscriptionCode ? null : $this->plans->findOneBy(['code' => $subscriptionCode, 'enabled' => true]);
            if (self::NO_SUBSCRIPTION !== $subscriptionCode && null === $plan) {
                throw new \DomainException('Unknown subscription plan.');
            }
            $label = null === $plan ? '' : $this->translator->trans('pricing.line.subscription', ['%plan%' => $plan->getName($project->getLocale())], 'messages', $project->getLocale());
            $quote->changeSubscription($plan, $label);
        }

        $project->setCustomer($customer);
        $quote->getRequirement()->setCustomer($quote->getRequirement()->getCustomer() ?? $customer);
        $project->getLead()?->attachCustomer($customer);
        $number = $this->references->order();
        // Guarded while the quote is still "issued" (valid, current, customer actor).
        $this->stateMachine->apply($project, 'order', \sprintf('Order %s placed', $number), ['order' => $number, 'quote' => $quote->getNumber()], false);
        $quote->accept();
        $order = new Order($number, $customer, $quote);
        $this->em->persist($order);
        $this->audit->log('order.created', $order, null, ['total' => $order->getTotal(), 'currency' => $order->getCurrency(), 'recurring_monthly' => $order->getRecurringMonthly()], ['quote' => $quote->getNumber(), 'project' => $project->getReference()]);
        if (null !== $project->getLead()) {
            $this->leads->track($project->getLead(), LeadActivity::ORDER_CREATED, 'Order '.$order->getNumber(), ['order' => $order->getNumber(), 'total' => $order->getTotal()]);
        }
        $this->em->flush();

        $user = $customer->getUser();
        $this->notifications->notifyUser($user, NotificationType::OrderReceived, [
            'order' => $order->getNumber(),
            'project' => $project->getName(),
            'amount' => MoneyFormatter::format($order->getTotal(), $order->getCurrency(), $user->getLocale()),
        ], $project);

        return $order;
    }

    public function findForCustomer(Customer $customer, string $number): ?Order
    {
        return $this->orders->findOneBy(['customer' => $customer, 'number' => $number]);
    }
}
