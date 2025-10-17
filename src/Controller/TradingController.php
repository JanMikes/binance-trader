<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\BasketRepository;
use App\Service\Trading\BinanceDataSyncService;
use App\Service\Trading\EmergencyCloseService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TradingController extends AbstractController
{
    public function __construct(
        private readonly BasketRepository $basketRepository,
        private readonly EmergencyCloseService $emergencyCloseService,
        private readonly BinanceDataSyncService $dataSyncService
    ) {
    }

    #[Route('/trading/close-all', name: 'trading_close_all', methods: ['POST'])]
    public function closeAll(Request $request): Response
    {
        // Validate CSRF token
        if (!$this->isCsrfTokenValid('close-all', (string)$request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token');
            return $this->redirectToRoute('dashboard');
        }

        $basket = $this->basketRepository->findActiveBasket();

        if ($basket === null) {
            $this->addFlash('error', 'No active basket found');
            return $this->redirectToRoute('dashboard');
        }

        // Execute emergency close
        $result = $this->emergencyCloseService->closeAllPositions($basket->getId());

        if ($result['success']) {
            $this->addFlash('success', $result['message']);
        } else {
            $this->addFlash('error', $result['message']);
        }

        return $this->redirectToRoute('dashboard');
    }

    #[Route('/trading/refresh', name: 'trading_refresh', methods: ['POST'])]
    public function refresh(Request $request): Response
    {
        // Validate CSRF token
        if (!$this->isCsrfTokenValid('refresh', (string)$request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token');
            return $this->redirectToRoute('dashboard');
        }

        // Execute data sync
        $result = $this->dataSyncService->syncActiveBasket();

        if ($result['success']) {
            $this->addFlash('success', $result['message']);
        } else {
            $this->addFlash('error', $result['message']);
        }

        return $this->redirectToRoute('dashboard');
    }
}
