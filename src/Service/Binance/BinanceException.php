<?php

declare(strict_types=1);

namespace App\Service\Binance;

class BinanceException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $binanceCode = 0,
        private readonly mixed $binanceData = null,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getBinanceCode(): int
    {
        return $this->binanceCode;
    }

    public function getBinanceData(): mixed
    {
        return $this->binanceData;
    }
}
