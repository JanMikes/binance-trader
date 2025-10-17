<?php

declare(strict_types=1);

namespace App\Service\Trading;

use App\Entity\Basket;
use App\Repository\BasketRepository;
use App\Repository\OrderRepository;
use App\Service\Binance\BinanceApiClient;
use App\Service\Binance\BinanceDataMapper;
use App\Service\Binance\BinanceException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Syncs data from Binance to local database without executing any trades
 */
class BinanceDataSyncService
{
    public function __construct(
        private readonly BinanceApiClient $binanceApi,
        private readonly BinanceDataMapper $dataMapper,
        private readonly BasketRepository $basketRepository,
        private readonly OrderRepository $orderRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Sync data from Binance for active basket
     * @return array{success: bool, message: string, synced_orders: int}
     */
    public function syncActiveBasket(): array
    {
        $basket = $this->basketRepository->findActiveBasket();

        if ($basket === null) {
            return [
                'success' => false,
                'message' => 'No active basket found',
                'synced_orders' => 0,
            ];
        }

        return $this->syncBasket($basket);
    }

    /**
     * Sync data from Binance for specific basket
     * @return array{success: bool, message: string, synced_orders: int}
     */
    public function syncBasket(Basket $basket): array
    {
        try {
            $symbol = $basket->getSymbol();
            $syncedOrders = 0;

            $this->logger->info('Starting Binance data sync', [
                'basket_id' => $basket->getId(),
                'symbol' => $symbol,
            ]);

            // Step 1: Fetch open orders from Binance
            $openOrders = $this->binanceApi->getOpenOrders($symbol);

            // Step 2: Update order statuses in database
            // First, get all clientOrderIds from Binance
            $binanceClientOrderIds = array_map(
                fn($order) => (string)$order['clientOrderId'],
                $openOrders
            );

            // Update orders that exist on Binance
            foreach ($openOrders as $binanceOrder) {
                $clientOrderId = (string)$binanceOrder['clientOrderId'];
                $order = $this->orderRepository->findByClientOrderId($clientOrderId);

                if ($order !== null) {
                    // Update status from Binance
                    $order->setStatus((string)$binanceOrder['status']);
                    $order->setPrice((string)$binanceOrder['price']);
                    $order->setQuantity((string)$binanceOrder['origQty']);

                    if (isset($binanceOrder['orderId'])) {
                        $order->setExchangeOrderId((string)$binanceOrder['orderId']);
                    }

                    $this->orderRepository->save($order, false);
                    $syncedOrders++;
                } else {
                    // Order exists on Binance but not in our DB - create it
                    $newOrder = $this->dataMapper->mapToOrderEntity($binanceOrder, $basket);
                    $this->orderRepository->save($newOrder, false);
                    $syncedOrders++;

                    $this->logger->info('Created order from Binance sync', [
                        'clientOrderId' => $clientOrderId,
                    ]);
                }
            }

            // Mark orders as CANCELED if they don't exist on Binance anymore
            $localOpenOrders = $this->orderRepository->findOpenOrdersByBasket($basket);
            foreach ($localOpenOrders as $localOrder) {
                if (!in_array($localOrder->getClientOrderId(), $binanceClientOrderIds, true)) {
                    // Order is in our DB as open but not on Binance - mark as canceled
                    $localOrder->setStatus('CANCELED');
                    $this->orderRepository->save($localOrder, false);
                    $syncedOrders++;

                    $this->logger->info('Marked order as CANCELED (not on Binance)', [
                        'clientOrderId' => $localOrder->getClientOrderId(),
                    ]);
                }
            }

            // Flush all changes to database
            $this->entityManager->flush();

            $message = sprintf(
                'Successfully synced %d orders from Binance',
                $syncedOrders
            );

            $this->logger->info('Binance data sync completed', [
                'basket_id' => $basket->getId(),
                'synced_orders' => $syncedOrders,
            ]);

            return [
                'success' => true,
                'message' => $message,
                'synced_orders' => $syncedOrders,
            ];
        } catch (BinanceException $e) {
            $this->logger->error('Binance API error during sync', [
                'basket_id' => $basket->getId(),
                'error' => $e->getMessage(),
                'binance_code' => $e->getBinanceCode(),
            ]);

            return [
                'success' => false,
                'message' => 'Binance API error: ' . $e->getMessage(),
                'synced_orders' => 0,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Error during Binance sync', [
                'basket_id' => $basket->getId(),
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Sync failed: ' . $e->getMessage(),
                'synced_orders' => 0,
            ];
        }
    }
}
