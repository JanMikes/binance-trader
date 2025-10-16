<?php

declare(strict_types=1);

namespace App\Service\Strategy;

interface StrategyInterface
{
    /**
     * Compute the desired order state based on current market and account state.
     *
     * This is a pure function that returns what orders SHOULD exist on the exchange.
     * The reconciler will then compare this with actual orders and create/cancel as needed.
     *
     * @param array<string, mixed> $config Strategy configuration (from bot_config or basket.config)
     * @param array<string, mixed> $state Current account state (balances, positions, fills)
     * @param array<string, mixed> $market Current market data (last price, orderbook if needed)
     *
     * @return array{
     *     buys: array<int, array{side: string, type: string, price: float, qty: float, clientId: string}>,
     *     sells: array<int, array{side: string, type: string, price: float, qty: float, clientId: string}>,
     *     meta: array<string, mixed>
     * }
     */
    public function computeDesiredOrders(array $config, array $state, array $market): array;
}
