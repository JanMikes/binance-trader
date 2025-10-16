<?php

declare(strict_types=1);

namespace App\Service\Trading;

use App\Entity\Basket;
use App\Repository\FillRepository;

class PositionCalculator
{
    public function __construct(
        private readonly FillRepository $fillRepository
    ) {
    }

    /**
     * Calculate realized P&L from closed round-trip trades
     */
    public function calculateRealizedPnL(Basket $basket): float
    {
        $buyFills = $this->fillRepository->findBuyFillsByBasket($basket);
        $sellFills = $this->fillRepository->findSellFillsByBasket($basket);

        $totalBuyValue = 0.0;
        $totalBuyQty = 0.0;
        $totalSellValue = 0.0;
        $totalSellQty = 0.0;
        $totalCommission = 0.0;

        foreach ($buyFills as $fill) {
            $price = (float)$fill->getPrice();
            $qty = (float)$fill->getQuantity();
            $totalBuyValue += $price * $qty;
            $totalBuyQty += $qty;
            $totalCommission += (float)$fill->getCommission();
        }

        foreach ($sellFills as $fill) {
            $price = (float)$fill->getPrice();
            $qty = (float)$fill->getQuantity();
            $totalSellValue += $price * $qty;
            $totalSellQty += $qty;
            $totalCommission += (float)$fill->getCommission();
        }

        // Calculate P&L for closed positions (matched buy/sell)
        $closedQty = min($totalBuyQty, $totalSellQty);

        if ($closedQty == 0) {
            return 0.0;
        }

        // Average prices
        $avgBuyPrice = $totalBuyQty > 0 ? $totalBuyValue / $totalBuyQty : 0;
        $avgSellPrice = $totalSellQty > 0 ? $totalSellValue / $totalSellQty : 0;

        $realizedPnL = ($avgSellPrice - $avgBuyPrice) * $closedQty - $totalCommission;

        return $realizedPnL;
    }

    /**
     * Calculate unrealized P&L (mark-to-market)
     */
    public function calculateUnrealizedPnL(Basket $basket, float $currentPrice): float
    {
        $totalBuyQty = $this->fillRepository->getTotalBuyQuantity($basket);
        $totalSellQty = $this->fillRepository->getTotalSellQuantity($basket);

        $openPosition = $totalBuyQty - $totalSellQty;

        if ($openPosition <= 0) {
            return 0.0;
        }

        $avgEntryPrice = $this->getAverageEntryPrice($basket);

        if ($avgEntryPrice === null) {
            return 0.0;
        }

        return ($currentPrice - $avgEntryPrice) * $openPosition;
    }

    /**
     * Get average entry price (VWAP) for open position
     */
    public function getAverageEntryPrice(Basket $basket): ?float
    {
        $buyFills = $this->fillRepository->findBuyFillsByBasket($basket);

        if (count($buyFills) === 0) {
            return null;
        }

        $totalValue = 0.0;
        $totalQty = 0.0;

        foreach ($buyFills as $fill) {
            $price = (float)$fill->getPrice();
            $qty = (float)$fill->getQuantity();
            $totalValue += $price * $qty;
            $totalQty += $qty;
        }

        return $totalQty > 0 ? $totalValue / $totalQty : null;
    }

    /**
     * Get complete position summary
     * @return array{
     *     base_qty: float,
     *     quote_invested: float,
     *     avg_entry_price: float|null,
     *     current_price: float,
     *     realized_pnl: float,
     *     unrealized_pnl: float,
     *     total_pnl: float,
     *     roi_percent: float
     * }
     */
    public function getPositionSummary(Basket $basket, float $currentPrice): array
    {
        $totalBuyQty = $this->fillRepository->getTotalBuyQuantity($basket);
        $totalSellQty = $this->fillRepository->getTotalSellQuantity($basket);
        $openPosition = $totalBuyQty - $totalSellQty;

        $avgEntryPrice = $this->getAverageEntryPrice($basket);
        $quoteInvested = $avgEntryPrice !== null ? $avgEntryPrice * $totalBuyQty : 0.0;

        $realizedPnL = $this->calculateRealizedPnL($basket);
        $unrealizedPnL = $this->calculateUnrealizedPnL($basket, $currentPrice);
        $totalPnL = $realizedPnL + $unrealizedPnL;

        $roiPercent = $quoteInvested > 0 ? ($totalPnL / $quoteInvested) * 100 : 0.0;

        return [
            'base_qty' => $openPosition,
            'quote_invested' => $quoteInvested,
            'avg_entry_price' => $avgEntryPrice,
            'current_price' => $currentPrice,
            'realized_pnl' => $realizedPnL,
            'unrealized_pnl' => $unrealizedPnL,
            'total_pnl' => $totalPnL,
            'roi_percent' => $roiPercent,
        ];
    }
}
