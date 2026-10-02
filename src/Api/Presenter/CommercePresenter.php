<?php

declare(strict_types=1);

namespace App\Api\Presenter;

use App\Order\Entity\Order;
use App\Order\Entity\OrderItem;
use App\Order\Entity\Quote;
use App\Order\Entity\QuoteItem;
use App\Project\Entity\Project;
use App\Project\Entity\ProjectEvent;

/**
 * Public JSON shapes of the API v1 (amounts in minor units + currency).
 * Internal costs and margins are never exposed to customers.
 */
final class CommercePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function quote(Quote $quote): array
    {
        $snapshot = $quote->getPricingSnapshot();

        return [
            'token' => $quote->getToken(),
            'number' => $quote->getNumber(),
            'status' => $quote->getStatus()->value,
            'expired' => $quote->isExpired(),
            'valid_until' => $quote->getValidUntil()->format(\DATE_ATOM),
            'project' => $quote->getProject()->getReference(),
            'solution' => ['code' => $quote->getSolution()->getCode(), 'name' => $quote->getSolution()->getName($quote->getProject()->getLocale())],
            'features' => $quote->getFeatures(),
            'currency' => $quote->getCurrency(),
            'price' => $quote->getSellingPrice(),
            'recurring_monthly' => $quote->getRecurringMonthly(),
            'subscription_plan' => $quote->getSubscriptionPlan()?->getCode(),
            'hosting_plan' => $quote->getHostingPlan()?->getCode(),
            'domain' => $snapshot['domain'] ?? null,
            'warnings' => $snapshot['warnings'] ?? [],
            'lines' => array_map(static fn (QuoteItem $i) => ['code' => $i->getCode(), 'label' => $i->getLabel(), 'type' => $i->getType()->value, 'price' => $i->getPrice(), 'recurring' => $i->isRecurring()], $quote->getCustomerItems()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function order(Order $order): array
    {
        return [
            'number' => $order->getNumber(),
            'status' => $order->getStatus()->value,
            'project' => $order->getProject()->getReference(),
            'quote' => $order->getQuote()->getNumber(),
            'currency' => $order->getCurrency(),
            'total' => $order->getTotal(),
            'recurring_monthly' => $order->getRecurringMonthly(),
            'paid_at' => $order->getPaidAt()?->format(\DATE_ATOM),
            'created_at' => $order->getCreatedAt()->format(\DATE_ATOM),
            'items' => array_values(array_map(static fn (OrderItem $i) => ['code' => $i->getCode(), 'label' => $i->getLabel(), 'amount' => $i->getTotal(), 'recurring' => $i->isRecurring()], $order->getItems()->toArray())),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function project(Project $project): array
    {
        return [
            'reference' => $project->getReference(),
            'name' => $project->getName(),
            'solution' => $project->getSolution()?->getCode(),
            'features' => $project->getFeatures(),
            'domain' => $project->getDomainName(),
            'simulated' => $project->isSimulated(),
            'deployment_url' => $project->getDeploymentUrl(),
            'admin_url' => $project->getAdminUrl(),
            'order' => $project->getOrder()?->getNumber(),
            'created_at' => $project->getCreatedAt()->format(\DATE_ATOM),
        ] + self::projectStatus($project);
    }

    /**
     * @return array<string, mixed>
     */
    public static function projectStatus(Project $project, int $events = 10): array
    {
        $timeline = $project->getEvents()->toArray();
        usort($timeline, static fn (ProjectEvent $a, ProjectEvent $b) => [$b->getCreatedAt(), $b->getId()] <=> [$a->getCreatedAt(), $a->getId()]);
        $timeline = \array_slice($timeline, 0, $events); // newest first

        return [
            'status' => $project->getStatus()->value,
            'progress' => $project->getProgress(),
            'terminal' => $project->getStatus()->isTerminal(),
            'updated_at' => $project->getUpdatedAt()->format(\DATE_ATOM),
            'events' => array_map(static fn (ProjectEvent $e) => [
                'at' => $e->getCreatedAt()->format(\DATE_ATOM),
                'type' => $e->getType(),
                'transition' => $e->getTransition(),
                'from' => $e->getFromStatus()?->value,
                'to' => $e->getToStatus()?->value,
                'message' => $e->getMessage(),
            ], $timeline),
        ];
    }
}
