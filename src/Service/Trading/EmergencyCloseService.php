<?php

declare(strict_types=1);

namespace App\Service\Trading;

use App\Entity\Basket;
use App\Repository\BasketRepository;
use App\Repository\FillRepository;
use App\Service\Binance\BinanceApiClient;
use App\Service\Binance\BinanceException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class EmergencyCloseService
{
    public function __construct(
        private readonly BinanceApiClient $binanceApi,
        private readonly FillRepository $fillRepository,
        private readonly BasketRepository $basketRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Close all positions for a basket
     * @return array{success: bool, message: string, canceled_count: int, exit_order_placed: bool}
     */
    public function closeAllPositions(int $basketId, float $safetyMarginPercent = 0.03): array
    {
        $basket = $this->basketRepository->findById($basketId);

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
                try {
                    $this->binanceApi->cancelOrder($symbol, (string)$order['clientOrderId']);
                    $canceledCount++;
                } catch (BinanceException $e) {
                    $this->logger->warning('Failed to cancel order during emergency close', [
                        'clientOrderId' => $order['clientOrderId'],
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
                        $this->binanceApi->placeOrder(
                            $symbol,
                            'SELL',
                            'LIMIT',
                            [
                                'price' => (string)$exitPrice,
                                'quantity' => (string)$exitQty,
                                'timeInForce' => 'GTC',
                                'newClientOrderId' => "BOT:{$symbol}:{$basketId}:S:EMERGENCY",
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

            // Step 4: Mark basket as closed
            $basket->setStatus('emergency_closed');
            $basket->setClosedAt(new \DateTimeImmutable());
            $this->basketRepository->save($basket, false);

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
