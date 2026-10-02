<?php

declare(strict_types=1);

namespace App\Lead\Repository;

use App\Lead\Entity\Lead;
use App\Lead\Enum\LeadStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Lead>
 */
class LeadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Lead::class);
    }

    public function findOpenByEmail(string $email): ?Lead
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.email = :email')
            ->andWhere('l.status != :lost')
            ->setParameter('email', mb_strtolower(trim($email)))
            ->setParameter('lost', LeadStatus::Lost)
            ->orderBy('l.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
