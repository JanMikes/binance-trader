<?php

declare(strict_types=1);

namespace App\Service\Trading;

use App\Entity\Basket;
use App\Repository\BasketRepository;
use App\Repository\FillRepository;
use App\Repository\OrderRepository;
use App\Service\Binance\BinanceApiClient;
use App\Service\Binance\BinanceException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

class EmergencyCloseService
{
    public function __construct(
        private readonly BinanceApiClient $binanceApi,
        private readonly FillRepository $fillRepository,
        private readonly BasketRepository $basketRepository,
        private readonly OrderRepository $orderRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Close all positions for a basket
     * @return array{success: bool, message: string, canceled_count: int, exit_order_placed: bool}
     */
    public function closeAllPositions(Uuid $basketId, float $safetyMarginPercent = 0.03): array
    {
        $basket = $this->basketRepository->find($basketId);

        if ($basket === null) {
            return [
                'success' => false,
                'message' => 'Basket not found',
                'canceled_count' => 0,
                'exit_order_placed' => false,
            ];
        }

        $symbol = $basket->getSymbol();

        try {
            $this->entityManager->beginTransaction();

            // Step 1: Cancel all open orders
            $openOrders = $this->binanceApi->getOpenOrders($symbol);
            $canceledCount = 0;

            foreach ($openOrders as $order) {
                $clientOrderId = (string)$order['clientOrderId'];

                try {
                    $this->binanceApi->cancelOrder($symbol, $clientOrderId);
                    $canceledCount++;

                    // Update order status in database
                    $orderEntity = $this->orderRepository->findByClientOrderId($clientOrderId);
                    if ($orderEntity !== null) {
                        $orderEntity->setStatus('CANCELED');
                        $this->orderRepository->save($orderEntity, false); // Don't flush yet, we're in a transaction
                    }

                    $this->logger->info('Order canceled during emergency close', [
                        'clientOrderId' => $clientOrderId,
                    ]);
                } catch (BinanceException $e) {
                    $this->logger->warning('Failed to cancel order during emergency close', [
                        'clientOrderId' => $clientOrderId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Step 2: Calculate position
            $totalBuyQty = $this->fillRepository->getTotalBuyQuantity($basket);
            $totalSellQty = $this->fillRepository->getTotalSellQuantity($basket);
            $position = $totalBuyQty - $totalSellQty;

            $exitOrderPlaced = false;

            // Step 3: Place emergency exit order if we have a position
            if ($position > 0.00001) {
                $currentPrice = $this->binanceApi->getCurrentPrice($symbol);
                $exitPrice = $currentPrice * (1 - $safetyMarginPercent);

                // Round to tick size (simplified - should use exchange info)
                $tickSize = (float)($basket->getConfig()['tick_size'] ?? 0.001);
                $lotSize = (float)($basket->getConfig()['lot_size'] ?? 0.01);

                $exitPrice = floor($exitPrice / $tickSize) * $tickSize;
                $exitQty = floor($position / $lotSize) * $lotSize;

                if ($exitQty > 0) {
                    try {
                        // Convert UUID to base58 for shorter clientOrderId
                        $basketIdShort = $basketId->toBase58();

                        $this->binanceApi->placeOrder(
                            $symbol,
                            'SELL',
                            'LIMIT',
                            [
                                'price' => (string)$exitPrice,
                                'quantity' => (string)$exitQty,
                                'timeInForce' => 'GTC',
                                'newClientOrderId' => "{$symbol}_{$basketIdShort}_S_EMERGENCY",
                            ]
                        );
                        $exitOrderPlaced = true;

                        $this->logger->info('Emergency exit order placed', [
                            'basket_id' => $basketId,
                            'price' => $exitPrice,
                            'qty' => $exitQty,
                        ]);
                    } catch (BinanceException $e) {
                        $this->logger->error('Failed to place emergency exit order', [
                            'basket_id' => $basketId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            // Step 4: Flush all changes to database before committing transaction
            // Note: We do NOT mark the basket as closed - just cancel orders
            // The basket remains active so trading can continue
            $this->entityManager->flush();
            $this->entityManager->commit();

            $message = sprintf(
                'Emergency close completed. Canceled %d orders. Exit order %s.',
                $canceledCount,
                $exitOrderPlaced ? 'placed' : 'not needed'
            );

            $this->logger->info('Emergency close completed', [
                'basket_id' => $basketId,
                'canceled' => $canceledCount,
                'exit_placed' => $exitOrderPlaced,
            ]);

            return [
                'success' => true,
                'message' => $message,
                'canceled_count' => $canceledCount,
                'exit_order_placed' => $exitOrderPlaced,
            ];
        } catch (\Throwable $e) {
            $this->entityManager->rollback();

            $this->logger->error('Emergency close failed', [
                'basket_id' => $basketId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Emergency close failed: ' . $e->getMessage(),
                'canceled_count' => 0,
                'exit_order_placed' => false,
            ];
        }
    }
}
