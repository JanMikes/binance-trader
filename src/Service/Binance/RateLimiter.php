<?php

declare(strict_types=1);

namespace App\Service\Binance;

/**
 * Token bucket rate limiter for Binance API (1200 requests per minute)
 */
class RateLimiter
{
    private float $tokens;
    private float $lastRefill;

    public function __construct(
        private readonly int $maxRequests = 1200,
        private readonly int $perSeconds = 60
    ) {
        $this->tokens = (float)$maxRequests;
        $this->lastRefill = microtime(true);
    }

    public function acquire(int $cost = 1): void
    {
        $this->refill();

        if ($this->tokens < $cost) {
            $waitTime = ($cost - $this->tokens) / ($this->maxRequests / $this->perSeconds);
            usleep((int)($waitTime * 1000000));
            $this->refill();
        }

        $this->tokens -= $cost;
    }

    private function refill(): void
    {
        $now = microtime(true);
        $timePassed = $now - $this->lastRefill;
        $tokensToAdd = $timePassed * ($this->maxRequests / $this->perSeconds);

        $this->tokens = min($this->maxRequests, $this->tokens + $tokensToAdd);
        $this->lastRefill = $now;
    }

    public function getAvailableTokens(): float
    {
        $this->refill();
        return $this->tokens;
    }
}
