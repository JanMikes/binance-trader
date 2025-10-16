<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Basket;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Basket>
 */
class BasketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Basket::class);
    }

    public function save(Basket $basket, bool $flush = true): void
    {
        $this->getEntityManager()->persist($basket);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findActiveBasket(): ?Basket
    {
        return $this->findOneBy(['status' => 'active'], ['createdAt' => 'DESC']);
    }

    /**
     * @return Basket[]
     */
    public function findActiveBaskets(): array
    {
        return $this->findBy(['status' => 'active'], ['createdAt' => 'DESC']);
    }

    public function findById(int $id): ?Basket
    {
        return $this->find($id);
    }
}
