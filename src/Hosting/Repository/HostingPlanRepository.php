<?php

declare(strict_types=1);

namespace App\Hosting\Repository;

use App\Hosting\Entity\HostingPlan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HostingPlan>
 */
class HostingPlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HostingPlan::class);
    }
}
