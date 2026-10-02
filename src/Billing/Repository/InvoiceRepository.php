<?php

declare(strict_types=1);

namespace App\Billing\Repository;

use App\Billing\Entity\Invoice;
use App\Customer\Entity\Customer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Invoice>
 */
class InvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invoice::class);
    }

    /**
     * @return list<Invoice>
     */
    public function findForCustomer(Customer $customer): array
    {
        return $this->findBy(['customer' => $customer], ['id' => 'DESC']);
    }
}
