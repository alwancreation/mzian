<?php

declare(strict_types=1);

namespace App\Analytics;

use App\Analytics\Entity\Visit;
use App\Billing\Entity\Payment;
use App\Billing\Enum\PaymentStatus;
use App\Lead\Entity\Lead;
use App\Order\Entity\Order;
use App\Order\Entity\Quote;
use App\Order\Enum\OrderStatus;
use App\Project\Entity\Project;
use App\Project\Entity\ProjectCostEntry;
use App\Project\Entity\ProjectEvent;
use App\Project\Enum\CostCategory;
use App\Project\Enum\ProjectStatus;
use App\Requirement\Entity\Requirement;
use App\Requirement\Enum\RequirementStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Business metrics for the admin dashboard (computed from the database, never estimated).
 * Amounts are in minor units.
 */
final readonly class AnalyticsService
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @return array<string, int|float|null>
     */
    public function metrics(\DateTimeImmutable $since): array
    {
        $visitors = $this->count(Visit::class, 'createdAt', $since);
        $leads = $this->count(Lead::class, 'createdAt', $since);
        $quotes = $this->count(Quote::class, 'createdAt', $since);
        $orders = $this->count(Order::class, 'createdAt', $since);

        $paid = $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(o.total), 0) AS revenue, COALESCE(SUM(o.margin), 0) AS margin, COUNT(o.id) AS paidOrders')
            ->from(Order::class, 'o')
            ->andWhere('o.status = :paid')->setParameter('paid', OrderStatus::Paid)
            ->andWhere('o.paidAt >= :since')->setParameter('since', $since)
            ->getQuery()->getSingleResult();

        $costs = $this->em->createQueryBuilder()
            ->select('c.category AS category, COALESCE(SUM(c.amount), 0) AS total')
            ->from(ProjectCostEntry::class, 'c')
            ->andWhere('c.createdAt >= :since')->setParameter('since', $since)
            ->groupBy('c.category')
            ->getQuery()->getArrayResult();
        $costByCategory = [];
        foreach ($costs as $row) {
            $key = $row['category'] instanceof CostCategory ? $row['category']->value : (string) $row['category'];
            $costByCategory[$key] = (int) $row['total'];
        }
        $infrastructureCost = ($costByCategory['hosting'] ?? 0) + ($costByCategory['domain'] ?? 0) + ($costByCategory['infrastructure'] ?? 0) + ($costByCategory['email'] ?? 0);
        $aiCost = $costByCategory['ai'] ?? 0;

        $delivered = $this->em->createQueryBuilder()
            ->select('p.approvedAt AS approvedAt, p.completedAt AS completedAt')
            ->from(Project::class, 'p')
            ->andWhere('p.status = :completed')->setParameter('completed', ProjectStatus::Completed)
            ->andWhere('p.completedAt >= :since')->setParameter('since', $since)
            ->getQuery()->getArrayResult();
        $durations = [];
        foreach ($delivered as $row) {
            if ($row['approvedAt'] instanceof \DateTimeImmutable && $row['completedAt'] instanceof \DateTimeImmutable) {
                $durations[] = $row['completedAt']->getTimestamp() - $row['approvedAt']->getTimestamp();
            }
        }

        $approved = $this->distinctProjectsEntering(ProjectStatus::Approved, $since);
        $failed = $this->distinctProjectsEntering(ProjectStatus::Failed, $since);

        return [
            'visitors' => $visitors,
            'leads' => $leads,
            'lead_conversion_rate' => $visitors > 0 ? round($leads * 100 / $visitors, 1) : null,
            'quotes' => $quotes,
            'orders' => $orders,
            'paid_orders' => (int) $paid['paidOrders'],
            'order_conversion_rate' => $leads > 0 ? round($orders * 100 / $leads, 1) : null,
            'revenue' => (int) $paid['revenue'],
            'expected_margin' => (int) $paid['margin'],
            'infrastructure_cost' => $infrastructureCost,
            'ai_cost' => $aiCost,
            'actual_margin' => (int) $paid['revenue'] - array_sum($costByCategory),
            'projects_delivered' => \count($delivered),
            'average_delivery_seconds' => [] !== $durations ? (int) round(array_sum($durations) / \count($durations)) : null,
            'failure_rate' => $approved > 0 ? round($failed * 100 / $approved, 1) : null,
        ];
    }

    /**
     * Visitor → Lead → Requirement → Quote → Order → Payment → Approved → Delivered.
     *
     * @return list<array{key: string, label: string, count: int}>
     */
    public function funnel(\DateTimeImmutable $since): array
    {
        $requirements = (int) $this->em->createQueryBuilder()
            ->select('COUNT(r.id)')->from(Requirement::class, 'r')
            ->andWhere('r.createdAt >= :since')->setParameter('since', $since)
            ->andWhere('r.status != :draft')->setParameter('draft', RequirementStatus::Draft)
            ->getQuery()->getSingleScalarResult();
        $payments = (int) $this->em->createQueryBuilder()
            ->select('COUNT(DISTINCT IDENTITY(p.order))')->from(Payment::class, 'p')
            ->andWhere('p.status = :ok')->setParameter('ok', PaymentStatus::Succeeded)
            ->andWhere('p.paidAt >= :since')->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();

        return [
            ['key' => 'visitor', 'label' => 'Visitors', 'count' => $this->count(Visit::class, 'createdAt', $since)],
            ['key' => 'lead', 'label' => 'Leads', 'count' => $this->count(Lead::class, 'createdAt', $since)],
            ['key' => 'requirement', 'label' => 'Requirements', 'count' => $requirements],
            ['key' => 'quote', 'label' => 'Quotes', 'count' => $this->count(Quote::class, 'createdAt', $since)],
            ['key' => 'order', 'label' => 'Orders', 'count' => $this->count(Order::class, 'createdAt', $since)],
            ['key' => 'payment', 'label' => 'Payments', 'count' => $payments],
            ['key' => 'approved', 'label' => 'Approved', 'count' => $this->distinctProjectsEntering(ProjectStatus::Approved, $since)],
            ['key' => 'delivered', 'label' => 'Delivered', 'count' => $this->distinctProjectsEntering(ProjectStatus::Completed, $since)],
        ];
    }

    /**
     * @param class-string $entity
     */
    private function count(string $entity, string $dateField, \DateTimeImmutable $since): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(x.id)')->from($entity, 'x')
            ->andWhere(\sprintf('x.%s >= :since', $dateField))->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();
    }

    private function distinctProjectsEntering(ProjectStatus $status, \DateTimeImmutable $since): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(DISTINCT IDENTITY(e.project))')->from(ProjectEvent::class, 'e')
            ->andWhere('e.toStatus = :status')->setParameter('status', $status)
            ->andWhere('e.createdAt >= :since')->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();
    }
}
