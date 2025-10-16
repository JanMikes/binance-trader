<?php

declare(strict_types=1);

namespace App\Service\Binance;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class BinanceApiClient
{
    private readonly string $baseUrl;
    private readonly RateLimiter $rateLimiter;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey,
        private readonly string $apiSecret,
        bool $useTestnet = true,
        string $testnetUrl = 'https://testnet.binance.vision',
        string $productionUrl = 'https://api.binance.com'
    ) {
        $this->baseUrl = $useTestnet ? $testnetUrl : $productionUrl;
        $this->rateLimiter = new RateLimiter(1200, 60);
    }

    /**
     * Get account information including balances
     * @return array<string, mixed>
     * @phpstan-return array<string, mixed>
     */
    public function getAccountInfo(): array
    {
        /** @phpstan-var array<string, mixed> */
        return $this->signedRequest('GET', '/api/v3/account');
    }

    /**
     * Get all open orders for a symbol
     * @return array<int, array<string, mixed>>
     * @phpstan-return array<int, array<string, mixed>>
     */
    public function getOpenOrders(string $symbol): array
    {
        /** @phpstan-var array<int, array<string, mixed>> */
        return $this->signedRequest('GET', '/api/v3/openOrders', [
            'symbol' => $symbol,
        ]);
    }

    /**
     * Get specific order by client order ID
     * @return array<string, mixed>|null
     * @phpstan-return array<string, mixed>|null
     */
    public function getOrder(string $symbol, string $clientOrderId): ?array
    {
        try {
            /** @phpstan-var array<string, mixed> */
            return $this->signedRequest('GET', '/api/v3/order', [
                'symbol' => $symbol,
                'origClientOrderId' => $clientOrderId,
            ]);
        } catch (BinanceException $e) {
            if ($e->getBinanceCode() === -2013) {
                // Order does not exist
                return null;
            }
            throw $e;
        }
    }

    /**
     * Place a new order
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @phpstan-return array<string, mixed>
     */
    public function placeOrder(string $symbol, string $side, string $type, array $params = []): array
    {
        $orderParams = array_merge([
            'symbol' => $symbol,
            'side' => $side,
            'type' => $type,
        ], $params);

        /** @phpstan-var array<string, mixed> */
        return $this->signedRequest('POST', '/api/v3/order', $orderParams);
    }

    /**
     * Cancel an order by client order ID
     * @return array<string, mixed>
     * @phpstan-return array<string, mixed>
     */
    public function cancelOrder(string $symbol, string $clientOrderId): array
    {
        /** @phpstan-var array<string, mixed> */
        return $this->signedRequest('DELETE', '/api/v3/order', [
            'symbol' => $symbol,
            'origClientOrderId' => $clientOrderId,
        ]);
    }

    /**
     * Get current price for a symbol
     */
    public function getCurrentPrice(string $symbol): float
    {
        $this->rateLimiter->acquire();

        $response = $this->httpClient->request('GET', $this->baseUrl . '/api/v3/ticker/price', [
            'query' => ['symbol' => $symbol],
        ]);

        $data = $response->toArray();
        return (float)$data['price'];
    }

    /**
     * Get trade history for a symbol
     * @return array<int, array<string, mixed>>
     * @phpstan-return array<int, array<string, mixed>>
     */
    public function getMyTrades(string $symbol, ?int $startTime = null, int $limit = 1000): array
    {
        $params = [
            'symbol' => $symbol,
            'limit' => $limit,
        ];

        if ($startTime !== null) {
            $params['startTime'] = $startTime;
        }

        /** @phpstan-var array<int, array<string, mixed>> */
        return $this->signedRequest('GET', '/api/v3/myTrades', $params);
    }

    /**
     * Get exchange information (filters, tick size, lot size, etc.)
     * @return array<string, mixed>
     */
    public function getExchangeInfo(string $symbol): array
    {
        $this->rateLimiter->acquire();

        $response = $this->httpClient->request('GET', $this->baseUrl . '/api/v3/exchangeInfo', [
            'query' => ['symbol' => $symbol],
        ]);

        $data = $response->toArray();

        if (!isset($data['symbols'][0])) {
            throw new BinanceException("Symbol {$symbol} not found in exchange info");
        }

        return $data['symbols'][0];
    }

    /**
     * Execute a signed request with retry logic
     * @param array<string, mixed> $params
     * @return array<string, mixed>|array<int, array<string, mixed>>
     */
    private function signedRequest(string $method, string $endpoint, array $params = []): array
    {
        $maxRetries = 3;
        $attempt = 0;

        while ($attempt < $maxRetries) {
            try {
                $this->rateLimiter->acquire();

                $params['timestamp'] = (int)(microtime(true) * 1000);
                $params['recvWindow'] = 60000;

                $queryString = http_build_query($params);
                $signature = hash_hmac('sha256', $queryString, $this->apiSecret);
                $params['signature'] = $signature;

                $options = [
                    'headers' => [
                        'X-MBX-APIKEY' => $this->apiKey,
                    ],
                ];

                if ($method === 'GET' || $method === 'DELETE') {
                    $options['query'] = $params;
                    $url = $this->baseUrl . $endpoint;
                } else {
                    $options['body'] = $params;
                    $url = $this->baseUrl . $endpoint;
                }

                $response = $this->httpClient->request($method, $url, $options);
                $data = $response->toArray();

                return $data;
            } catch (\Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface $e) {
                $attempt++;
                $statusCode = $e->getResponse()->getStatusCode();

                if ($statusCode === 429) {
                    // Rate limit exceeded
                    $this->logger->warning('Rate limit exceeded, retrying...', [
                        'attempt' => $attempt,
                        'endpoint' => $endpoint,
                    ]);
                    sleep(2 ** $attempt); // Exponential backoff
                    continue;
                }

                if ($statusCode >= 500) {
                    // Server error, retry
                    $this->logger->warning('Binance server error, retrying...', [
                        'attempt' => $attempt,
                        'status' => $statusCode,
                        'endpoint' => $endpoint,
                    ]);
                    sleep(2 ** $attempt);
                    continue;
                }

                // Client error, parse error message
                try {
                    $errorData = $e->getResponse()->toArray(false);
                    throw new BinanceException(
                        $errorData['msg'] ?? 'Unknown Binance API error',
                        $errorData['code'] ?? 0,
                        $errorData
                    );
                } catch (\Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface) {
                    throw new BinanceException(
                        'Failed to decode Binance error response',
                        0,
                        null,
                        0,
                        $e
                    );
                }
            } catch (\Throwable $e) {
                $attempt++;
                if ($attempt >= $maxRetries) {
                    $this->logger->error('Binance API request failed after retries', [
                        'endpoint' => $endpoint,
                        'error' => $e->getMessage(),
                    ]);
                    throw $e;
                }
                sleep(2 ** $attempt);
            }
        }

        throw new BinanceException('Max retries exceeded');
    }
}
