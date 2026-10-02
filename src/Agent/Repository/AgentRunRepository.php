<?php

declare(strict_types=1);

namespace App\Agent\Repository;

use App\Agent\Entity\AgentRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AgentRun>
 */
class AgentRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgentRun::class);
    }
}
