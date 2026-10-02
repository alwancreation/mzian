<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Customer\Entity\Customer;
use App\Domain\Entity\Domain;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Domain>
 */
class DomainRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Domain::class);
    }

    /**
     * @return list<Domain>
     */
    public function findForCustomer(Customer $customer): array
    {
        return $this->createQueryBuilder('d')
            ->join('d.project', 'p')
            ->andWhere('p.customer = :customer')
            ->setParameter('customer', $customer)
            ->orderBy('d.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
