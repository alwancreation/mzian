<?php

declare(strict_types=1);

namespace App\Billing\Service;

use App\Billing\Entity\Invoice;
use App\Billing\Entity\Payment;
use App\Billing\Repository\InvoiceRepository;
use App\Order\Entity\Order;
use App\Order\Entity\OrderItem;
use App\Shared\Reference\ReferenceGenerator;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One paid invoice per paid order (idempotent). Taxes are not computed yet:
 * prices are shown and invoiced as final prices (see PRICING.md).
 */
final readonly class InvoiceService
{
    public function __construct(
        private InvoiceRepository $invoices,
        private ReferenceGenerator $references,
        private EntityManagerInterface $em,
    ) {
    }

    public function issueForOrder(Order $order, Payment $payment): Invoice
    {
        $existing = $this->invoices->findOneBy(['order' => $order]);
        if (null !== $existing) {
            return $existing;
        }

        $lines = [];
        foreach ($order->getItems() as $item) {
            /** @var OrderItem $item */
            if (!$item->isRecurring()) {
                $lines[] = ['label' => $item->getLabel(), 'amount' => $item->getTotal()];
            }
        }
        $customer = $order->getCustomer();
        $invoice = new Invoice($this->references->invoice(), $customer, $order, $order->getCurrency(), $lines, 0, [
            'name' => $customer->getUser()->getFullName(),
            'company' => $customer->getCompanyName(),
            'email' => $customer->getEmail(),
            'phone' => $customer->getPhone(),
            'city' => $customer->getCity(),
            'country' => $customer->getCountry(),
            'payment' => $payment->getMethod() ?? $payment->getProvider(),
        ]);
        $invoice->markPaid();
        $this->em->persist($invoice);

        return $invoice;
    }
}
