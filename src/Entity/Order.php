<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OrderRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: 'orders')]
#[ORM\Index(columns: ['basket_id'], name: 'idx_orders_basket_id')]
#[ORM\Index(columns: ['client_order_id'], name: 'idx_orders_client_order_id')]
#[ORM\Index(columns: ['status'], name: 'idx_orders_status')]
class Order
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Basket::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Basket $basket;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?string $exchangeOrderId = null;

    #[ORM\Column(type: Types::STRING, length: 100, unique: true)]
    private string $clientOrderId;

    #[ORM\Column(type: Types::STRING, length: 10)]
    private string $side;

    #[ORM\Column(type: Types::STRING, length: 30)]
    private string $type;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 8)]
    private string $price;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 8)]
    private string $quantity;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status = 'NEW';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $filledAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getExchangeOrderId(): ?string
    {
        return $this->exchangeOrderId;
    }

    public function setExchangeOrderId(?string $exchangeOrderId): self
    {
        $this->exchangeOrderId = $exchangeOrderId;
        return $this;
    }

    public function getClientOrderId(): string
    {
        return $this->clientOrderId;
    }

    public function setClientOrderId(string $clientOrderId): self
    {
        $this->clientOrderId = $clientOrderId;
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

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;
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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getFilledAt(): ?\DateTimeImmutable
    {
        return $this->filledAt;
    }

    public function setFilledAt(?\DateTimeImmutable $filledAt): self
    {
        $this->filledAt = $filledAt;
        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function markAsFilled(): self
    {
        $this->status = 'FILLED';
        $this->filledAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function isFilled(): bool
    {
        return $this->status === 'FILLED';
    }
}
