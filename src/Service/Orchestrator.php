<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AccountSnapshot;
use App\Entity\Basket;
use App\Repository\AccountSnapshotRepository;
use App\Repository\BasketRepository;
use App\Repository\FillRepository;
use App\Service\Binance\BinanceApiClient;
use App\Service\Binance\BinanceDataMapper;
use App\Service\Binance\BinanceException;
use App\Service\Strategy\StrategyInterface;
use App\Service\Trading\OrderExecutor;
use App\Service\Trading\OrderReconciler;
use Psr\Log\LoggerInterface;

/**
 * Main coordination loop - orchestrates Strategy → Reconciler → Executor pipeline
 */
class Orchestrator
{
    private bool $running = true;

    public function __construct(
        private readonly BinanceApiClient $binanceApi,
        private readonly BinanceDataMapper $dataMapper,
        private readonly StrategyInterface $strategy,
        private readonly OrderReconciler $reconciler,
        private readonly OrderExecutor $executor,
        private readonly BasketRepository $basketRepository,
        private readonly FillRepository $fillRepository,
        private readonly AccountSnapshotRepository $snapshotRepository,
        private readonly SystemStatusService $systemStatus,
        private readonly LoggerInterface $logger,
        private readonly int $checkIntervalSeconds = 5
    ) {
    }

    /**
     * Main run loop
     */
    public function run(): void
    {
        $this->logger->info('Orchestrator started', [
            'check_interval' => $this->checkIntervalSeconds,
        ]);

        while ($this->running) {
            try {
                $this->processCycle();
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'Cycle error: %s (%s) at %s:%d',
                    $e->getMessage(),
                    get_class($e),
                    $e->getFile(),
                    $e->getLine()
                ), [
                    'error' => $e->getMessage(),
                    'exception_class' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            sleep($this->checkIntervalSeconds);
        }

        $this->logger->info('Orchestrator stopped');
    }

    /**
     * Stop the orchestrator gracefully
     */
    public function stop(): void
    {
        $this->logger->info('Orchestrator stop requested');
        $this->running = false;
    }

    /**
     * Process one cycle
     */
    private function processCycle(): void
    {
        $startTime = microtime(true);

        // Load active baskets
        $baskets = $this->basketRepository->findActiveBaskets();

        if (count($baskets) === 0) {
            $this->logger->debug('No active baskets found');
            return;
        }

        foreach ($baskets as $basket) {
            $this->processBasket($basket);
        }

        // Create periodic snapshot (every 10 cycles = ~50 seconds if interval is 5s)
        static $cycleCount = 0;
        $cycleCount++;
        if ($cycleCount % 10 === 0) {
            $this->createAccountSnapshot();
        }

        $duration = (microtime(true) - $startTime) * 1000;
        $this->logger->info('Cycle complete', [
            'duration_ms' => round($duration, 2),
            'baskets_processed' => count($baskets),
        ]);
    }

    /**
     * Process a single basket
     */
    private function processBasket(Basket $basket): void
    {
        $this->logger->debug('Starting to process basket', [
            'basket_id' => $basket->getId(),
            'symbol' => $basket->getSymbol(),
        ]);

        try {
            $symbol = $basket->getSymbol();
            $config = $basket->getConfig();

            // Add symbol to config (strategy expects it)
            $config['symbol'] = $symbol;

            // Map base_capital_usdc to max_grid_capital_quote if not set
            if (!isset($config['max_grid_capital_quote']) && isset($config['base_capital_usdc'])) {
                $config['max_grid_capital_quote'] = $config['base_capital_usdc'];
            }

            // Fetch exchange info for market parameters (cache this in production)
            $exchangeInfo = $this->binanceApi->getExchangeInfo($symbol);
            $symbolInfo = $this->dataMapper->extractSymbolInfo($exchangeInfo, $symbol);
            $config['tick_size'] = $symbolInfo['tick_size'];
            $config['lot_size'] = $symbolInfo['lot_size'];
            $config['min_notional'] = $symbolInfo['min_notional'];

            // Step 1: Fetch state from Binance
            $accountInfo = $this->binanceApi->getAccountInfo();
            $openOrders = $this->binanceApi->getOpenOrders($symbol);
            $currentPrice = $this->binanceApi->getCurrentPrice($symbol);

            // Extract balances
            $balances = $this->dataMapper->extractBalances($accountInfo);
            $quoteBalance = (float)($balances['USDC']['free'] ?? 0);
            $baseBalance = (float)($balances['SOL']['free'] ?? 0);

            // Sync fills from Binance
            $this->syncFills($basket, $symbol);

            // Get fills for VWAP calculation
            $fills = $this->fillRepository->findByBasket($basket);
            $fillsArray = array_map(fn($fill) => [
                'side' => $fill->getSide(),
                'price' => $fill->getPrice(),
                'qty' => $fill->getQuantity(),
            ], $fills);

            // Calculate position
            $totalBuyQty = $this->fillRepository->getTotalBuyQuantity($basket);
            $totalSellQty = $this->fillRepository->getTotalSellQuantity($basket);
            $positionQty = $totalBuyQty - $totalSellQty;

            // Step 2: Prepare state for strategy
            // Convert UUID to base58 for shorter clientOrderId (Binance limit is 36 chars)
            $basketIdShort = $basket->getId()->toBase58();

            $state = [
                'basket_id' => $basketIdShort,
                'available_quote_balance' => $quoteBalance,
                'available_base_balance' => $baseBalance,
                'position_base_qty' => $positionQty,
                'fills_history' => $fillsArray,
                'basket_created_at' => $basket->getCreatedAt(),
            ];

            $market = [
                'last_trade_price' => $currentPrice,
            ];

            // Step 3: Call Strategy
            $desiredOrders = $this->strategy->computeDesiredOrders($config, $state, $market);

            $this->logger->info('Strategy computed orders', [
                'basket_id' => $basket->getId(),
                'buys' => count($desiredOrders['buys']),
                'sells' => count($desiredOrders['sells']),
                'meta' => $desiredOrders['meta'],
                'buy_orders' => array_map(fn($o) => [
                    'clientId' => $o['clientId'],
                    'price' => $o['price'],
                    'qty' => $o['qty'],
                ], $desiredOrders['buys']),
                'current_price' => $currentPrice,
                'quote_balance' => $quoteBalance,
            ]);

            // Check reanchor suggestion
            if ($desiredOrders['meta']['reanchor_suggested'] ?? false) {
                $this->logger->warning('Reanchor suggested', [
                    'basket_id' => $basket->getId(),
                    'current_price' => $currentPrice,
                    'old_anchor' => $basket->getAnchorPrice(),
                ]);

                // Auto-reanchor: Update anchor price to current price
                $basket->setAnchorPrice((string)$currentPrice);

                // Update config JSONB with new anchor
                $basketConfig = $basket->getConfig();
                $basketConfig['anchor_price_P0'] = $currentPrice;
                $basket->setConfig($basketConfig);
                $this->basketRepository->save($basket);

                // Update local config for strategy recomputation
                $config['anchor_price_P0'] = $currentPrice;

                $this->logger->info('Basket reanchored', [
                    'basket_id' => $basket->getId(),
                    'new_anchor' => $currentPrice,
                ]);

                // Recompute desired orders with new anchor
                $desiredOrders = $this->strategy->computeDesiredOrders($config, $state, $market);
                $this->logger->info('Orders recomputed after reanchor', [
                    'basket_id' => $basket->getId(),
                    'buys' => count($desiredOrders['buys']),
                    'sells' => count($desiredOrders['sells']),
                ]);
            }

            // Step 4: Reconcile
            $allDesired = array_merge($desiredOrders['buys'], $desiredOrders['sells']);
            /** @var array<int, array{clientOrderId: string, price: float|string, origQty: float|string, status: string}> $openOrders */
            $reconcileResult = $this->reconciler->reconcile($allDesired, $openOrders);

            $this->logger->info('Reconciliation result', [
                'basket_id' => $basket->getId(),
                'to_cancel' => count($reconcileResult['to_cancel']),
                'to_create' => count($reconcileResult['to_create']),
                'orders_to_create' => array_map(fn($o) => [
                    'clientId' => $o['clientId'],
                    'price' => $o['price'],
                    'qty' => $o['qty'],
                ], $reconcileResult['to_create']),
            ]);

            // Step 5: Execute (only if system is running)
            if ($this->systemStatus->isStopped()) {
                $this->logger->warning('System is STOPPED - skipping order execution', [
                    'basket_id' => $basket->getId(),
                    'system_status' => $this->systemStatus->getStatus(),
                ]);
                return;
            }

            $this->executor->executeReconciliation($symbol, $reconcileResult, $basket);
        } catch (BinanceException $e) {
            $this->logger->error(sprintf(
                'Binance API error processing basket %s: %s (Code: %d) at %s:%d',
                $basket->getId(),
                $e->getMessage(),
                $e->getBinanceCode(),
                $e->getFile(),
                $e->getLine()
            ), [
                'basket_id' => $basket->getId(),
                'error' => $e->getMessage(),
                'binance_code' => $e->getBinanceCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Error processing basket %s: %s (%s) at %s:%d',
                $basket->getId(),
                $e->getMessage(),
                get_class($e),
                $e->getFile(),
                $e->getLine()
            ), [
                'basket_id' => $basket->getId(),
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Sync fills from Binance to database
     */
    private function syncFills(Basket $basket, string $symbol): void
    {
        try {
            // Get fills from last 24 hours
            $startTime = (time() - 86400) * 1000;
            $trades = $this->binanceApi->getMyTrades($symbol, $startTime);

            // TODO: Map trades to fills and save to database
            // This requires matching trades with orders
            // For now, we'll log the count
            $this->logger->debug('Synced fills', [
                'basket_id' => $basket->getId(),
                'trades_count' => count($trades),
            ]);
        } catch (BinanceException $e) {
            $this->logger->warning('Failed to sync fills', [
                'basket_id' => $basket->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create periodic account snapshot
     */
    private function createAccountSnapshot(): void
    {
        try {
            $accountInfo = $this->binanceApi->getAccountInfo();
            $balances = $this->dataMapper->extractBalances($accountInfo);

            $quoteBalance = (float)($balances['USDC']['free'] ?? 0);
            $baseBalance = (float)($balances['SOL']['free'] ?? 0);

            // Estimate total value (simplified - assumes 1:1 for now)
            $totalValue = $quoteBalance + $baseBalance;

            $snapshot = new AccountSnapshot();
            $snapshot->setQuoteBalance((string)$quoteBalance);
            $snapshot->setBaseBalance((string)$baseBalance);
            $snapshot->setTotalValue((string)$totalValue);

            $this->snapshotRepository->save($snapshot);

            $this->logger->debug('Account snapshot created', [
                'quote' => $quoteBalance,
                'base' => $baseBalance,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to create account snapshot', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
