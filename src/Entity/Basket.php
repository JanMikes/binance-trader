<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BasketRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BasketRepository::class)]
#[ORM\Table(name: 'baskets')]
class Basket
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $symbol;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 8)]
    private string $anchorPrice;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $config = [];

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status = 'active';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function setSymbol(string $symbol): self
    {
        $this->symbol = $symbol;
        return $this;
    }

    public function getAnchorPrice(): string
    {
        return $this->anchorPrice;
    }

    public function setAnchorPrice(string $anchorPrice): self
    {
        $this->anchorPrice = $anchorPrice;
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function setConfig(array $config): self
    {
        $this->config = $config;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function setClosedAt(?\DateTimeImmutable $closedAt): self
    {
        $this->closedAt = $closedAt;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function close(): self
    {
        $this->status = 'closed';
        $this->closedAt = new \DateTimeImmutable();
        return $this;
    }
}
