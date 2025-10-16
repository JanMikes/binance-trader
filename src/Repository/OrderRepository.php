<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Basket;
use App\Entity\Order;
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

    public function save(Order $order, bool $flush = true): void
    {
        $this->getEntityManager()->persist($order);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findByClientOrderId(string $clientOrderId): ?Order
    {
        return $this->findOneBy(['clientOrderId' => $clientOrderId]);
    }

    /**
     * @return Order[]
     */
    public function findOpenOrdersByBasket(Basket $basket): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.basket = :basket')
            ->andWhere('o.status IN (:statuses)')
            ->setParameter('basket', $basket)
            ->setParameter('statuses', ['NEW', 'PARTIALLY_FILLED'])
            ->orderBy('o.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Order[]
     */
    public function findFilledOrdersByBasket(Basket $basket): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.basket = :basket')
            ->andWhere('o.status = :status')
            ->setParameter('basket', $basket)
            ->setParameter('status', 'FILLED')
            ->orderBy('o.filledAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Order[]
     */
    public function findAllByBasket(Basket $basket): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.basket = :basket')
            ->setParameter('basket', $basket)
            ->orderBy('o.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
