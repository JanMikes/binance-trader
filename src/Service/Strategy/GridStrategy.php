<?php

declare(strict_types=1);

namespace App\Service\Strategy;

use Psr\Log\LoggerInterface;

class GridStrategy implements StrategyInterface
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $state
     * @param array<string, mixed> $market
     * @return array{buys: array<int, array{side: string, type: string, price: float, qty: float, clientId: string}>, sells: array<int, array{side: string, type: string, price: float, qty: float, clientId: string}>, meta: array<string, mixed>}
     */
    public function computeDesiredOrders(array $config, array $state, array $market): array
    {
        // Extract configuration
        $symbol = (string)$config['symbol'];
        $basketId = (string)$state['basket_id'];
        $anchorPrice = (float)$config['anchor_price_P0'];
        $levelsPct = $config['levels_pct']; // array of drops
        $allocWeights = $config['alloc_weights']; // array of capital weights
        $maxGridCapital = (float)$config['max_grid_capital_quote'];
        $tickSize = (float)$config['tick_size'];
        $lotSize = (float)$config['lot_size'];
        $minNotional = (float)$config['min_notional'];
        $lastPrice = (float)$market['last_trade_price'];

        // Step 1: Build planned levels
        $levels = $this->buildPlannedLevels(
            $anchorPrice,
            $levelsPct,
            $allocWeights,
            $maxGridCapital,
            $tickSize,
            $lotSize,
            $minNotional,
            $symbol,
            $basketId
        );

        $this->logger->debug('Planned levels built', [
            'levels_count' => count($levels),
            'levels' => $levels,
            'anchor_price' => $anchorPrice,
            'max_capital' => $maxGridCapital,
        ]);

        // Step 2: Calculate VWAP and filled levels
        $fillsHistory = $state['fills_history'] ?? [];
        $vwapData = $this->calculateVWAP($fillsHistory, $levels, $tickSize);
        $avgPrice = $vwapData['avg_price'];
        $filledLevels = $vwapData['filled_levels'];
        $nFilled = count($filledLevels);

        // Step 3: Apply zone protections
        $hardStopMode = (string)($config['hard_stop_mode'] ?? 'none');
        $hardStopPct = (float)($config['hard_stop_pct'] ?? 0.40);

        if ($hardStopMode === 'hard') {
            $stopPrice = $anchorPrice * (1 - $hardStopPct);
            $levels = array_filter($levels, fn($lv) => $lv['price'] >= $stopPrice);
            $levels = array_values($levels);
        }

        // Step 4: Determine BUY should-be orders
        $placeMode = (string)($config['place_mode'] ?? 'all_unfilled');
        $kNext = (int)($config['k_next'] ?? 2);
        $availableQuote = (float)($state['available_quote_balance'] ?? 0);

        $this->logger->debug('Determining BUY orders', [
            'levels_count' => count($levels),
            'filled_levels_count' => count($filledLevels),
            'last_price' => $lastPrice,
            'place_mode' => $placeMode,
            'k_next' => $kNext,
            'available_quote' => $availableQuote,
            'max_capital' => $maxGridCapital,
        ]);

        $buys = $this->determineBuyOrders(
            $levels,
            $filledLevels,
            $lastPrice,
            $placeMode,
            $kNext,
            $availableQuote,
            $maxGridCapital
        );

        $this->logger->debug('BUY orders determined', [
            'buys_count' => count($buys),
            'buys' => $buys,
        ]);

        // Step 5: Determine SELL should-be orders
        $positionBaseQty = (float)($state['position_base_qty'] ?? 0);
        $sells = [];

        if ($positionBaseQty > 0 && $avgPrice !== null) {
            $sells = $this->determineSellOrders(
                $positionBaseQty,
                $avgPrice,
                $nFilled,
                $config,
                $tickSize,
                $lotSize,
                $symbol,
                $basketId
            );
        }

        // Step 6: Check reanchor suggestion
        // Only suggest reanchor if no position AND no BUY orders
        $reanchorSuggested = count($buys) === 0 && count($sells) === 0
            && $this->shouldReanchor($positionBaseQty, $config, $state);

        return [
            'buys' => $buys,
            'sells' => $sells,
            'meta' => [
                'basket_id' => $basketId,
                'avg_price' => $avgPrice,
                'filled_levels' => $nFilled,
                'planned_levels_N' => count($levels),
                'remaining_quote_budget' => $availableQuote,
                'reanchor_suggested' => $reanchorSuggested,
            ],
        ];
    }

    /**
     * Build planned levels from anchor price
     * @param array<int, float> $levelsPct
     * @param array<int, float> $allocWeights
     * @return array<int, array{idx: int, price: float, qty: float, clientId: string}>
     */
    private function buildPlannedLevels(
        float $anchorPrice,
        array $levelsPct,
        array $allocWeights,
        float $maxGridCapital,
        float $tickSize,
        float $lotSize,
        float $minNotional,
        string $symbol,
        string $basketId
    ): array {
        $levels = [];
        $n = count($levelsPct);

        for ($i = 0; $i < $n; $i++) {
            // Convert percentage to decimal (e.g., -5.0 => -0.05)
            $dropPct = $levelsPct[$i] / 100.0;
            $price = $this->roundDown($anchorPrice * (1 + $dropPct), $tickSize);
            $quoteCap = $maxGridCapital * $allocWeights[$i];
            $qty = $this->roundDown($quoteCap / $price, $lotSize);

            // Check min notional
            if ($qty * $price >= $minNotional && $qty > 0) {
                $levels[] = [
                    'idx' => $i + 1,
                    'price' => $price,
                    'qty' => $qty,
                    'clientId' => "{$symbol}_{$basketId}_B_" . ($i + 1),
                ];
            }
        }

        return $levels;
    }

    /**
     * Calculate VWAP from fills history
     * @param array<int, array{side: string, price: float|string, qty: float|string}> $fills
     * @param array<int, array{idx: int, price: float}> $levels
     * @return array{avg_price: float|null, filled_levels: array<int, bool>}
     */
    private function calculateVWAP(array $fills, array $levels, float $tickSize): array
    {
        $filledQty = 0.0;
        $filledQuote = 0.0;
        $filledLevels = [];

        foreach ($fills as $fill) {
            if ((string)$fill['side'] !== 'BUY') {
                continue;
            }

            $fillPrice = (float)$fill['price'];
            $fillQty = (float)$fill['qty'];

            $filledQty += $fillQty;
            $filledQuote += $fillPrice * $fillQty;

            // Map to nearest level
            foreach ($levels as $level) {
                if (abs($level['price'] - $fillPrice) <= $tickSize) {
                    $filledLevels[$level['idx']] = true;
                    break;
                }
            }
        }

        $avgPrice = $filledQty > 0 ? $filledQuote / $filledQty : null;

        return [
            'avg_price' => $avgPrice,
            'filled_levels' => $filledLevels,
        ];
    }

    /**
     * Determine which BUY orders should exist
     * @param array<int, array{idx: int, price: float, qty: float, clientId: string}> $levels
     * @param array<int, bool> $filledLevels
     * @return array<int, array{side: string, type: string, price: float, qty: float, clientId: string}>
     */
    private function determineBuyOrders(
        array $levels,
        array $filledLevels,
        float $lastPrice,
        string $placeMode,
        int $kNext,
        float $availableQuote,
        float $budget
    ): array {
        // Filter out filled levels
        $candidates = array_filter($levels, fn($lv) => !isset($filledLevels[$lv['idx']]));
        $candidates = array_values($candidates);

        // Apply place mode
        if ($placeMode === 'only_next_k') {
            // Sort by price descending (closest to current price first)
            usort($candidates, fn($a, $b) => $b['price'] <=> $a['price']);

            // Filter to only those below current price
            $filtered = [];
            foreach ($candidates as $lv) {
                if ($lv['price'] <= $lastPrice) {
                    $filtered[] = $lv;
                }
                if (count($filtered) >= $kNext) {
                    break;
                }
            }
            $candidates = $filtered;
        }

        // Check budget constraints
        $buys = [];
        foreach ($candidates as $lv) {
            $cost = $lv['price'] * $lv['qty'];
            if ($cost <= $availableQuote && $cost <= $budget) {
                $buys[] = [
                    'side' => 'BUY',
                    'type' => 'LIMIT',
                    'price' => $lv['price'],
                    'qty' => $lv['qty'],
                    'clientId' => $lv['clientId'],
                ];
            }
        }

        return $buys;
    }

    /**
     * Determine SELL orders (TP1, TP2, TRAIL)
     * @param array<string, mixed> $config
     * @return array<int, array{side: string, type: string, price: float, qty: float, clientId: string}>
     */
    private function determineSellOrders(
        float $positionBaseQty,
        float $avgPrice,
        int $nFilled,
        array $config,
        float $tickSize,
        float $lotSize,
        string $symbol,
        string $basketId
    ): array {
        // Calculate dynamic TP
        $tpStart = (float)($config['tp_start_pct'] ?? 0.012);
        $tpStep = (float)($config['tp_step_pct'] ?? 0.0015);
        $tpMin = (float)($config['tp_min_pct'] ?? 0.003);

        $tpn = max($tpStart - $tpStep * max(0, $nFilled - 1), $tpMin);

        // Calculate TP prices
        $tp1Price = $this->roundUp($avgPrice * (1 + $tpn), $tickSize);
        $tp2Delta = (float)($config['tp2_delta_pct'] ?? 0.008);
        $tp2Price = $this->roundUp($avgPrice * (1 + $tpn + $tp2Delta), $tickSize);

        // Split position
        $tp1Share = (float)($config['tp1_share'] ?? 0.40);
        $tp2Share = (float)($config['tp2_share'] ?? 0.35);
        $trailShare = (float)($config['trail_share'] ?? 0.25);

        $q1 = $this->roundDown($positionBaseQty * $tp1Share, $lotSize);
        $q2 = $this->roundDown($positionBaseQty * $tp2Share, $lotSize);
        $q3 = $this->roundDown($positionBaseQty - $q1 - $q2, $lotSize);

        $sells = [];

        if ($q1 > 0) {
            $sells[] = [
                'side' => 'SELL',
                'type' => 'LIMIT',
                'price' => $tp1Price,
                'qty' => $q1,
                'clientId' => "{$symbol}_{$basketId}_S_TP1",
            ];
        }

        if ($q2 > 0) {
            $sells[] = [
                'side' => 'SELL',
                'type' => 'LIMIT',
                'price' => $tp2Price,
                'qty' => $q2,
                'clientId' => "{$symbol}_{$basketId}_S_TP2",
            ];
        }

        if ($q3 > 0) {
            // For trailing, we'll use LIMIT for now (can be enhanced later)
            $trailingCallbackPct = (float)($config['trailing_callback_pct'] ?? 0.018);
            $trailPrice = $this->roundUp($avgPrice * (1 + $trailingCallbackPct), $tickSize);

            $sells[] = [
                'side' => 'SELL',
                'type' => 'LIMIT',
                'price' => $trailPrice,
                'qty' => $q3,
                'clientId' => "{$symbol}_{$basketId}_S_TRAIL",
            ];
        }

        return $sells;
    }

    /**
     * Check if reanchor is suggested
     * @param array<string, mixed> $config
     * @param array<string, mixed> $state
     */
    private function shouldReanchor(float $positionQty, array $config, array $state): bool
    {
        // Position is closed
        if ($positionQty == 0) {
            return true;
        }

        // Close ratio threshold
        $closeRatio = (float)($config['reanchor_rules']['close_ratio'] ?? 0.7);
        // TODO: Calculate actual close ratio from fills

        // Time TTL
        $ttl = (int)($config['reanchor_rules']['time_TTL_s'] ?? 86400);
        $basketCreatedAt = $state['basket_created_at'] ?? null;

        if ($basketCreatedAt instanceof \DateTimeImmutable) {
            $age = time() - $basketCreatedAt->getTimestamp();
            if ($age > $ttl) {
                return true;
            }
        }

        return false;
    }

    /**
     * Round down to nearest step
     */
    private function roundDown(float $value, float $step): float
    {
        if ($step == 0) {
            return $value;
        }
        return floor($value / $step) * $step;
    }

    /**
     * Round up to nearest step
     */
    private function roundUp(float $value, float $step): float
    {
        if ($step == 0) {
            return $value;
        }
        return ceil($value / $step) * $step;
    }
}
