<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Basket;
use App\Entity\Fill;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Fill>
 */
class FillRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Fill::class);
    }

    public function save(Fill $fill, bool $flush = true): void
    {
        $this->getEntityManager()->persist($fill);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @return Fill[]
     */
    public function findByBasket(Basket $basket): array
    {
        return $this->findBy(['basket' => $basket], ['executedAt' => 'DESC']);
    }

    /**
     * @return Fill[]
     */
    public function findBuyFillsByBasket(Basket $basket): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.basket = :basket')
            ->andWhere('f.side = :side')
            ->setParameter('basket', $basket)
            ->setParameter('side', 'BUY')
            ->orderBy('f.executedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Fill[]
     */
    public function findSellFillsByBasket(Basket $basket): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.basket = :basket')
            ->andWhere('f.side = :side')
            ->setParameter('basket', $basket)
            ->setParameter('side', 'SELL')
            ->orderBy('f.executedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function getTotalBuyQuantity(Basket $basket): float
    {
        $result = $this->createQueryBuilder('f')
            ->select('SUM(f.quantity) as total')
            ->where('f.basket = :basket')
            ->andWhere('f.side = :side')
            ->setParameter('basket', $basket)
            ->setParameter('side', 'BUY')
            ->getQuery()
            ->getSingleScalarResult();

        return (float)($result ?? 0);
    }

    public function getTotalSellQuantity(Basket $basket): float
    {
        $result = $this->createQueryBuilder('f')
            ->select('SUM(f.quantity) as total')
            ->where('f.basket = :basket')
            ->andWhere('f.side = :side')
            ->setParameter('basket', $basket)
            ->setParameter('side', 'SELL')
            ->getQuery()
            ->getSingleScalarResult();

        return (float)($result ?? 0);
    }
}
