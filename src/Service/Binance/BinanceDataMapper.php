<?php

declare(strict_types=1);

namespace App\Service\Binance;

use App\Entity\Basket;
use App\Entity\Fill;
use App\Entity\Order;

class BinanceDataMapper
{
    /**
     * Map Binance order response to Order entity
     * @param array<string, mixed> $binanceOrder
     */
    public function mapToOrderEntity(array $binanceOrder, Basket $basket): Order
    {
        $order = new Order();
        $order->setBasket($basket);
        $order->setExchangeOrderId((string)$binanceOrder['orderId']);
        $order->setClientOrderId((string)$binanceOrder['clientOrderId']);
        $order->setSide((string)$binanceOrder['side']);
        $order->setType((string)$binanceOrder['type']);
        $order->setPrice((string)$binanceOrder['price']);
        $order->setQuantity((string)$binanceOrder['origQty']);
        $order->setStatus((string)$binanceOrder['status']);

        if ($binanceOrder['status'] === 'FILLED' && isset($binanceOrder['updateTime'])) {
            $order->setFilledAt(new \DateTimeImmutable('@' . ($binanceOrder['updateTime'] / 1000)));
        }

        return $order;
    }

    /**
     * Map Binance trade response to Fill entity
     * @param array<string, mixed> $binanceTrade
     */
    public function mapToFillEntity(array $binanceTrade, Order $order, Basket $basket): Fill
    {
        $fill = new Fill();
        $fill->setOrder($order);
        $fill->setBasket($basket);
        $fill->setSide($binanceTrade['isBuyer'] ? 'BUY' : 'SELL');
        $fill->setPrice((string)$binanceTrade['price']);
        $fill->setQuantity((string)$binanceTrade['qty']);
        $fill->setCommission((string)$binanceTrade['commission']);
        $fill->setCommissionAsset((string)$binanceTrade['commissionAsset']);
        $fill->setExecutedAt(new \DateTimeImmutable('@' . ($binanceTrade['time'] / 1000)));

        return $fill;
    }

    /**
     * Extract account balances from Binance account response
     * @param array<string, mixed> $accountInfo
     * @return array<string, array{free: string, locked: string}>
     */
    public function extractBalances(array $accountInfo): array
    {
        $balances = [];

        if (!isset($accountInfo['balances'])) {
            return $balances;
        }

        foreach ($accountInfo['balances'] as $balance) {
            $asset = (string)$balance['asset'];
            $balances[$asset] = [
                'free' => (string)$balance['free'],
                'locked' => (string)$balance['locked'],
            ];
        }

        return $balances;
    }

    /**
     * Extract symbol info (finds the symbol and extracts its filters)
     * @param array<string, mixed> $exchangeInfo
     * @return array{tick_size: float, lot_size: float, min_notional: float}
     */
    public function extractSymbolInfo(array $exchangeInfo, string $symbol): array
    {
        $symbolData = null;

        // Find the symbol in the symbols array
        if (isset($exchangeInfo['symbols'])) {
            foreach ($exchangeInfo['symbols'] as $sym) {
                if ($sym['symbol'] === $symbol) {
                    $symbolData = $sym;
                    break;
                }
            }
        }

        if ($symbolData === null) {
            // Return defaults if symbol not found
            return [
                'tick_size' => 0.01,
                'lot_size' => 0.01,
                'min_notional' => 10.0,
            ];
        }

        // Extract filters
        $filters = $this->extractSymbolFilters($symbolData);

        return [
            'tick_size' => (float)$filters['tick_size'],
            'lot_size' => (float)$filters['lot_size'],
            'min_notional' => (float)$filters['min_notional'],
        ];
    }

    /**
     * Extract exchange info filters (tick size, lot size, min notional)
     * @param array<string, mixed> $exchangeInfo
     * @return array{tick_size: string, lot_size: string, min_notional: string}
     */
    public function extractSymbolFilters(array $exchangeInfo): array
    {
        $filters = [
            'tick_size' => '0.01',
            'lot_size' => '0.01',
            'min_notional' => '10.0',
        ];

        if (!isset($exchangeInfo['filters'])) {
            return $filters;
        }

        foreach ($exchangeInfo['filters'] as $filter) {
            if ($filter['filterType'] === 'PRICE_FILTER') {
                $filters['tick_size'] = (string)$filter['tickSize'];
            } elseif ($filter['filterType'] === 'LOT_SIZE') {
                $filters['lot_size'] = (string)$filter['stepSize'];
            } elseif ($filter['filterType'] === 'MIN_NOTIONAL' || $filter['filterType'] === 'NOTIONAL') {
                $filters['min_notional'] = (string)($filter['minNotional'] ?? $filter['notional'] ?? '10.0');
            }
        }

        return $filters;
    }
}
