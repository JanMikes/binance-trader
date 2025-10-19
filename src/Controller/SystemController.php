<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Basket;
use App\Repository\BasketRepository;
use App\Repository\FillRepository;
use App\Service\Binance\BinanceApiClient;
use App\Service\Binance\BinanceDataMapper;
use App\Service\Binance\BinanceException;
use App\Service\Strategy\StrategyInterface;
use App\Service\SystemStatusService;
use App\Service\Trading\OrderExecutor;
use App\Service\Trading\OrderReconciler;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SystemController extends AbstractController
{
    public function __construct(
        private readonly SystemStatusService $systemStatus,
        private readonly BasketRepository $basketRepository,
        private readonly FillRepository $fillRepository,
        private readonly BinanceApiClient $binanceApi,
        private readonly BinanceDataMapper $dataMapper,
        private readonly StrategyInterface $strategy,
        private readonly OrderReconciler $reconciler,
        private readonly OrderExecutor $executor,
        private readonly LoggerInterface $logger
    ) {
    }

    #[Route('/system/start', name: 'system_start', methods: ['POST'])]
    public function start(Request $request): Response
    {
        // Validate CSRF token
        if (!$this->isCsrfTokenValid('system-action', (string)$request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token');
            return $this->redirectToRoute('dashboard');
        }

        $this->systemStatus->start();
        $this->addFlash('success', 'System started - automatic order processing enabled');

        return $this->redirectToRoute('dashboard');
    }

    #[Route('/system/stop', name: 'system_stop', methods: ['POST'])]
    public function stop(Request $request): Response
    {
        // Validate CSRF token
        if (!$this->isCsrfTokenValid('system-action', (string)$request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token');
            return $this->redirectToRoute('dashboard');
        }

        $this->systemStatus->stop();
        $this->addFlash('warning', 'System stopped - no automatic order processing');

        return $this->redirectToRoute('dashboard');
    }

    #[Route('/system/run', name: 'system_run', methods: ['POST'])]
    public function run(Request $request): Response
    {
        // Validate CSRF token
        if (!$this->isCsrfTokenValid('system-action', (string)$request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token');
            return $this->redirectToRoute('dashboard');
        }

        // Manually trigger one processing cycle
        try {
            $baskets = $this->basketRepository->findActiveBaskets();

            if (count($baskets) === 0) {
                $this->addFlash('info', 'No active baskets found');
                return $this->redirectToRoute('dashboard');
            }

            foreach ($baskets as $basket) {
                $this->processBasket($basket);
            }

            $this->addFlash('success', sprintf('Manual cycle completed - processed %d basket(s)', count($baskets)));
        } catch (\Throwable $e) {
            $this->logger->error('Manual cycle failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->addFlash('error', 'Manual cycle failed: ' . $e->getMessage());
        }

        return $this->redirectToRoute('dashboard');
    }

    /**
     * Process a single basket (extracted from Orchestrator logic)
     */
    private function processBasket(Basket $basket): void
    {
        $this->logger->info('Manual processing basket', [
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

            // Fetch exchange info for market parameters
            $exchangeInfo = $this->binanceApi->getExchangeInfo($symbol);
            $symbolInfo = $this->dataMapper->extractSymbolInfo($exchangeInfo, $symbol);
            $config['tick_size'] = $symbolInfo['tick_size'];
            $config['lot_size'] = $symbolInfo['lot_size'];
            $config['min_notional'] = $symbolInfo['min_notional'];

            // Fetch state from Binance
            $accountInfo = $this->binanceApi->getAccountInfo();
            $openOrders = $this->binanceApi->getOpenOrders($symbol);
            $currentPrice = $this->binanceApi->getCurrentPrice($symbol);

            // Extract balances
            $balances = $this->dataMapper->extractBalances($accountInfo);
            $quoteBalance = (float)($balances['USDC']['free'] ?? 0);
            $baseBalance = (float)($balances['SOL']['free'] ?? 0);

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

            // Convert UUID to base58 for shorter clientOrderId
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

            // Call Strategy
            $desiredOrders = $this->strategy->computeDesiredOrders($config, $state, $market);

            $this->logger->info('Manual cycle - strategy computed orders', [
                'basket_id' => $basket->getId(),
                'buys' => count($desiredOrders['buys']),
                'sells' => count($desiredOrders['sells']),
                'reanchor_suggested' => $desiredOrders['meta']['reanchor_suggested'] ?? false,
            ]);

            // Check reanchor suggestion
            if ($desiredOrders['meta']['reanchor_suggested'] ?? false) {
                $this->logger->warning('Manual cycle - reanchor suggested', [
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

                $this->logger->info('Manual cycle - basket reanchored', [
                    'basket_id' => $basket->getId(),
                    'new_anchor' => $currentPrice,
                ]);

                // Recompute desired orders with new anchor
                $desiredOrders = $this->strategy->computeDesiredOrders($config, $state, $market);
                $this->logger->info('Manual cycle - orders recomputed after reanchor', [
                    'basket_id' => $basket->getId(),
                    'buys' => count($desiredOrders['buys']),
                    'sells' => count($desiredOrders['sells']),
                ]);
            }

            // Reconcile
            $allDesired = array_merge($desiredOrders['buys'], $desiredOrders['sells']);
            /** @var array<int, array{clientOrderId: string, price: float|string, origQty: float|string, status: string}> $openOrders */
            $reconcileResult = $this->reconciler->reconcile($allDesired, $openOrders);

            $this->logger->info('Manual cycle - reconciliation result', [
                'basket_id' => $basket->getId(),
                'to_cancel' => count($reconcileResult['to_cancel']),
                'to_create' => count($reconcileResult['to_create']),
            ]);

            // Execute reconciliation (manual trigger always executes, regardless of system status)
            $this->executor->executeReconciliation($symbol, $reconcileResult, $basket);

            $this->logger->info('Manual cycle - completed', [
                'basket_id' => $basket->getId(),
            ]);
        } catch (BinanceException $e) {
            $this->logger->error('Manual cycle - Binance API error', [
                'basket_id' => $basket->getId(),
                'error' => $e->getMessage(),
                'binance_code' => $e->getBinanceCode(),
            ]);
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('Manual cycle - error', [
                'basket_id' => $basket->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
