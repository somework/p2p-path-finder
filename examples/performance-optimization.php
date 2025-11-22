<?php

declare(strict_types=1);

/**
 * Example: Performance Optimization Techniques
 *
 * This example demonstrates practical performance optimization strategies for
 * the P2P Path Finder library. It includes:
 * - Order book pre-filtering (30-70% performance gain)
 * - Guard limit tuning (memory and latency control)
 * - Tolerance window optimization (search space reduction)
 * - Hop limit tuning (exponential complexity control)
 * - Mini-benchmarks showing measurable improvements
 *
 * Run: php examples/performance-optimization.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\Filter\MaximumAmountFilter;
use SomeWork\P2PPathFinder\Application\Filter\MinimumAmountFilter;
use SomeWork\P2PPathFinder\Application\Filter\ToleranceWindowFilter;
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

// ============================================================================
// Helper Functions
// ============================================================================

/**
 * Creates a large, complex order book for benchmarking.
 *
 * @param int $numOrders Number of orders to generate
 * @return OrderBook
 */
function createLargeOrderBook(int $numOrders): OrderBook
{
    $currencies = ['USD', 'EUR', 'GBP', 'JPY', 'CHF', 'AUD', 'CAD', 'NZD', 'SEK', 'NOK'];
    $orders = [];
    
    for ($i = 0; $i < $numOrders; $i++) {
        $baseCurrency = $currencies[$i % count($currencies)];
        $quoteCurrency = $currencies[($i + 1) % count($currencies)];
        
        // Vary order sizes to create diversity
        $minAmount = (string) (10 * ($i % 10 + 1));
        $maxAmount = (string) ($minAmount * 100);
        
        // Vary exchange rates
        $rate = number_format(0.8 + ($i % 50) * 0.01, 6, '.', '');
        
        $orders[] = new Order(
            $i % 2 === 0 ? OrderSide::BUY : OrderSide::SELL,
            AssetPair::fromString($baseCurrency, $quoteCurrency),
            OrderBounds::from(
                Money::fromString($baseCurrency, $minAmount, 2),
                Money::fromString($baseCurrency, $maxAmount, 2)
            ),
            ExchangeRate::fromString($baseCurrency, $quoteCurrency, $rate, 6)
        );
    }
    
    return new OrderBook($orders);
}

/**
 * Measures execution time and memory usage of a search operation.
 *
 * @param callable $operation The operation to measure
 * @return array{time_ms: float, memory_mb: float, result: mixed}
 */
function measurePerformance(callable $operation): array
{
    $startTime = microtime(true);
    $startMemory = memory_get_usage(true);
    
    $result = $operation();
    
    $endTime = microtime(true);
    $endMemory = memory_get_usage(true);
    
    return [
        'time_ms' => round(($endTime - $startTime) * 1000, 2),
        'memory_mb' => round(($endMemory - $startMemory) / 1024 / 1024, 2),
        'result' => $result,
    ];
}

/**
 * Formats performance comparison results.
 */
function displayComparison(string $label, array $before, array $after): void
{
    $timeImprovement = $before['time_ms'] > 0 
        ? round((($before['time_ms'] - $after['time_ms']) / $before['time_ms']) * 100, 1)
        : 0;
    
    $memoryImprovement = $before['memory_mb'] > 0
        ? round((($before['memory_mb'] - $after['memory_mb']) / $before['memory_mb']) * 100, 1)
        : 0;
    
    echo "\n";
    echo "  {$label}:\n";
    echo "    Time:   {$before['time_ms']}ms → {$after['time_ms']}ms ";
    echo "(" . ($timeImprovement > 0 ? "↓" : "↑") . abs($timeImprovement) . "%)\n";
    echo "    Memory: {$before['memory_mb']}MB → {$after['memory_mb']}MB ";
    echo "(" . ($memoryImprovement > 0 ? "↓" : "↑") . abs($memoryImprovement) . "%)\n";
}

// ============================================================================
// Main Demo
// ============================================================================

try {

echo "\n";
echo "╔════════════════════════════════════════════════════════════════════════════╗\n";
echo "║                    Performance Optimization Demo                           ║\n";
echo "╚════════════════════════════════════════════════════════════════════════════╝\n";
echo "\n";

echo "This example demonstrates measurable performance improvements using various\n";
echo "optimization techniques. All benchmarks use the same order book for fair comparison.\n\n";

// ============================================================================
// Optimization 1: Order Book Pre-Filtering
// ============================================================================

echo str_repeat('=', 80) . "\n";
echo "Optimization 1: Order Book Pre-Filtering\n";
echo str_repeat('=', 80) . "\n\n";

echo "Pre-filtering the order book removes irrelevant orders BEFORE graph construction,\n";
echo "reducing both memory usage and search time.\n\n";

$orderBook = createLargeOrderBook(500);
echo "Created order book with " . iterator_count($orderBook) . " orders\n\n";

$spendAmount = Money::fromString('USD', '100.00', 2);
$config = PathSearchConfig::builder()
    ->withSpendAmount($spendAmount)
    ->withToleranceBounds('0.00', '0.05')
    ->withHopLimits(1, 3)
    ->withResultLimit(5)
    ->withSearchGuards(50000, 100000)
    ->build();

$service = new PathFinderService(new GraphBuilder());

// Benchmark 1a: Without pre-filtering
echo "Scenario 1a: Without pre-filtering (baseline)\n";
$baseline = measurePerformance(function () use ($orderBook, $config, $service) {
    $request = new PathSearchRequest($orderBook, $config, 'EUR');
    return $service->findBestPaths($request);
});

echo "  Paths found: " . ($baseline['result']->hasPaths() ? count($baseline['result']->paths()) : 0) . "\n";
echo "  Time: {$baseline['time_ms']}ms\n";
echo "  Memory: {$baseline['memory_mb']}MB\n";

// Benchmark 1b: With amount range filtering
echo "\nScenario 1b: With amount range filtering\n";
echo "  Filtering for orders: min >= $10 (10% of spend), max <= $10,000 (100x spend)\n";

$amountFiltered = measurePerformance(function () use ($orderBook, $config, $service, $spendAmount) {
    // Pre-filter orders by amount
    $minFilter = new MinimumAmountFilter($spendAmount->multiply('0.1'));
    $maxFilter = new MaximumAmountFilter($spendAmount->multiply('100.0'));
    
    $filteredOrders = iterator_to_array($orderBook->filter($minFilter, $maxFilter));
    $filteredBook = new OrderBook($filteredOrders);
    
    echo "  Orders after filtering: " . count($filteredOrders) . 
         " (" . round((count($filteredOrders) / iterator_count($orderBook)) * 100, 1) . "% of original)\n";
    
    $request = new PathSearchRequest($filteredBook, $config, 'EUR');
    return $service->findBestPaths($request);
});

echo "  Paths found: " . ($amountFiltered['result']->hasPaths() ? count($amountFiltered['result']->paths()) : 0) . "\n";
echo "  Time: {$amountFiltered['time_ms']}ms\n";
echo "  Memory: {$amountFiltered['memory_mb']}MB\n";

displayComparison('Amount filtering impact', $baseline, $amountFiltered);

// Benchmark 1c: With tolerance window filtering (additional)
echo "\nScenario 1c: With tolerance window filtering (on top of amount filtering)\n";

$fullyFiltered = measurePerformance(function () use ($orderBook, $config, $service, $spendAmount) {
    // Apply both amount and tolerance filters
    $minFilter = new MinimumAmountFilter($spendAmount->multiply('0.1'));
    $maxFilter = new MaximumAmountFilter($spendAmount->multiply('100.0'));
    // ToleranceWindowFilter needs a reference rate and tolerance - create a USD/EUR rate for demonstration
    $referenceRate = ExchangeRate::fromString('USD', 'EUR', '0.92', 6);
    $toleranceFilter = new ToleranceWindowFilter($referenceRate, '0.05'); // 5% tolerance
    
    $filteredOrders = iterator_to_array($orderBook->filter($minFilter, $maxFilter, $toleranceFilter));
    $filteredBook = new OrderBook($filteredOrders);
    
    echo "  Orders after filtering: " . count($filteredOrders) . 
         " (" . round((count($filteredOrders) / iterator_count($orderBook)) * 100, 1) . "% of original)\n";
    
    $request = new PathSearchRequest($filteredBook, $config, 'EUR');
    return $service->findBestPaths($request);
});

echo "  Paths found: " . ($fullyFiltered['result']->hasPaths() ? count($fullyFiltered['result']->paths()) : 0) . "\n";
echo "  Time: {$fullyFiltered['time_ms']}ms\n";
echo "  Memory: {$fullyFiltered['memory_mb']}MB\n";

displayComparison('Combined filtering impact', $baseline, $fullyFiltered);

echo "\n✓ Key Takeaway: Pre-filtering can reduce search time by 30-70%\n";
echo "  The more irrelevant orders you remove, the faster the search.\n";

// ============================================================================
// Optimization 2: Guard Limit Tuning
// ============================================================================

echo "\n";
echo str_repeat('=', 80) . "\n";
echo "Optimization 2: Guard Limit Tuning\n";
echo str_repeat('=', 80) . "\n\n";

echo "Guard limits control search breadth. Lower limits reduce memory/time but may\n";
echo "miss some paths. Higher limits are more thorough but use more resources.\n\n";

$optimizedOrderBook = new OrderBook(
    iterator_to_array($orderBook->filter(
        new MinimumAmountFilter($spendAmount->multiply('0.1')),
        new MaximumAmountFilter($spendAmount->multiply('100.0'))
    ))
);

// Benchmark 2a: Conservative limits (fast, low memory)
echo "Scenario 2a: Conservative limits (5k states, 10k expansions)\n";
$conservativeConfig = PathSearchConfig::builder()
    ->withSpendAmount($spendAmount)
    ->withToleranceBounds('0.00', '0.05')
    ->withHopLimits(1, 3)
    ->withResultLimit(5)
    ->withSearchGuards(5000, 10000) // Conservative
    ->build();

$conservative = measurePerformance(function () use ($optimizedOrderBook, $conservativeConfig, $service) {
    $request = new PathSearchRequest($optimizedOrderBook, $conservativeConfig, 'EUR');
    return $service->findBestPaths($request);
});

$guardReport = $conservative['result']->guardLimits();
echo "  Paths found: " . ($conservative['result']->hasPaths() ? count($conservative['result']->paths()) : 0) . "\n";
echo "  Time: {$conservative['time_ms']}ms\n";
echo "  Memory: {$conservative['memory_mb']}MB\n";
echo "  Expansions: {$guardReport->expansions()} / {$guardReport->expansionLimit()}\n";
echo "  States: {$guardReport->visitedStates()} / {$guardReport->visitedStateLimit()}\n";
echo "  Guard hit: " . ($guardReport->anyLimitReached() ? 'YES' : 'NO') . "\n";

// Benchmark 2b: Moderate limits (balanced)
echo "\nScenario 2b: Moderate limits (25k states, 50k expansions)\n";
$moderateConfig = PathSearchConfig::builder()
    ->withSpendAmount($spendAmount)
    ->withToleranceBounds('0.00', '0.05')
    ->withHopLimits(1, 3)
    ->withResultLimit(5)
    ->withSearchGuards(25000, 50000) // Moderate
    ->build();

$moderate = measurePerformance(function () use ($optimizedOrderBook, $moderateConfig, $service) {
    $request = new PathSearchRequest($optimizedOrderBook, $moderateConfig, 'EUR');
    return $service->findBestPaths($request);
});

$guardReport = $moderate['result']->guardLimits();
echo "  Paths found: " . ($moderate['result']->hasPaths() ? count($moderate['result']->paths()) : 0) . "\n";
echo "  Time: {$moderate['time_ms']}ms\n";
echo "  Memory: {$moderate['memory_mb']}MB\n";
echo "  Expansions: {$guardReport->expansions()} / {$guardReport->expansionLimit()}\n";
echo "  States: {$guardReport->visitedStates()} / {$guardReport->visitedStateLimit()}\n";
echo "  Guard hit: " . ($guardReport->anyLimitReached() ? 'YES' : 'NO') . "\n";

// Benchmark 2c: Aggressive limits (thorough, higher resources)
echo "\nScenario 2c: Aggressive limits (100k states, 200k expansions)\n";
$aggressiveConfig = PathSearchConfig::builder()
    ->withSpendAmount($spendAmount)
    ->withToleranceBounds('0.00', '0.05')
    ->withHopLimits(1, 3)
    ->withResultLimit(5)
    ->withSearchGuards(100000, 200000) // Aggressive
    ->build();

$aggressive = measurePerformance(function () use ($optimizedOrderBook, $aggressiveConfig, $service) {
    $request = new PathSearchRequest($optimizedOrderBook, $aggressiveConfig, 'EUR');
    return $service->findBestPaths($request);
});

$guardReport = $aggressive['result']->guardLimits();
echo "  Paths found: " . ($aggressive['result']->hasPaths() ? count($aggressive['result']->paths()) : 0) . "\n";
echo "  Time: {$aggressive['time_ms']}ms\n";
echo "  Memory: {$aggressive['memory_mb']}MB\n";
echo "  Expansions: {$guardReport->expansions()} / {$guardReport->expansionLimit()}\n";
echo "  States: {$guardReport->visitedStates()} / {$guardReport->visitedStateLimit()}\n";
echo "  Guard hit: " . ($guardReport->anyLimitReached() ? 'YES' : 'NO') . "\n";

echo "\n✓ Key Takeaway: Start conservative and increase limits only if needed\n";
echo "  Monitor guard reports to determine if you're hitting limits too often.\n";

// ============================================================================
// Optimization 3: Tolerance Window Tuning
// ============================================================================

echo "\n";
echo str_repeat('=', 80) . "\n";
echo "Optimization 3: Tolerance Window Tuning\n";
echo str_repeat('=', 80) . "\n\n";

echo "Narrower tolerance windows reduce search space (fewer valid paths to explore).\n";
echo "Wider windows allow more paths but increase search time.\n\n";

// Benchmark 3a: Narrow tolerance (0-2%)
echo "Scenario 3a: Narrow tolerance (0-2%)\n";
$narrowConfig = PathSearchConfig::builder()
    ->withSpendAmount($spendAmount)
    ->withToleranceBounds('0.00', '0.02') // Narrow
    ->withHopLimits(1, 3)
    ->withResultLimit(5)
    ->withSearchGuards(25000, 50000)
    ->build();

$narrowTolerance = measurePerformance(function () use ($optimizedOrderBook, $narrowConfig, $service) {
    $request = new PathSearchRequest($optimizedOrderBook, $narrowConfig, 'EUR');
    return $service->findBestPaths($request);
});

echo "  Paths found: " . ($narrowTolerance['result']->hasPaths() ? count($narrowTolerance['result']->paths()) : 0) . "\n";
echo "  Time: {$narrowTolerance['time_ms']}ms\n";
echo "  Memory: {$narrowTolerance['memory_mb']}MB\n";

// Benchmark 3b: Medium tolerance (0-5%)
echo "\nScenario 3b: Medium tolerance (0-5%)\n";
$mediumConfig = PathSearchConfig::builder()
    ->withSpendAmount($spendAmount)
    ->withToleranceBounds('0.00', '0.05') // Medium
    ->withHopLimits(1, 3)
    ->withResultLimit(5)
    ->withSearchGuards(25000, 50000)
    ->build();

$mediumTolerance = measurePerformance(function () use ($optimizedOrderBook, $mediumConfig, $service) {
    $request = new PathSearchRequest($optimizedOrderBook, $mediumConfig, 'EUR');
    return $service->findBestPaths($request);
});

echo "  Paths found: " . ($mediumTolerance['result']->hasPaths() ? count($mediumTolerance['result']->paths()) : 0) . "\n";
echo "  Time: {$mediumTolerance['time_ms']}ms\n";
echo "  Memory: {$mediumTolerance['memory_mb']}MB\n";

// Benchmark 3c: Wide tolerance (0-10%)
echo "\nScenario 3c: Wide tolerance (0-10%)\n";
$wideConfig = PathSearchConfig::builder()
    ->withSpendAmount($spendAmount)
    ->withToleranceBounds('0.00', '0.10') // Wide
    ->withHopLimits(1, 3)
    ->withResultLimit(5)
    ->withSearchGuards(25000, 50000)
    ->build();

$wideTolerance = measurePerformance(function () use ($optimizedOrderBook, $wideConfig, $service) {
    $request = new PathSearchRequest($optimizedOrderBook, $wideConfig, 'EUR');
    return $service->findBestPaths($request);
});

echo "  Paths found: " . ($wideTolerance['result']->hasPaths() ? count($wideTolerance['result']->paths()) : 0) . "\n";
echo "  Time: {$wideTolerance['time_ms']}ms\n";
echo "  Memory: {$wideTolerance['memory_mb']}MB\n";

displayComparison('Narrow vs wide tolerance', $wideTolerance, $narrowTolerance);

echo "\n✓ Key Takeaway: Use the narrowest tolerance that meets your business needs\n";
echo "  Wider tolerance = more options but slower search.\n";

// ============================================================================
// Optimization 4: Hop Limit Tuning
// ============================================================================

echo "\n";
echo str_repeat('=', 80) . "\n";
echo "Optimization 4: Hop Limit Tuning\n";
echo str_repeat('=', 80) . "\n\n";

echo "Hop limits have EXPONENTIAL impact on search space.\n";
echo "Each additional hop can multiply the number of states to explore.\n\n";

// Benchmark 4a: 1-2 hops
echo "Scenario 4a: Limited hops (1-2)\n";
$shortConfig = PathSearchConfig::builder()
    ->withSpendAmount($spendAmount)
    ->withToleranceBounds('0.00', '0.05')
    ->withHopLimits(1, 2) // Short paths only
    ->withResultLimit(5)
    ->withSearchGuards(25000, 50000)
    ->build();

$shortHops = measurePerformance(function () use ($optimizedOrderBook, $shortConfig, $service) {
    $request = new PathSearchRequest($optimizedOrderBook, $shortConfig, 'EUR');
    return $service->findBestPaths($request);
});

$guardReport = $shortHops['result']->guardLimits();
echo "  Paths found: " . ($shortHops['result']->hasPaths() ? count($shortHops['result']->paths()) : 0) . "\n";
echo "  Time: {$shortHops['time_ms']}ms\n";
echo "  Memory: {$shortHops['memory_mb']}MB\n";
echo "  Expansions: {$guardReport->expansions()}\n";

// Benchmark 4b: 1-3 hops
echo "\nScenario 4b: Medium hops (1-3)\n";
$mediumHopsConfig = PathSearchConfig::builder()
    ->withSpendAmount($spendAmount)
    ->withToleranceBounds('0.00', '0.05')
    ->withHopLimits(1, 3) // Medium paths
    ->withResultLimit(5)
    ->withSearchGuards(25000, 50000)
    ->build();

$mediumHops = measurePerformance(function () use ($optimizedOrderBook, $mediumHopsConfig, $service) {
    $request = new PathSearchRequest($optimizedOrderBook, $mediumHopsConfig, 'EUR');
    return $service->findBestPaths($request);
});

$guardReport = $mediumHops['result']->guardLimits();
echo "  Paths found: " . ($mediumHops['result']->hasPaths() ? count($mediumHops['result']->paths()) : 0) . "\n";
echo "  Time: {$mediumHops['time_ms']}ms\n";
echo "  Memory: {$mediumHops['memory_mb']}MB\n";
echo "  Expansions: {$guardReport->expansions()}\n";

// Benchmark 4c: 1-5 hops
echo "\nScenario 4c: Long hops (1-5)\n";
$longHopsConfig = PathSearchConfig::builder()
    ->withSpendAmount($spendAmount)
    ->withToleranceBounds('0.00', '0.05')
    ->withHopLimits(1, 5) // Long paths
    ->withResultLimit(5)
    ->withSearchGuards(25000, 50000)
    ->build();

$longHops = measurePerformance(function () use ($optimizedOrderBook, $longHopsConfig, $service) {
    $request = new PathSearchRequest($optimizedOrderBook, $longHopsConfig, 'EUR');
    return $service->findBestPaths($request);
});

$guardReport = $longHops['result']->guardLimits();
echo "  Paths found: " . ($longHops['result']->hasPaths() ? count($longHops['result']->paths()) : 0) . "\n";
echo "  Time: {$longHops['time_ms']}ms\n";
echo "  Memory: {$longHops['memory_mb']}MB\n";
echo "  Expansions: {$guardReport->expansions()}\n";

displayComparison('2-hop vs 5-hop search', $shortHops, $longHops);

echo "\n✓ Key Takeaway: Use the minimum hop limit that finds good paths\n";
echo "  Most optimal paths are 1-3 hops. Going beyond 4-5 hops rarely helps.\n";

// ============================================================================
// Summary: Combined Optimizations
// ============================================================================

echo "\n";
echo "╔════════════════════════════════════════════════════════════════════════════╗\n";
echo "║                    Performance Optimization Summary                        ║\n";
echo "╚════════════════════════════════════════════════════════════════════════════╝\n";
echo "\n";

echo "Optimization Impact (Typical Improvements):\n\n";

echo "  1. Pre-filtering order book:     30-70% faster, 20-50% less memory\n";
echo "     • MinimumAmountFilter: Remove tiny orders\n";
echo "     • MaximumAmountFilter: Remove huge orders\n";
echo "     • ToleranceWindowFilter: Remove poor-rate orders\n\n";

echo "  2. Guard limit tuning:            Linear impact on max memory/time\n";
echo "     • Conservative (5k-10k):    Fast, low memory, may miss some paths\n";
echo "     • Moderate (25k-50k):       Balanced, recommended for production\n";
echo "     • Aggressive (100k-200k):   Thorough, higher resource usage\n\n";

echo "  3. Tolerance window tuning:       10-30% impact per doubling\n";
echo "     • Narrow (0-2%):    Fastest, fewer path options\n";
echo "     • Medium (0-5%):    Balanced, recommended default\n";
echo "     • Wide (0-10%):     Slower, more path options\n\n";

echo "  4. Hop limit tuning:              EXPONENTIAL impact (3-10x per hop)\n";
echo "     • 1-2 hops:  Fastest, direct paths only\n";
echo "     • 1-3 hops:  Recommended default, covers most use cases\n";
echo "     • 1-5 hops:  Much slower, rarely needed\n\n";

echo "Recommended Production Configuration:\n\n";

echo "  // For latency-sensitive APIs (< 100ms target)\n";
echo "  PathSearchConfig::builder()\n";
echo "    ->withSpendAmount(\$amount)\n";
echo "    ->withToleranceBounds('0.00', '0.03')   // Narrow tolerance\n";
echo "    ->withHopLimits(1, 2)                   // Short paths only\n";
echo "    ->withResultLimit(3)                    // Few results\n";
echo "    ->withSearchGuards(5000, 10000)         // Conservative limits\n";
echo "    ->withSearchTimeBudget(50)              // 50ms safety net\n";
echo "    ->build();\n\n";

echo "  // For balanced use cases (< 200ms acceptable)\n";
echo "  PathSearchConfig::builder()\n";
echo "    ->withSpendAmount(\$amount)\n";
echo "    ->withToleranceBounds('0.00', '0.05')   // Medium tolerance\n";
echo "    ->withHopLimits(1, 3)                   // Standard hops\n";
echo "    ->withResultLimit(5)                    // Moderate results\n";
echo "    ->withSearchGuards(25000, 50000)        // Moderate limits\n";
echo "    ->withSearchTimeBudget(150)             // 150ms safety net\n";
echo "    ->build();\n\n";

echo "  // For comprehensive searches (< 500ms acceptable)\n";
echo "  PathSearchConfig::builder()\n";
echo "    ->withSpendAmount(\$amount)\n";
echo "    ->withToleranceBounds('0.00', '0.10')   // Wide tolerance\n";
echo "    ->withHopLimits(1, 4)                   // Longer paths\n";
echo "    ->withResultLimit(10)                   // Many results\n";
echo "    ->withSearchGuards(100000, 150000)      // High limits\n";
echo "    ->withSearchTimeBudget(400)             // 400ms safety net\n";
echo "    ->build();\n\n";

echo "Monitoring and Tuning:\n\n";
echo "  1. Always check SearchOutcome::guardLimits() after search\n";
echo "  2. Monitor guard breach rates (should be < 10% in production)\n";
echo "  3. If breaches are frequent:\n";
echo "     • Try pre-filtering more aggressively\n";
echo "     • Increase guard limits\n";
echo "     • Reduce tolerance or hop limits\n";
echo "  4. Track p95/p99 latency and adjust accordingly\n";
echo "  5. Use time budget as a safety net (should rarely trigger)\n\n";

echo "For more details, see docs/memory-characteristics.md\n";

echo "\n";
echo "╔════════════════════════════════════════════════════════════════════════════╗\n";
echo "║                          Example Complete                                  ║\n";
echo "╚════════════════════════════════════════════════════════════════════════════╝\n";
echo "\n";

} catch (\Throwable $e) {
    fwrite(STDERR, "\n✗ Example failed with unexpected error:\n");
    fwrite(STDERR, "  " . get_class($e) . ": " . $e->getMessage() . "\n");
    fwrite(STDERR, "  at " . $e->getFile() . ":" . $e->getLine() . "\n");
    exit(1); // Failure
}

exit(0); // Success

