<?php

require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

// Load environment
(new Dotenv())->load(__DIR__ . '/../.env.local');

$apiKey = $_ENV['BINANCE_API_KEY'] ?? '';
$apiSecret = $_ENV['BINANCE_API_SECRET'] ?? '';
$useTestnet = $_ENV['BINANCE_USE_TESTNET'] ?? 'true';

echo "=== Binance API Test ===\n";
echo "API Key: " . substr($apiKey, 0, 20) . "..." . substr($apiKey, -10) . "\n";
echo "API Secret: " . substr($apiSecret, 0, 20) . "..." . substr($apiSecret, -10) . "\n";
echo "Use Testnet: $useTestnet\n";
echo "Base URL: " . ($useTestnet === 'true' ? 'https://testnet.binance.vision' : 'https://api.binance.com') . "\n\n";

// Test request
$baseUrl = $useTestnet === 'true' ? 'https://testnet.binance.vision' : 'https://api.binance.com';
$endpoint = '/api/v3/account';

$timestamp = (int)(microtime(true) * 1000);
$recvWindow = 60000;

$params = [
    'timestamp' => $timestamp,
    'recvWindow' => $recvWindow,
];

$queryString = http_build_query($params);
$signature = hash_hmac('sha256', $queryString, $apiSecret);

$fullUrl = $baseUrl . $endpoint . '?' . $queryString . '&signature=' . $signature;

echo "=== REQUEST DETAILS ===\n";
echo "Method: GET\n";
echo "Endpoint: $endpoint\n";
echo "Timestamp: $timestamp\n";
echo "Query String (before signature): $queryString\n";
echo "Signature: $signature\n";
echo "Full URL: $fullUrl\n\n";

echo "=== HEADERS ===\n";
echo "X-MBX-APIKEY: $apiKey\n\n";

// Make actual request
echo "=== MAKING REQUEST ===\n";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $fullUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'X-MBX-APIKEY: ' . $apiKey,
]);
curl_setopt($ch, CURLOPT_VERBOSE, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "\n=== RESPONSE ===\n";
echo "HTTP Code: $httpCode\n";
if ($error) {
    echo "Error: $error\n";
}
echo "Response:\n";
echo $response . "\n";

if ($httpCode === 200) {
    echo "\n✅ SUCCESS! API credentials are working.\n";
} else {
    echo "\n❌ FAILED! Check the response above for details.\n";

    // Parse error if JSON
    $decoded = json_decode($response, true);
    if ($decoded) {
        echo "Error Code: " . ($decoded['code'] ?? 'N/A') . "\n";
        echo "Error Message: " . ($decoded['msg'] ?? 'N/A') . "\n";
    }
}
