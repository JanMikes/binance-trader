<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\BasketRepository;
use App\Repository\OrderRepository;
use App\Service\Binance\BinanceApiClient;
use App\Service\Trading\PositionCalculator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    public function __construct(
        private readonly BasketRepository $basketRepository,
        private readonly OrderRepository $orderRepository,
        private readonly BinanceApiClient $binanceApi,
        private readonly PositionCalculator $positionCalculator
    ) {
    }

    #[Route('/', name: 'dashboard')]
    public function index(): Response
    {
        $basket = $this->basketRepository->findActiveBasket();

        if ($basket === null) {
            return $this->render('dashboard/no_basket.html.twig');
        }

        // Get current price
        try {
            $currentPrice = $this->binanceApi->getCurrentPrice($basket->getSymbol());
        } catch (\Throwable $e) {
            $currentPrice = 0.0;
        }

        // Get open orders
        $openOrders = $this->orderRepository->findOpenOrdersByBasket($basket);

        // Get recent filled orders
        $filledOrders = $this->orderRepository->findFilledOrdersByBasket($basket);

        // Get position summary
        $positionSummary = $this->positionCalculator->getPositionSummary($basket, $currentPrice);

        return $this->render('dashboard/index.html.twig', [
            'basket' => $basket,
            'current_price' => $currentPrice,
            'open_orders' => $openOrders,
            'filled_orders' => array_slice($filledOrders, 0, 20), // Last 20
            'position' => $positionSummary,
        ]);
    }

    #[Route('/orders/history', name: 'orders_history')]
    public function ordersHistory(): Response
    {
        $basket = $this->basketRepository->findActiveBasket();

        if ($basket === null) {
            return $this->redirectToRoute('dashboard');
        }

        $allOrders = $this->orderRepository->findAllByBasket($basket);

        return $this->render('dashboard/orders_history.html.twig', [
            'basket' => $basket,
            'orders' => $allOrders,
        ]);
    }
}
