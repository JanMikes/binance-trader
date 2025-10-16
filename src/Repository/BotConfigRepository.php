<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BotConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BotConfig>
 */
class BotConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BotConfig::class);
    }

    public function save(BotConfig $config, bool $flush = true): void
    {
        $this->getEntityManager()->persist($config);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findByKey(string $key): ?BotConfig
    {
        return $this->findOneBy(['key' => $key]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getConfig(string $key): ?array
    {
        $config = $this->findByKey($key);
        return $config?->getValue();
    }

    /**
     * @param array<string, mixed> $value
     */
    public function setConfig(string $key, array $value): void
    {
        $config = $this->findByKey($key);

        if ($config === null) {
            $config = new BotConfig();
            $config->setKey($key);
        }

        $config->setValue($value);
        $this->save($config);
    }
}
