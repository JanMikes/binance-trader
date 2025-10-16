<?php

declare(strict_types=1);

namespace App\Service\Trading;

use Psr\Log\LoggerInterface;

/**
 * Reconciles desired order state (from Strategy) with actual order state (from Binance)
 * Implements the core "Should-Be" pattern
 */
class OrderReconciler
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Compare desired vs actual orders and generate action plan
     *
     * @param array<int, array{side: string, type: string, price: float, qty: float, clientId: string}> $desiredOrders
     * @param array<int, array{clientOrderId: string, price: string|float, origQty: string|float, status: string}> $actualOrders
     * @return array{
     *     to_cancel: array<int, string>,
     *     to_create: array<int, array{side: string, type: string, price: float, qty: float, clientId: string}>,
     *     stats: array{canceled: int, created: int, unchanged: int}
     * }
     */
    public function reconcile(array $desiredOrders, array $actualOrders): array
    {
        // Build maps by clientOrderId for fast lookup
        $desiredMap = [];
        foreach ($desiredOrders as $order) {
            $desiredMap[$order['clientId']] = $order;
        }

        $actualMap = [];
        foreach ($actualOrders as $order) {
            $actualMap[(string)$order['clientOrderId']] = $order;
        }

        // Find orders to cancel: ACTUAL - SHOULD_BE
        $toCancel = [];
        foreach ($actualMap as $clientId => $actualOrder) {
            if (!isset($desiredMap[$clientId])) {
                $toCancel[] = $clientId;
            } elseif ($this->orderNeedsUpdate($desiredMap[$clientId], $actualOrder)) {
                // Price or quantity changed - cancel and recreate
                $toCancel[] = $clientId;
            }
        }

        // Find orders to create: SHOULD_BE - ACTUAL (or those that need update)
        $toCreate = [];
        foreach ($desiredMap as $clientId => $desiredOrder) {
            if (!isset($actualMap[$clientId])) {
                $toCreate[] = $desiredOrder;
            } elseif ($this->orderNeedsUpdate($desiredOrder, $actualMap[$clientId])) {
                // Recreate with new parameters
                $toCreate[] = $desiredOrder;
            }
        }

        $unchanged = count($actualMap) - count($toCancel);

        $this->logger->info('Order reconciliation complete', [
            'to_cancel' => count($toCancel),
            'to_create' => count($toCreate),
            'unchanged' => $unchanged,
        ]);

        return [
            'to_cancel' => $toCancel,
            'to_create' => $toCreate,
            'stats' => [
                'canceled' => count($toCancel),
                'created' => count($toCreate),
                'unchanged' => $unchanged,
            ],
        ];
    }

    /**
     * Check if an order needs to be updated (price or quantity changed)
     * @param array{side: string, type: string, price: float, qty: float, clientId: string} $desired
     * @param array{clientOrderId: string, price: string|float, origQty: string|float, status: string} $actual
     */
    private function orderNeedsUpdate(array $desired, array $actual): bool
    {
        $desiredPrice = $desired['price'];
        $desiredQty = $desired['qty'];
        $actualPrice = (float)$actual['price'];
        $actualQty = (float)$actual['origQty'];

        // Compare with small tolerance for floating point
        $priceDiff = abs($desiredPrice - $actualPrice);
        $qtyDiff = abs($desiredQty - $actualQty);

        return $priceDiff > 0.00000001 || $qtyDiff > 0.00000001;
    }
}
