<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FillRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: FillRepository::class)]
#[ORM\Table(name: 'fills')]
#[ORM\Index(columns: ['basket_id'], name: 'idx_fills_basket_id')]
#[ORM\Index(columns: ['order_id'], name: 'idx_fills_order_id')]
class Fill
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Order $order;

    #[ORM\ManyToOne(targetEntity: Basket::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Basket $basket;

    #[ORM\Column(type: Types::STRING, length: 10)]
    private string $side;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 8)]
    private string $price;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 8)]
    private string $quantity;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 8)]
    private string $commission;

    #[ORM\Column(type: Types::STRING, length: 10)]
    private string $commissionAsset;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $executedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->executedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOrder(): Order
    {
        return $this->order;
    }

    public function setOrder(Order $order): self
    {
        $this->order = $order;
        return $this;
    }

    public function getBasket(): Basket
    {
        return $this->basket;
    }

    public function setBasket(Basket $basket): self
    {
        $this->basket = $basket;
        return $this;
    }

    public function getSide(): string
    {
        return $this->side;
    }

    public function setSide(string $side): self
    {
        $this->side = $side;
        return $this;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function setPrice(string $price): self
    {
        $this->price = $price;
        return $this;
    }

    public function getQuantity(): string
    {
        return $this->quantity;
    }

    public function setQuantity(string $quantity): self
    {
        $this->quantity = $quantity;
        return $this;
    }

    public function getCommission(): string
    {
        return $this->commission;
    }

    public function setCommission(string $commission): self
    {
        $this->commission = $commission;
        return $this;
    }

    public function getCommissionAsset(): string
    {
        return $this->commissionAsset;
    }

    public function setCommissionAsset(string $commissionAsset): self
    {
        $this->commissionAsset = $commissionAsset;
        return $this;
    }

    public function getExecutedAt(): \DateTimeImmutable
    {
        return $this->executedAt;
    }

    public function setExecutedAt(\DateTimeImmutable $executedAt): self
    {
        $this->executedAt = $executedAt;
        return $this;
    }

    public function getTotalValue(): float
    {
        return (float)$this->price * (float)$this->quantity;
    }
}
