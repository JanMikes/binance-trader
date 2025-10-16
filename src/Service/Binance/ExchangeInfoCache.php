<?php

declare(strict_types=1);

namespace App\Service\Binance;

use Psr\Log\LoggerInterface;

/**
 * Caches exchange info (tick size, lot size, etc.) to reduce API calls
 */
class ExchangeInfoCache
{
    /** @var array<string, array{tick_size: string, lot_size: string, min_notional: string, cached_at: int}> */
    private array $cache = [];
    private const CACHE_TTL = 86400; // 24 hours

    public function __construct(
        private readonly BinanceApiClient $binanceApi,
        private readonly BinanceDataMapper $dataMapper,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Get exchange info for a symbol (with caching)
     * @return array{tick_size: string, lot_size: string, min_notional: string}
     */
    public function getSymbolFilters(string $symbol): array
    {
        // Check cache
        if (isset($this->cache[$symbol])) {
            $cached = $this->cache[$symbol];
            $age = time() - $cached['cached_at'];

            if ($age < self::CACHE_TTL) {
                $this->logger->debug('Using cached exchange info', [
                    'symbol' => $symbol,
                    'age_seconds' => $age,
                ]);

                return [
                    'tick_size' => $cached['tick_size'],
                    'lot_size' => $cached['lot_size'],
                    'min_notional' => $cached['min_notional'],
                ];
            }
        }

        // Fetch fresh data
        $this->logger->info('Fetching exchange info from Binance', [
            'symbol' => $symbol,
        ]);

        $exchangeInfo = $this->binanceApi->getExchangeInfo($symbol);
        $filters = $this->dataMapper->extractSymbolFilters($exchangeInfo);

        // Cache it
        $this->cache[$symbol] = [
            'tick_size' => $filters['tick_size'],
            'lot_size' => $filters['lot_size'],
            'min_notional' => $filters['min_notional'],
            'cached_at' => time(),
        ];

        return $filters;
    }

    /**
     * Clear cache for a symbol or all symbols
     */
    public function clearCache(?string $symbol = null): void
    {
        if ($symbol === null) {
            $this->cache = [];
            $this->logger->info('Cleared all exchange info cache');
        } else {
            unset($this->cache[$symbol]);
            $this->logger->info('Cleared exchange info cache for symbol', ['symbol' => $symbol]);
        }
    }
}
