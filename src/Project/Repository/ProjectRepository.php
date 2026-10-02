<?php

declare(strict_types=1);

namespace App\Project\Repository;

use App\Customer\Entity\Customer;
use App\Project\Entity\Project;
use App\Project\Enum\ProjectStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Project>
 */
class ProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Project::class);
    }

    /**
     * @return list<Project>
     */
    public function findForCustomer(Customer $customer): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.solution', 's')->addSelect('s')
            ->andWhere('p.customer = :customer')
            ->setParameter('customer', $customer)
            ->orderBy('p.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<ProjectStatus> $statuses
     *
     * @return list<Project>
     */
    public function findByStatuses(array $statuses, int $limit = 100): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.customer', 'c')->addSelect('c')
            ->leftJoin('p.solution', 's')->addSelect('s')
            ->andWhere('p.status IN (:statuses)')
            ->setParameter('statuses', $statuses)
            ->orderBy('p.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<string, int> status value => count
     */
    public function countByStatus(): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.status AS status, COUNT(p.id) AS total')
            ->groupBy('p.status')
            ->getQuery()
            ->getArrayResult();
        $counts = [];
        foreach ($rows as $row) {
            $status = $row['status'] instanceof ProjectStatus ? $row['status']->value : (string) $row['status'];
            $counts[$status] = (int) $row['total'];
        }

        return $counts;
    }

    public function nextSequence(): int
    {
        return (int) $this->createQueryBuilder('p')->select('COALESCE(MAX(p.id), 0)')->getQuery()->getSingleScalarResult() + 1;
    }
}
