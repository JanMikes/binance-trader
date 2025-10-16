<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AccountSnapshotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AccountSnapshotRepository::class)]
#[ORM\Table(name: 'account_snapshots')]
class AccountSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $timestamp;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 8)]
    private string $quoteBalance;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 8)]
    private string $baseBalance;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 8)]
    private string $totalValue;

    public function __construct()
    {
        $this->timestamp = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTimestamp(): \DateTimeImmutable
    {
        return $this->timestamp;
    }

    public function setTimestamp(\DateTimeImmutable $timestamp): self
    {
        $this->timestamp = $timestamp;
        return $this;
    }

    public function getQuoteBalance(): string
    {
        return $this->quoteBalance;
    }

    public function setQuoteBalance(string $quoteBalance): self
    {
        $this->quoteBalance = $quoteBalance;
        return $this;
    }

    public function getBaseBalance(): string
    {
        return $this->baseBalance;
    }

    public function setBaseBalance(string $baseBalance): self
    {
        $this->baseBalance = $baseBalance;
        return $this;
    }

    public function getTotalValue(): string
    {
        return $this->totalValue;
    }

    public function setTotalValue(string $totalValue): self
    {
        $this->totalValue = $totalValue;
        return $this;
    }
}
