<?php

declare(strict_types=1);

namespace App\Hosting\Repository;

use App\Hosting\Entity\HostingAccount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HostingAccount>
 */
class HostingAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HostingAccount::class);
    }
}
