<?php

declare(strict_types=1);

/**
 * Example: Bybit P2P API Integration
 *
 * This example demonstrates how to integrate with the Bybit P2P API to fetch
 * real-time advertisement data and use it with the P2P Path Finder library.
 *
 * It covers:
 * - Mock Bybit P2P API client implementation
 * - Mapping Bybit ad data to Order objects
 * - Handling pagination and filtering
 * - Complete workflow from API to path finding
 * - Error handling for API and library errors
 * - Production-ready patterns
 *
 * API Documentation: https://bybit-exchange.github.io/docs/p2p/ad/online-ad-list
 *
 * Run: php examples/bybit-p2p-integration.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Brick\Math\RoundingMode;
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Application\Service\PathFinderService;
use SomeWork\P2PPathFinder\Application\Service\PathSearchRequest;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\AssetPair;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Domain\ValueObject\OrderBounds;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

try {

echo "\n";
echo "╔════════════════════════════════════════════════════════════════════════════╗\n";
echo "║                    Bybit P2P API Integration Example                       ║\n";
echo "╚════════════════════════════════════════════════════════════════════════════╝\n";
echo "\n";

// ============================================================================
// Part 1: Mock Bybit P2P API Client
// ============================================================================

/**
 * Mock Bybit P2P API Client
 * 
 * In production, this would make real HTTP requests to Bybit's API.
 * For this example, we use mock data based on Bybit's API response format.
 * 
 * API Endpoint: POST /v5/p2p/item/online
 * Documentation: https://bybit-exchange.github.io/docs/p2p/ad/online-ad-list
 */
class BybitP2PClient
{
    /**
     * Get online P2P advertisements
     * 
     * @param string $tokenId Token ID (e.g., USDT, BTC, ETH)
     * @param string $currencyId Currency ID (e.g., USD, EUR, GBP)
     * @param string $side "0" for buy, "1" for sell
     * @param string $page Page number (default: "1")
     * @param string $size Page size (default: "10", max: "100")
     * @return array API response
     */
    public function getOnlineAds(
        string $tokenId,
        string $currencyId,
        string $side,
        string $page = "1",
        string $size = "10"
    ): array {
        // In production, this would make a real API call:
        // POST https://api.bybit.com/v5/p2p/item/online
        // Headers: X-BAPI-API-KEY, X-BAPI-SIGN, X-BAPI-TIMESTAMP, X-BAPI-RECV-WINDOW
        
        // For this example, we return mock data based on real Bybit response format
        return $this->getMockResponse($tokenId, $currencyId, $side);
    }
    
    /**
     * Mock response generator
     * 
     * Returns realistic data matching Bybit's actual API response structure
     * Based on: https://bybit-exchange.github.io/docs/p2p/ad/online-ad-list
     */
    private function getMockResponse(string $tokenId, string $currencyId, string $side): array
    {
        // Mock data representing different currency pairs and market conditions
        $mockAds = [
            // USD -> USDT market
            ['tokenId' => 'USDT', 'currencyId' => 'USD', 'side' => '0', 'price' => '1.0001', 'lastQuantity' => '10000', 'minAmount' => '100', 'maxAmount' => '5000', 'nickName' => 'USDTrader1'],
            ['tokenId' => 'USDT', 'currencyId' => 'USD', 'side' => '0', 'price' => '1.0002', 'lastQuantity' => '20000', 'minAmount' => '50', 'maxAmount' => '10000', 'nickName' => 'USDTrader2'],
            ['tokenId' => 'USDT', 'currencyId' => 'USD', 'side' => '1', 'price' => '0.9999', 'lastQuantity' => '15000', 'minAmount' => '100', 'maxAmount' => '8000', 'nickName' => 'USDSeller'],
            
            // EUR -> USDT market
            ['tokenId' => 'USDT', 'currencyId' => 'EUR', 'side' => '0', 'price' => '0.92', 'lastQuantity' => '20000', 'minAmount' => '20', 'maxAmount' => '18400', 'nickName' => 'EuroTrader'],
            ['tokenId' => 'USDT', 'currencyId' => 'EUR', 'side' => '0', 'price' => '0.93', 'lastQuantity' => '10000', 'minAmount' => '200', 'maxAmount' => '9300', 'nickName' => 'cjmtest'],
            ['tokenId' => 'USDT', 'currencyId' => 'EUR', 'side' => '1', 'price' => '0.91', 'lastQuantity' => '5000', 'minAmount' => '10', 'maxAmount' => '4550', 'nickName' => 'Saaaul'],
            
            // USDT -> BTC market
            ['tokenId' => 'BTC', 'currencyId' => 'USDT', 'side' => '0', 'price' => '0.000025', 'lastQuantity' => '2.5', 'minAmount' => '500', 'maxAmount' => '50000', 'nickName' => 'BTCBuyer1'],
            ['tokenId' => 'BTC', 'currencyId' => 'USDT', 'side' => '0', 'price' => '0.000026', 'lastQuantity' => '5.0', 'minAmount' => '1000', 'maxAmount' => '100000', 'nickName' => 'BTCBuyer2'],
            
            // GBP -> USDT market
            ['tokenId' => 'USDT', 'currencyId' => 'GBP', 'side' => '0', 'price' => '0.79', 'lastQuantity' => '8000', 'minAmount' => '100', 'maxAmount' => '6320', 'nickName' => 'GBPTrader'],
            ['tokenId' => 'USDT', 'currencyId' => 'GBP', 'side' => '1', 'price' => '0.78', 'lastQuantity' => '12000', 'minAmount' => '50', 'maxAmount' => '9360', 'nickName' => 'GBPSeller'],
        ];
        
        // Filter ads based on request parameters
        $filteredAds = array_filter($mockAds, function($ad) use ($tokenId, $currencyId, $side) {
            return $ad['tokenId'] === $tokenId 
                && $ad['currencyId'] === $currencyId 
                && $ad['side'] === $side;
        });
        
        // Determine scale based on token/currency type
        $tokenScale = ($tokenId === 'BTC') ? 8 : 4;
        $currencyScale = ($currencyId === 'USDT') ? 4 : 2;
        
        // Build response in Bybit's exact format
        $items = [];
        foreach ($filteredAds as $idx => $ad) {
            $items[] = [
                'id' => (string)(1899658238346616832 + $idx),
                'accountId' => (string)(290120 + $idx),
                'userId' => 290118 + $idx,
                'nickName' => $ad['nickName'],
                'tokenId' => $ad['tokenId'],
                'tokenName' => '',
                'currencyId' => $ad['currencyId'],
                'side' => (int)$ad['side'],
                'priceType' => 0,
                'price' => $ad['price'],
                'premium' => '0',
                'lastQuantity' => $ad['lastQuantity'],
                'quantity' => $ad['lastQuantity'],
                'frozenQuantity' => '0',
                'executedQuantity' => '0',
                'minAmount' => $ad['minAmount'],
                'maxAmount' => $ad['maxAmount'],
                'remark' => 'Mock advertisement for testing',
                'status' => 10,
                'createDate' => (string)(time() * 1000),
                'payments' => ['14', '377'],
                'orderNum' => 0,
                'finishNum' => 0,
                'recentOrderNum' => 0,
                'recentExecuteRate' => 0,
                'fee' => '',
                'isOnline' => true,
                'lastLogoutTime' => (string)time(),
                'blocked' => 'N',
                'makerContact' => false,
                'symbolInfo' => [
                    'id' => '13',
                    'exchangeId' => '301',
                    'orgId' => '9001',
                    'tokenId' => $ad['tokenId'],
                    'currencyId' => $ad['currencyId'],
                    'status' => 1,
                    'lowerLimitAlarm' => 90,
                    'upperLimitAlarm' => 110,
                    'itemDownRange' => '70',
                    'itemUpRange' => '130',
                    'currencyMinQuote' => $ad['minAmount'],
                    'currencyMaxQuote' => $ad['maxAmount'],
                    'currencyLowerMaxQuote' => $ad['minAmount'],
                    'tokenMinQuote' => '1',
                    'tokenMaxQuote' => $ad['lastQuantity'],
                    'kycCurrencyLimit' => '900',
                    'itemSideLimit' => 3,
                    'buyFeeRate' => '0',
                    'sellFeeRate' => '0',
                    'orderAutoCancelMinute' => 15,
                    'orderFinishMinute' => 10,
                    'tradeSide' => 9,
                    'currency' => [
                        'id' => '14',
                        'exchangeId' => '0',
                        'orgId' => '9001',
                        'currencyId' => $ad['currencyId'],
                        'scale' => $currencyScale,
                    ],
                    'token' => [
                        'id' => '1',
                        'exchangeId' => '0',
                        'orgId' => '9001',
                        'tokenId' => $ad['tokenId'],
                        'scale' => $tokenScale,
                        'sequence' => 1,
                    ],
                    'buyAd' => null,
                    'sellAd' => null,
                ],
                'tradingPreferenceSet' => [
                    'hasUnPostAd' => 0,
                    'isKyc' => 1,
                    'isEmail' => 0,
                    'isMobile' => 0,
                    'hasRegisterTime' => 0,
                    'registerTimeThreshold' => 0,
                    'orderFinishNumberDay30' => 60,
                    'completeRateDay30' => '95',
                    'nationalLimit' => '',
                    'hasOrderFinishNumberDay30' => 1,
                    'hasCompleteRateDay30' => 1,
                    'hasNationalLimit' => 0,
                ],
                'version' => 0,
                'authStatus' => 1,
                'recommend' => false,
                'recommendTag' => '',
                'authTag' => ['BA'],
                'userType' => $idx % 2 === 0 ? 'ORG' : 'PERSONAL',
                'itemType' => 'ORIGIN',
                'paymentPeriod' => 15,
            ];
        }
        
        return [
            'ret_code' => 0,
            'ret_msg' => 'SUCCESS',
            'result' => [
                'count' => count($items),
                'items' => $items,
            ],
            'ext_code' => '',
            'ext_info' => [],
            'time_now' => (string)microtime(true),
        ];
    }
}

echo str_repeat('=', 80) . "\n";
echo "Part 1: Mock Bybit P2P API Client Created\n";
echo str_repeat('=', 80) . "\n\n";

echo "✓ BybitP2PClient class defined\n";
echo "  - Implements getOnlineAds() method\n";
echo "  - Returns mock data matching exact Bybit API response format\n";
echo "  - Includes all fields: symbolInfo, tradingPreferenceSet, authTag, etc.\n";
echo "  - In production, would make real HTTP requests\n\n";

// ============================================================================
// Part 2: Order Converter - Map Bybit Ads to Library Orders
// ============================================================================

/**
 * Converts Bybit P2P advertisements to PathFinder Order objects
 * 
 * Handles the mapping between Bybit's API format and the library's domain objects.
 * 
 * Supports extracting scale information from Bybit's symbolInfo if available,
 * or falls back to provided defaults.
 */
class BybitOrderConverter
{
    /**
     * Convert Bybit ad to PathFinder Order
     * 
     * @param array $ad Bybit advertisement data (from API response items)
     * @param int $tokenScale Decimal scale for token (crypto) - default, can be overridden by symbolInfo
     * @param int $currencyScale Decimal scale for currency (fiat) - default, can be overridden by symbolInfo
     * @param int $rateScale Decimal scale for exchange rate
     * @return Order
     */
    public function convertAdToOrder(
        array $ad,
        int $tokenScale = 8,
        int $currencyScale = 2,
        int $rateScale = 8
    ): Order {
        // Extract scale from symbolInfo if available (real Bybit API response)
        if (isset($ad['symbolInfo']['token']['scale'])) {
            $tokenScale = (int)$ad['symbolInfo']['token']['scale'];
        }
        if (isset($ad['symbolInfo']['currency']['scale'])) {
            $currencyScale = (int)$ad['symbolInfo']['currency']['scale'];
        }
        
        // For BUY orders: base=currency, quote=token
        // For SELL orders: base=token, quote=currency
        $tokenId = $ad['tokenId'];
        $currencyId = $ad['currencyId'];
        
        // In Bybit, price = how much currency per token (token -> currency rate)
        $priceRate = ExchangeRate::fromString($tokenId, $currencyId, $ad['price'], $rateScale);
        
        // Determine order side
        // Bybit: 0 = buy (user wants to buy token with currency)
        //        1 = sell (user wants to sell token for currency)
        $sideValue = (int) $ad['side'];
        if (! in_array($sideValue, [0, 1], true)) {
            throw new InvalidInput(sprintf('Unexpected Bybit side value "%s".', (string) $ad['side']));
        }
        $side = $sideValue === 0 ? OrderSide::BUY : OrderSide::SELL;
        
        // For BUY: spend currency, get token -> AssetPair(currency, token)
        // For SELL: spend token, get currency -> AssetPair(token, currency)
        if ($side === OrderSide::BUY) {
            $assetPair = AssetPair::fromString($currencyId, $tokenId);
            $minAmount = Money::fromString($currencyId, $ad['minAmount'], $currencyScale);
            $maxAmount = Money::fromString($currencyId, $ad['maxAmount'], $currencyScale);
            $bounds = OrderBounds::from($minAmount, $maxAmount);
            
            // Exchange rate: currency -> token (invert token -> currency)
            $exchangeRate = $priceRate->invert();
        } else {
            $assetPair = AssetPair::fromString($tokenId, $currencyId);
            
            // Convert currency bounds to token bounds via inverted rate
            $minCurrency = Money::fromString($currencyId, $ad['minAmount'], $currencyScale);
            $maxCurrency = Money::fromString($currencyId, $ad['maxAmount'], $currencyScale);
            $currencyToTokenRate = $priceRate->invert();
            
            $minAmount = $currencyToTokenRate->convert($minCurrency, $tokenScale);
            $maxAmount = $currencyToTokenRate->convert($maxCurrency, $tokenScale);
            $bounds = OrderBounds::from($minAmount, $maxAmount);
            
            // Exchange rate: token -> currency
            $exchangeRate = $priceRate;
        }
        
        return new Order($side, $assetPair, $bounds, $exchangeRate);
    }
    
    /**
     * Convert multiple ads to orders
     * 
     * @param array $ads Array of Bybit advertisements
     * @param int $tokenScale Decimal scale for tokens
     * @param int $currencyScale Decimal scale for currencies
     * @param int $rateScale Decimal scale for exchange rates
     * @return Order[]
     */
    public function convertAdsToOrders(
        array $ads,
        int $tokenScale = 8,
        int $currencyScale = 2,
        int $rateScale = 8
    ): array {
        $orders = [];
        foreach ($ads as $ad) {
            try {
                $orders[] = $this->convertAdToOrder($ad, $tokenScale, $currencyScale, $rateScale);
            } catch (InvalidInput $e) {
                // Skip invalid ads and log in production
                echo "⚠ Skipping invalid ad: {$e->getMessage()}\n";
            }
        }
        return $orders;
    }
}

echo str_repeat('=', 80) . "\n";
echo "Part 2: Order Converter Created\n";
echo str_repeat('=', 80) . "\n\n";

echo "✓ BybitOrderConverter class defined\n";
echo "  - convertAdToOrder() - single ad conversion\n";
echo "  - convertAdsToOrders() - batch conversion\n";
echo "  - Handles BUY/SELL side mapping\n";
echo "  - Converts price to exchange rate correctly\n";
echo "  - Extracts scale information from symbolInfo automatically\n\n";

// ============================================================================
// Part 3: Fetch Ads from Multiple Markets
// ============================================================================

echo str_repeat('=', 80) . "\n";
echo "Part 3: Fetching Advertisements from Bybit P2P API\n";
echo str_repeat('=', 80) . "\n\n";

$client = new BybitP2PClient();
$converter = new BybitOrderConverter();

// Define markets to fetch
$markets = [
    ['tokenId' => 'USDT', 'currencyId' => 'USD', 'side' => '0', 'label' => 'USD → USDT (Buy)'],
    ['tokenId' => 'USDT', 'currencyId' => 'EUR', 'side' => '0', 'label' => 'EUR → USDT (Buy)'],
    ['tokenId' => 'USDT', 'currencyId' => 'GBP', 'side' => '0', 'label' => 'GBP → USDT (Buy)'],
    ['tokenId' => 'BTC', 'currencyId' => 'USDT', 'side' => '0', 'label' => 'USDT → BTC (Buy)'],
];

$allOrders = [];
$totalAds = 0;

echo "Fetching advertisements from multiple markets...\n\n";

foreach ($markets as $market) {
    echo "→ Fetching {$market['label']}...\n";
    
    $response = $client->getOnlineAds(
        $market['tokenId'],
        $market['currencyId'],
        $market['side'],
        '1',  // page
        '20'  // size
    );
    
    if ($response['ret_code'] === 0 && isset($response['result']['items'])) {
        $ads = $response['result']['items'];
        $count = count($ads);
        $totalAds += $count;
        
        echo "  ✓ Fetched {$count} ad(s)\n";
        
        // Convert ads to orders
        $orders = $converter->convertAdsToOrders($ads, 8, 2, 8);
        $allOrders = array_merge($allOrders, $orders);
        
        // Display sample ad
        if ($count > 0) {
            $sample = $ads[0];
            echo "  Sample: {$sample['price']} {$sample['currencyId']} per {$sample['tokenId']}, ";
            echo "Range: {$sample['minAmount']}-{$sample['maxAmount']} {$sample['currencyId']}\n";
        }
    } else {
        echo "  ✗ API error: {$response['ret_msg']}\n";
    }
    
    echo "\n";
}

echo "✓ Total ads fetched: {$totalAds}\n";
echo "✓ Total orders created: " . count($allOrders) . "\n\n";

// ============================================================================
// Part 4: Build Order Book and Find Paths
// ============================================================================

echo str_repeat('=', 80) . "\n";
echo "Part 4: Building Order Book and Finding Optimal Paths\n";
echo str_repeat('=', 80) . "\n\n";

// Create order book from all fetched orders
$orderBook = new OrderBook($allOrders);
echo "✓ Order book created with " . count($allOrders) . " orders\n\n";

// Scenario 1: Find path from USD to BTC
echo "Scenario 1: Finding path from USD to BTC\n";
echo str_repeat('-', 80) . "\n";

$config1 = PathSearchConfig::builder()
    ->withSpendAmount(Money::fromString('USD', '1000.00', 2))
    ->withToleranceBounds('0.00', '0.05')  // 0-5% tolerance
    ->withHopLimits(1, 4)                   // Allow up to 4 hops
    ->withSearchGuards(50000, 100000)      // Reasonable limits
    ->build();

$service = new PathFinderService(new GraphBuilder());
$request1 = new PathSearchRequest($orderBook, $config1, 'BTC');
$outcome1 = $service->findBestPaths($request1);

if ($outcome1->hasPaths()) {
    echo "✓ Found " . count($outcome1->paths()) . " path(s)\n\n";
    
    foreach ($outcome1->paths() as $idx => $path) {
        $num = $idx + 1;
        echo "  Path #{$num}:\n";
        echo "    Spend: {$path->totalSpent()->amount()} {$path->totalSpent()->currency()}\n";
        echo "    Receive: {$path->totalReceived()->amount()} {$path->totalReceived()->currency()}\n";
        echo "    Hops: " . count($path->legs()) . "\n";
        echo "    Residual tolerance: {$path->residualTolerancePercentage()}%\n";
        
        echo "    Route: ";
        $route = [];
        foreach ($path->legs() as $leg) {
            $route[] = $leg->from();
        }
        // Add the final destination
        $legs = iterator_to_array($path->legs());
        if (count($legs) > 0) {
            $route[] = $legs[count($legs) - 1]->to();
        }
        echo implode(' → ', $route) . "\n";
        
        echo "\n";
    }
} else {
    echo "✗ No paths found from USD to BTC\n\n";
}

// Check guard limits
$guardReport1 = $outcome1->guardLimits();
echo "  Guard metrics:\n";
echo "    Expansions: {$guardReport1->expansions()} / {$guardReport1->expansionLimit()}\n";
echo "    States: {$guardReport1->visitedStates()} / {$guardReport1->visitedStateLimit()}\n";
echo "    Time: {$guardReport1->elapsedMilliseconds()} ms\n";

if ($guardReport1->anyLimitReached()) {
    echo "  ⚠ Guard limits reached - results may be incomplete\n";
}

echo "\n";

// Scenario 2: Find path from EUR to BTC
echo "Scenario 2: Finding path from EUR to BTC\n";
echo str_repeat('-', 80) . "\n";

$config2 = PathSearchConfig::builder()
    ->withSpendAmount(Money::fromString('EUR', '500.00', 2))
    ->withToleranceBounds('0.00', '0.03')  // Stricter tolerance
    ->withHopLimits(1, 3)
    ->withSearchGuards(50000, 100000)
    ->build();

$request2 = new PathSearchRequest($orderBook, $config2, 'BTC');
$outcome2 = $service->findBestPaths($request2);

if ($outcome2->hasPaths()) {
    echo "✓ Found " . count($outcome2->paths()) . " path(s)\n\n";
    
    $bestPath = $outcome2->paths()->first();
    echo "  Best Path:\n";
    echo "    Spend: {$bestPath->totalSpent()->amount()} {$bestPath->totalSpent()->currency()}\n";
    echo "    Receive: {$bestPath->totalReceived()->amount()} {$bestPath->totalReceived()->currency()}\n";
    
    // Calculate effective rate using brick/math (received / spent)
    $effectiveRate = $bestPath->totalReceived()->decimal()
        ->dividedBy($bestPath->totalSpent()->decimal(), 10, RoundingMode::HALF_UP);
    echo "    Effective rate: {$effectiveRate} {$bestPath->totalReceived()->currency()}/{$bestPath->totalSpent()->currency()}\n";
    echo "\n";
} else {
    echo "✗ No paths found from EUR to BTC\n\n";
}

// Scenario 3: Multi-hop path finding (GBP → BTC via USDT)
echo "Scenario 3: Finding multi-hop path from GBP to BTC\n";
echo str_repeat('-', 80) . "\n";

$config3 = PathSearchConfig::builder()
    ->withSpendAmount(Money::fromString('GBP', '750.00', 2))
    ->withToleranceBounds('0.00', '0.10')  // More relaxed for multi-hop
    ->withHopLimits(2, 4)                   // Require at least 2 hops
    ->withSearchGuards(50000, 100000)
    ->build();

$request3 = new PathSearchRequest($orderBook, $config3, 'BTC');
$outcome3 = $service->findBestPaths($request3);

if ($outcome3->hasPaths()) {
    echo "✓ Found " . count($outcome3->paths()) . " path(s)\n\n";
    
    foreach ($outcome3->paths() as $idx => $path) {
        $num = $idx + 1;
        echo "  Path #{$num} (" . count($path->legs()) . " hops):\n";
        
        foreach ($path->legs() as $legIdx => $leg) {
            $legNum = $legIdx + 1;
            echo "    Hop {$legNum}: {$leg->from()} → {$leg->to()}\n";
            echo "      Spend: {$leg->spent()->amount()} {$leg->spent()->currency()}\n";
            echo "      Receive: {$leg->received()->amount()} {$leg->received()->currency()}\n";
        }
        
        echo "    Final: {$path->totalSpent()->amount()} {$path->totalSpent()->currency()} → ";
        echo "{$path->totalReceived()->amount()} {$path->totalReceived()->currency()}\n\n";
    }
} else {
    echo "✗ No paths found from GBP to BTC\n\n";
}

// ============================================================================
// Part 5: Production Integration Pattern
// ============================================================================

echo str_repeat('=', 80) . "\n";
echo "Part 5: Production Integration Pattern\n";
echo str_repeat('=', 80) . "\n\n";

echo "Complete production-ready function for Bybit P2P integration:\n\n";

/**
 * Production-ready function to find P2P trading paths using Bybit data
 * 
 * @param BybitP2PClient $client Bybit API client
 * @param string $sourceCurrency Starting currency (e.g., 'USD')
 * @param string $targetToken Target token (e.g., 'BTC')
 * @param string $amount Amount to spend
 * @param int $scale Currency scale
 * @return void
 */
function findBybitP2PPath(
    BybitP2PClient $client,
    string $sourceCurrency,
    string $targetToken,
    string $amount,
    int $scale = 2
): void {
    try {
        echo "Finding path: {$amount} {$sourceCurrency} → {$targetToken}\n\n";
        
        // Step 1: Fetch relevant market data
        echo "1. Fetching market data...\n";
        
        $markets = [
            ['tokenId' => 'USDT', 'currencyId' => $sourceCurrency, 'side' => '0'],
            ['tokenId' => $targetToken, 'currencyId' => 'USDT', 'side' => '0'],
        ];
        
        $allOrders = [];
        foreach ($markets as $market) {
            $response = $client->getOnlineAds(
                $market['tokenId'],
                $market['currencyId'],
                $market['side']
            );
            
            if ($response['ret_code'] === 0) {
                $converter = new BybitOrderConverter();
                $orders = $converter->convertAdsToOrders($response['result']['items']);
                $allOrders = array_merge($allOrders, $orders);
            }
        }
        
        echo "   ✓ Fetched " . count($allOrders) . " orders\n\n";
        
        // Step 2: Build order book
        echo "2. Building order book...\n";
        $orderBook = new OrderBook($allOrders);
        echo "   ✓ Order book ready\n\n";
        
        // Step 3: Configure search
        echo "3. Configuring search parameters...\n";
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString($sourceCurrency, $amount, $scale))
            ->withToleranceBounds('0.00', '0.05')
            ->withHopLimits(1, 3)
            ->withSearchGuards(50000, 100000)
            ->build();
        echo "   ✓ Configuration ready\n\n";
        
        // Step 4: Find paths
        echo "4. Searching for optimal paths...\n";
        $service = new PathFinderService(new GraphBuilder());
        $request = new PathSearchRequest($orderBook, $config, $targetToken);
        $outcome = $service->findBestPaths($request);
        
        // Step 5: Process results
        if ($outcome->hasPaths()) {
            $bestPath = $outcome->paths()->first();
            echo "   ✓ Found optimal path!\n\n";
            echo "   Best Path:\n";
            echo "     Input: {$bestPath->totalSpent()->amount()} {$bestPath->totalSpent()->currency()}\n";
            echo "     Output: {$bestPath->totalReceived()->amount()} {$bestPath->totalReceived()->currency()}\n";
            echo "     Hops: " . count($bestPath->legs()) . "\n";
            echo "     Tolerance: {$bestPath->residualTolerancePercentage()}%\n";
        } else {
            echo "   ✗ No paths found\n";
            echo "   Suggestions:\n";
            echo "     - Check if liquidity is available\n";
            echo "     - Try a different amount\n";
            echo "     - Widen tolerance bounds\n";
        }
        
        // Step 6: Check guard limits
        $guardReport = $outcome->guardLimits();
        if ($guardReport->anyLimitReached()) {
            echo "\n   ⚠ Guard limits reached - consider increasing limits\n";
        }
        
    } catch (InvalidInput $e) {
        echo "✗ Invalid input: {$e->getMessage()}\n";
    } catch (\Exception $e) {
        echo "✗ Error: {$e->getMessage()}\n";
    }
}

// Test the production function
findBybitP2PPath($client, 'USD', 'BTC', '1500.00', 2);

// ============================================================================
// Summary and Best Practices
// ============================================================================

echo "\n";
echo "╔════════════════════════════════════════════════════════════════════════════╗\n";
echo "║                           Integration Summary                              ║\n";
echo "╚════════════════════════════════════════════════════════════════════════════╝\n";
echo "\n";

echo "Key Components:\n";
echo "  1. BybitP2PClient - Fetches advertisement data from Bybit API\n";
echo "  2. BybitOrderConverter - Maps Bybit ads to PathFinder Orders\n";
echo "  3. OrderBook - Aggregates all orders for path finding\n";
echo "  4. PathFinderService - Executes path search algorithm\n";
echo "  5. SearchOutcome - Contains results and metrics\n";
echo "\n";

echo "Integration Steps:\n";
echo "  1. Fetch ads from relevant markets (multiple API calls)\n";
echo "  2. Convert ads to Order objects (handle BUY/SELL correctly)\n";
echo "  3. Create OrderBook with all converted orders\n";
echo "  4. Configure search parameters (amount, tolerance, hops, guards)\n";
echo "  5. Execute path search via PathFinderService\n";
echo "  6. Process results and check guard limits\n";
echo "\n";

echo "Production Considerations:\n";
echo "  ✓ API Authentication - Use real API keys with proper signing\n";
echo "  ✓ Rate Limiting - Respect Bybit's rate limits (check API docs)\n";
echo "  ✓ Error Handling - Handle API errors, network issues, invalid data\n";
echo "  ✓ Caching - Cache ad data to reduce API calls (with TTL)\n";
echo "  ✓ Pagination - Fetch multiple pages if needed\n";
echo "  ✓ Filtering - Pre-filter ads by amount, payment methods, user rating\n";
echo "  ✓ Monitoring - Log guard limit breaches, API errors, slow searches\n";
echo "  ✓ Security - Never expose API secrets, validate all input\n";
echo "\n";

echo "Performance Optimization:\n";
echo "  ✓ Fetch only relevant markets (don't fetch everything)\n";
echo "  ✓ Pre-filter ads by liquidity before converting to orders\n";
echo "  ✓ Tune guard limits based on your latency requirements\n";
echo "  ✓ Use reasonable hop limits (2-3 is usually sufficient)\n";
echo "  ✓ Consider batch processing for multiple queries\n";
echo "\n";

echo "API Resources:\n";
echo "  • Documentation: https://bybit-exchange.github.io/docs/p2p/ad/online-ad-list\n";
echo "  • Authentication: https://bybit-exchange.github.io/docs/p2p/authentication\n";
echo "  • Rate Limits: Check Bybit API documentation\n";
echo "  • Support: Bybit API support channels\n";
echo "\n";

echo "╔════════════════════════════════════════════════════════════════════════════╗\n";
echo "║                          Example Complete                                  ║\n";
echo "╚════════════════════════════════════════════════════════════════════════════╝\n";
echo "\n";

} catch (\Throwable $e) {
    fwrite(STDERR, "\n✗ Example failed with unexpected error:\n");
    fwrite(STDERR, "  " . get_class($e) . ": " . $e->getMessage() . "\n");
    fwrite(STDERR, "  at " . $e->getFile() . ":" . $e->getLine() . "\n\n");
    exit(1);
}

exit(0);

