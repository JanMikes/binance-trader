<?php

declare(strict_types=1);

namespace App\Service\Trading;

use App\Entity\Basket;
use App\Entity\Order;
use App\Repository\OrderRepository;
use App\Service\Binance\BinanceApiClient;
use App\Service\Binance\BinanceDataMapper;
use App\Service\Binance\BinanceException;
use App\Service\Binance\ExchangeInfoCache;
use Psr\Log\LoggerInterface;

class OrderExecutor
{
    public function __construct(
        private readonly BinanceApiClient $binanceApi,
        private readonly BinanceDataMapper $dataMapper,
        private readonly OrderRepository $orderRepository,
        private readonly OrderValidator $orderValidator,
        private readonly ExchangeInfoCache $exchangeInfoCache,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Execute order cancellations
     * @param array<int, string> $clientOrderIds
     */
    public function executeCancellations(string $symbol, array $clientOrderIds): int
    {
        $canceled = 0;

        foreach ($clientOrderIds as $clientOrderId) {
            try {
                $this->binanceApi->cancelOrder($symbol, $clientOrderId);

                // Update order status in database
                $order = $this->orderRepository->findByClientOrderId($clientOrderId);
                if ($order !== null) {
                    $order->setStatus('CANCELED');
                    $this->orderRepository->save($order);
                }

                $canceled++;
                $this->logger->info('Order canceled', ['clientOrderId' => $clientOrderId]);
            } catch (BinanceException $e) {
                $this->logger->error('Failed to cancel order', [
                    'clientOrderId' => $clientOrderId,
                    'error' => $e->getMessage(),
                    'binanceCode' => $e->getBinanceCode(),
                ]);
            }
        }

        return $canceled;
    }

    /**
     * Execute order placements
     * @param array<int, array{side: string, type: string, price: float, qty: float, clientId: string}> $orders
     */
    public function executeCreations(string $symbol, array $orders, Basket $basket): int
    {
        $created = 0;

        // Get exchange filters for validation
        try {
            $filters = $this->exchangeInfoCache->getSymbolFilters($symbol);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get exchange info, skipping order validation', [
                'error' => $e->getMessage(),
            ]);
            // Proceed without validation if cache fails
            $filters = null;
        }

        foreach ($orders as $orderSpec) {
            // Validate order before placing (if filters available)
            if ($filters !== null) {
                $validationErrors = $this->orderValidator->validateOrder(
                    $orderSpec['price'],
                    $orderSpec['qty'],
                    [
                        'tick_size' => (float)$filters['tick_size'],
                        'lot_size' => (float)$filters['lot_size'],
                        'min_notional' => (float)$filters['min_notional'],
                    ],
                    999999.0 // Balance check happens on Binance side
                );

                if (count($validationErrors) > 0) {
                    $this->logger->warning('Order validation failed, skipping', [
                        'clientOrderId' => $orderSpec['clientId'],
                        'errors' => $validationErrors,
                    ]);
                    continue;
                }
            }

            try {
                $params = [
                    'newClientOrderId' => $orderSpec['clientId'],
                    'price' => (string)$orderSpec['price'],
                    'quantity' => (string)$orderSpec['qty'],
                    'timeInForce' => 'GTC',
                ];

                $response = $this->binanceApi->placeOrder(
                    $symbol,
                    $orderSpec['side'],
                    $orderSpec['type'],
                    $params
                );

                // Save order to database
                $order = $this->dataMapper->mapToOrderEntity($response, $basket);
                $this->orderRepository->save($order);

                $created++;
                $this->logger->info('Order created', [
                    'clientOrderId' => $orderSpec['clientId'],
                    'exchangeOrderId' => $response['orderId'] ?? null,
                ]);
            } catch (BinanceException $e) {
                // Check if order already exists
                if ($e->getBinanceCode() === -2010) {
                    $this->logger->warning('Order already exists (idempotency)', [
                        'clientOrderId' => $orderSpec['clientId'],
                    ]);
                    continue;
                }

                $this->logger->error('Failed to create order', [
                    'clientOrderId' => $orderSpec['clientId'],
                    'error' => $e->getMessage(),
                    'binanceCode' => $e->getBinanceCode(),
                ]);
            }
        }

        return $created;
    }

    /**
     * Execute full reconciliation plan
     * @param array{to_cancel: array<int, string>, to_create: array<int, array{side: string, type: string, price: float, qty: float, clientId: string}>} $reconcileResult
     */
    public function executeReconciliation(string $symbol, array $reconcileResult, Basket $basket): void
    {
        // First cancel orders
        if (count($reconcileResult['to_cancel']) > 0) {
            $canceled = $this->executeCancellations($symbol, $reconcileResult['to_cancel']);
            $this->logger->info("Canceled {$canceled} orders");
        }

        // Then create new orders
        if (count($reconcileResult['to_create']) > 0) {
            $created = $this->executeCreations($symbol, $reconcileResult['to_create'], $basket);
            $this->logger->info("Created {$created} orders");
        }
    }
}
