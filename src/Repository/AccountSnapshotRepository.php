<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccountSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccountSnapshot>
 */
class AccountSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountSnapshot::class);
    }

    public function save(AccountSnapshot $snapshot, bool $flush = true): void
    {
        $this->getEntityManager()->persist($snapshot);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findLatest(): ?AccountSnapshot
    {
        return $this->findOneBy([], ['timestamp' => 'DESC']);
    }

    /**
     * @return AccountSnapshot[]
     */
    public function findRecent(int $limit = 100): array
    {
        return $this->createQueryBuilder('a')
            ->orderBy('a.timestamp', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return AccountSnapshot[]
     */
    public function findByDateRange(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.timestamp >= :start')
            ->andWhere('a.timestamp <= :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('a.timestamp', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
