<?php

declare(strict_types=1);

namespace App\Order\Repository;

use App\Customer\Entity\Customer;
use App\Order\Entity\Order;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Order>
 */
class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    /**
     * @return list<Order>
     */
    public function findForCustomer(Customer $customer): array
    {
        return $this->findBy(['customer' => $customer], ['id' => 'DESC']);
    }
}
