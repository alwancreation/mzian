<?php

declare(strict_types=1);

namespace App\Agent\Repository;

use App\Agent\Entity\AgentTask;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AgentTask>
 */
class AgentTaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgentTask::class);
    }
}
