<?php

declare(strict_types=1);

namespace App\Hosting\Repository;

use App\Customer\Entity\Customer;
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

    /**
     * @return list<HostingAccount>
     */
    public function findForCustomer(Customer $customer): array
    {
        return $this->createQueryBuilder('h')
            ->join('h.project', 'p')
            ->andWhere('p.customer = :customer')
            ->setParameter('customer', $customer)
            ->orderBy('h.id', \SortDirection::Descending)
            ->getQuery()
            ->getResult();
    }
}
