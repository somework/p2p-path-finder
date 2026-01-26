# Getting Started with P2P Path Finder

**Version**: 2.0  
**Last Updated**: 2026-01-25

This guide will help you get started with the P2P Path Finder library in just a few minutes.

---

## Table of Contents

- [Installation](#installation)
- [Your First Path Search](#your-first-path-search)
- [Understanding the Results](#understanding-the-results)
- [Working with Orders](#working-with-orders)
- [Customizing the Search](#customizing-the-search)
- [ExecutionPlanService (Recommended)](#executionplanservice-recommended)
- [Migration from PathSearchService](#migration-from-pathsearchservice)
- [Next Steps](#next-steps)

---

## Installation

### Requirements

- **PHP 8.2 or newer**
- **Composer 2.x**

### Install via Composer

```bash
composer require somework/p2p-path-finder
```

### Verify Installation

```bash
php -r "require 'vendor/autoload.php'; echo 'Installation successful!\n';"
```

---

## Your First Path Search

Let's find the best path to convert **USD to BTC** through a simple order book.

### Step 1: Create an Order Book

```php
<?php

require 'vendor/autoload.php';

use SomeWork\P2PPathFinder\Domain\Money\AssetPair;
use SomeWork\P2PPathFinder\Domain\Money\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderBook;
use SomeWork\P2PPathFinder\Domain\Order\OrderBounds;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;

// Create a simple order book with one order
$orders = [
    Order::buy(
        AssetPair::fromString('BTC/USD'),
        OrderBounds::fromStrings('0.01', '1.0', 8),  // Min: 0.01 BTC, Max: 1.0 BTC
        ExchangeRate::fromString('BTC', 'USD', '30000', 2),  // 1 BTC = 30,000 USD
        OrderSide::BUY
    ),
];

$orderBook = new OrderBook($orders);
```

**What's happening here?**

- We create an **order** that allows buying BTC with USD
- The order accepts **0.01 to 1.0 BTC** (with 8 decimal places)
- The **exchange rate** is 1 BTC = 30,000 USD (with 2 decimal places)
- `OrderSide::BUY` means we're buying the base currency (BTC) with the quote currency (USD)

### Step 2: Configure the Search

```php
use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Domain\Money\Money;

// Spend $1,000 USD
$spendAmount = Money::fromString('USD', '1000.00', 2);

// Configure the search
$config = PathSearchConfig::builder()
    ->withSpendAmount($spendAmount)
    ->withToleranceBounds('0.0', '0.10')  // 0-10% tolerance
    ->withHopLimits(1, 3)  // Allow 1-3 hops
    ->build();
```

**What's happening here?**

- We specify we want to **spend $1,000 USD**
- **Tolerance bounds** allow paths that are slightly suboptimal (0-10% worse than the best)
- **Hop limits** control path length (minimum 1 hop, maximum 3 hops)

### Step 3: Run the Search

```php
use SomeWork\P2PPathFinder\Application\PathSearch\Api\Request\PathSearchRequest;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\PathSearchService;

// Create the path search service
$graphBuilder = new GraphBuilder();
$pathSearchService = new PathSearchService($graphBuilder);

// Create the search request
$request = new PathSearchRequest(
    $orderBook,
    $config,
    'BTC'  // Target currency
);

// Find the best paths
$outcome = $pathSearchService->findBestPaths($request);
```

**What's happening here?**

- We create a **`PathSearchService`** with a `GraphBuilder`
- We create a **`PathSearchRequest`** specifying the order book, configuration, and target currency (BTC)
- We call **`findBestPaths()`** to search for optimal paths

### Step 4: Display the Results

```php
// Check if any paths were found
if ($outcome->paths()->isEmpty()) {
    echo "No paths found.\n";
    exit;
}

// Get the best path
/** @var SomeWork\P2PPathFinder\Application\PathSearch\Result\Path $bestPath */
$bestPath = $outcome->paths()->first();

// Build a human-friendly route using hopsAsArray()
// Use hopsAsArray() when you need a plain list (e.g., for serialization or string building).
// Use hops() when you want lazy, collection-style iteration without materializing an array.
$route = [];
foreach ($bestPath->hopsAsArray() as $index => $hop) {
    if (0 === $index) {
        $route[] = $hop->from();
    }

    $route[] = $hop->to();
}
$routeString = implode('->', $route);

// Display totals, tolerance headroom, and fees derived from hops
echo "Best path found!\n";
echo "  Route: {$routeString}\n";
echo "  Spend: {$bestPath->totalSpent()->amount()} {$bestPath->totalSpent()->currency()}\n";
echo "  Receive: {$bestPath->totalReceived()->amount()} {$bestPath->totalReceived()->currency()}\n";
echo "  Residual tolerance: {$bestPath->residualTolerance()->percentage()}%\n";
echo "  Fees: " . json_encode($bestPath->feeBreakdownAsArray()) . "\n";
echo "  Hops: {$bestPath->hops()->count()}\n";

// Inspect hop-level Orders and amounts
foreach ($bestPath->hops() as $hop) {
    echo "  Hop: {$hop->from()} -> {$hop->to()}\n";
    echo "    Order pair: {$hop->order()->assetPair()->base()}/{$hop->order()->assetPair()->quote()}\n";
    echo "    Spent: {$hop->spent()->amount()} {$hop->spent()->currency()}\n";
    echo "    Received: {$hop->received()->amount()} {$hop->received()->currency()}\n";
}
```

**Expected output**:

```
Best path found!
  Route: USD->BTC
  Spend: 1000.00 USD
  Receive: 0.03333333 BTC
  Residual tolerance: 5.00%
  Fees: []
  Hops: 1
  Hop: USD -> BTC
    Order pair: BTC/USD
    Spent: 1000.00 USD
    Received: 0.03333333 BTC
```

---

## Understanding the Results

### Path Structure

A **`Path`** contains:

- **`hops()`**: Ordered collection of hop objects (`PathHopCollection`)
- **`hopsAsArray()`**: List-friendly representation of hops for serialization
- **`totalSpent()`**: Derived from the first hop's `spent()` amount (Money object)
- **`totalReceived()`**: Derived from the last hop's `received()` amount (Money object)
- **`residualTolerance()`**: Remaining tolerance after applying the path (DecimalTolerance)
- **`feeBreakdown()`**: Aggregated fees across all hops (MoneyMap)

### Path Hops

Each **hop** represents one conversion step and exposes the associated order:

```php
foreach ($bestPath->hops() as $hop) {
    echo "Hop: {$hop->from()} -> {$hop->to()}\n";
    echo "  Spent: {$hop->spent()->amount()} {$hop->spent()->currency()}\n";
    echo "  Received: {$hop->received()->amount()} {$hop->received()->currency()}\n";

    // Fees for this hop
    foreach ($hop->fees() as $currency => $fee) {
        echo "    Fee: {$fee->amount()} {$currency}\n";
    }

    // Access the originating order for reconciliation or ID lookup
    $order = $hop->order();
    echo "  Order asset pair: {$order->assetPair()->base()} / {$order->assetPair()->quote()}\n";
    // If your Order implementation carries custom IDs, read them here or map via spl_object_id($order)
}
```

### Search Metadata

The **`SearchOutcome`** also provides metadata about the search:

```php
$guardReport = $outcome->guardLimits();

echo "Search statistics:\n";
echo "  Expansions: {$guardReport->expansions()}\n";
echo "  Visited states: {$guardReport->visitedStates()}\n";
echo "  Time: {$guardReport->elapsedMilliseconds()}ms\n";
echo "  Any limit reached: " . ($guardReport->anyLimitReached() ? 'yes' : 'no') . "\n";
```

This tells you:
- How many graph nodes were expanded
- How many unique states were visited
- How long the search took
- Whether any guard limits were hit

---

## Working with Orders

### Creating Orders

There are two ways to create orders: **buy** and **sell**.

**Buy Order** (buy base currency with quote currency):

```php
$buyOrder = Order::buy(
    AssetPair::fromString('BTC/USD'),  // Buy BTC with USD
    OrderBounds::fromStrings('0.01', '1.0', 8),  // 0.01-1.0 BTC
    ExchangeRate::fromString('BTC', 'USD', '30000', 2),  // 1 BTC = 30,000 USD
    OrderSide::BUY
);
```

**Sell Order** (sell base currency for quote currency):

```php
$sellOrder = Order::sell(
    AssetPair::fromString('BTC/USD'),  // Sell BTC for USD
    OrderBounds::fromStrings('0.01', '1.0', 8),  // 0.01-1.0 BTC
    ExchangeRate::fromString('BTC', 'USD', '29500', 2),  // 1 BTC = 29,500 USD
    OrderSide::SELL
);
```

### Multi-Hop Paths

To enable multi-hop paths (e.g., USD → EUR → BTC), add orders for intermediate pairs:

```php
$orders = [
    // USD -> EUR
    Order::buy(
        AssetPair::fromString('EUR/USD'),
        OrderBounds::fromStrings('100', '10000', 2),
        ExchangeRate::fromString('EUR', 'USD', '1.10', 4),
        OrderSide::BUY
    ),
    // EUR -> BTC
    Order::buy(
        AssetPair::fromString('BTC/EUR'),
        OrderBounds::fromStrings('0.01', '1.0', 8),
        ExchangeRate::fromString('BTC', 'EUR', '27000', 2),
        OrderSide::BUY
    ),
    // Direct USD -> BTC (for comparison)
    Order::buy(
        AssetPair::fromString('BTC/USD'),
        OrderBounds::fromStrings('0.01', '1.0', 8),
        ExchangeRate::fromString('BTC', 'USD', '30000', 2),
        OrderSide::BUY
    ),
];

$orderBook = new OrderBook($orders);
```

The path finder will automatically discover that `USD->EUR->BTC` might be better than the direct `USD->BTC` path.

### Adding Fees

Orders can have fees that reduce the received amount:

```php
use SomeWork\P2PPathFinder\Domain\Order\Fee\FeeBreakdown;
use SomeWork\P2PPathFinder\Domain\Order\Fee\FeePolicy;

// Create a fee policy (1% fee on quote amount)
$feePolicy = new class implements FeePolicy {
    public function calculate(
        OrderSide $side,
        Money $baseAmount,
        Money $quoteAmount
    ): FeeBreakdown {
        // Calculate 1% fee on quote amount
        $fee = $quoteAmount->multiply('0.01', $quoteAmount->scale());
        return FeeBreakdown::forQuote($fee);
    }
    
    public function fingerprint(): string {
        return 'quote-percentage:0.01:2';
    }
};

// Add fee policy to order
$orderWithFees = Order::buy(
    AssetPair::fromString('BTC/USD'),
    OrderBounds::fromStrings('0.01', '1.0', 8),
    ExchangeRate::fromString('BTC', 'USD', '30000', 2),
    OrderSide::BUY,
    $feePolicy
);
```

---

## Customizing the Search

### Single Plan vs Multiple Paths

**Important**: `ExecutionPlanService::findBestPlans()` returns at most **ONE** optimal execution plan, not multiple ranked paths. This is different from the legacy `PathSearchService` which could return multiple paths via `topK` configuration.

```php
// ExecutionPlanService (Recommended) - returns 0 or 1 plans
$planService = new ExecutionPlanService(new GraphBuilder());
$outcome = $planService->findBestPlans($request);

$plan = $outcome->bestPath();  // Single optimal plan or null
if (null !== $plan) {
    echo "Spend: {$plan->totalSpent()->amount()} {$plan->totalSpent()->currency()}\n";
    echo "Receive: {$plan->totalReceived()->amount()} {$plan->totalReceived()->currency()}\n";
}

// Note: $outcome->paths()->count() will be 0 or 1
```

**Why single plan?**: The execution plan algorithm optimizes for a single global optimum that may include split/merge execution. The concept of "alternative paths" doesn't map cleanly to split/merge topology.

**Getting alternatives**: If you need alternative routes, run separate searches with different constraints:

```php
// Alternative 1: Different tolerance bounds
$strictConfig = PathSearchConfig::builder()
    ->withSpendAmount($spendAmount)
    ->withToleranceBounds('0.0', '0.05')  // Tighter tolerance
    ->build();

// Alternative 2: Different spend amount
$smallerConfig = PathSearchConfig::builder()
    ->withSpendAmount(Money::fromString('USD', '500.00', 2))  // Smaller amount
    ->build();

// Alternative 3: Filtered order book
$filteredBook = new OrderBook($filteredOrders);
$filteredRequest = new PathSearchRequest($filteredBook, $config, 'BTC');
```

### Multiple Paths (Legacy PathSearchService Only)

> **Note**: This section applies to the deprecated `PathSearchService`. For new code, use `ExecutionPlanService` which returns a single optimal plan.

With `PathSearchService`, you can request multiple paths:

```php
$config = PathSearchConfig::builder()
    ->withSpendAmount($spendAmount)
    ->withToleranceBounds('0.0', '0.10')
    ->withHopLimits(1, 3)
    ->withTopK(10)  // Request top 10 paths (PathSearchService only)
    ->build();

// Iterate through all paths (PathSearchService only)
foreach ($outcome->paths() as $path) {
    $route = [];
    foreach ($path->hopsAsArray() as $index => $hop) {
        if (0 === $index) {
            $route[] = $hop->from();
        }

        $route[] = $hop->to();
    }

    echo "Path: " . implode(' -> ', $route) . "\n";
    echo "  Spend: {$path->totalSpent()->amount()} {$path->totalSpent()->currency()}\n";
    echo "  Receive: {$path->totalReceived()->amount()} {$path->totalReceived()->currency()}\n";
}
```

### Adjusting Tolerance

**Tolerance** controls how suboptimal paths can be relative to the best path:

```php
// Strict: Only return the absolute best path
$strictConfig = PathSearchConfig::builder()
    ->withSpendAmount($spendAmount)
    ->withToleranceBounds('0.0', '0.0')  // 0% tolerance
    ->withHopLimits(1, 3)
    ->build();

// Relaxed: Allow paths up to 20% worse
$relaxedConfig = PathSearchConfig::builder()
    ->withSpendAmount($spendAmount)
    ->withToleranceBounds('0.0', '0.20')  // 0-20% tolerance
    ->withHopLimits(1, 3)
    ->build();
```

**Trade-offs**:
- **Lower tolerance** → Fewer, better paths
- **Higher tolerance** → More paths, but some may be suboptimal

### Controlling Path Length

**Hop limits** control the minimum and maximum path length:

```php
// Direct paths only (1 hop)
$directConfig = PathSearchConfig::builder()
    ->withSpendAmount($spendAmount)
    ->withToleranceBounds('0.0', '0.10')
    ->withHopLimits(1, 1)  // Only 1 hop
    ->build();

// Allow longer paths (up to 5 hops)
$longPathConfig = PathSearchConfig::builder()
    ->withSpendAmount($spendAmount)
    ->withToleranceBounds('0.0', '0.10')
    ->withHopLimits(1, 5)  // 1-5 hops
    ->build();
```

**Trade-offs**:
- **Fewer hops** → Faster, simpler paths
- **More hops** → May find better rates through bridges

### Setting Guard Rails

**Guard rails** limit the search to prevent excessive computation:

```php
use SomeWork\P2PPathFinder\Application\PathSearch\Config\SearchGuardConfig;

$guardConfig = SearchGuardConfig::strict()
    ->withMaxExpansions(5000)    // Max graph nodes to explore
    ->withMaxVisitedStates(10000) // Max unique states to track
    ->withTimeBudget(3000);       // Max time in milliseconds

$config = PathSearchConfig::builder()
    ->withSpendAmount($spendAmount)
    ->withToleranceBounds('0.0', '0.10')
    ->withHopLimits(1, 3)
    ->withSearchGuardConfig($guardConfig)
    ->build();
```

**When guard limits are hit**:
- The search terminates early
- Partial results are returned
- `guardLimits()->anyLimitReached()` returns `true`

### Filtering Orders

You can pre-filter the order book before searching:

```php
use SomeWork\P2PPathFinder\Application\Order\Filter\MaximumAmountFilter;
use SomeWork\P2PPathFinder\Application\Order\Filter\MinimumAmountFilter;
use SomeWork\P2PPathFinder\Application\Order\Filter\ToleranceWindowFilter;

// Filter by amount range
$minFilter = new MinimumAmountFilter(
    Money::fromString('BTC', '0.01', 8)
);
$maxFilter = new MaximumAmountFilter(
    Money::fromString('BTC', '10.0', 8)
);

// Filter by tolerance (rate window)
$referenceRate = ExchangeRate::fromString('BTC', 'USD', '30000', 2);
$toleranceFilter = new ToleranceWindowFilter($referenceRate, '0.10');

// Apply filters
$filteredOrders = $orderBook->filter($minFilter, $maxFilter, $toleranceFilter);
$filteredBook = new OrderBook(iterator_to_array($filteredOrders));

// Search on filtered book
$request = new PathSearchRequest($filteredBook, $config, 'BTC');
$outcome = $pathSearchService->findBestPaths($request);
```

---

## Complete Example

Here's a complete working example you can run:

```php
<?php

require 'vendor/autoload.php';

use SomeWork\P2PPathFinder\Application\PathSearch\Api\Request\PathSearchRequest;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\PathSearchService;
use SomeWork\P2PPathFinder\Domain\Money\AssetPair;
use SomeWork\P2PPathFinder\Domain\Money\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderBook;
use SomeWork\P2PPathFinder\Domain\Order\OrderBounds;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;

// 1. Create order book
$orders = [
    // Direct USD -> BTC
    Order::buy(
        AssetPair::fromString('BTC/USD'),
        OrderBounds::fromStrings('0.01', '1.0', 8),
        ExchangeRate::fromString('BTC', 'USD', '30000', 2),
        OrderSide::BUY
    ),
    // Bridge: USD -> EUR
    Order::buy(
        AssetPair::fromString('EUR/USD'),
        OrderBounds::fromStrings('100', '10000', 2),
        ExchangeRate::fromString('EUR', 'USD', '1.10', 4),
        OrderSide::BUY
    ),
    // Bridge: EUR -> BTC
    Order::buy(
        AssetPair::fromString('BTC/EUR'),
        OrderBounds::fromStrings('0.01', '1.0', 8),
        ExchangeRate::fromString('BTC', 'EUR', '27000', 2),
        OrderSide::BUY
    ),
];

$orderBook = new OrderBook($orders);

// 2. Configure search
$spendAmount = Money::fromString('USD', '1000.00', 2);

$config = PathSearchConfig::builder()
    ->withSpendAmount($spendAmount)
    ->withToleranceBounds('0.0', '0.10')
    ->withHopLimits(1, 3)
    ->withTopK(5)
    ->build();

// 3. Run search
$graphBuilder = new GraphBuilder();
$pathSearchService = new PathSearchService($graphBuilder);

$request = new PathSearchRequest($orderBook, $config, 'BTC');
$outcome = $pathSearchService->findBestPaths($request);

// 4. Display results
echo "=== Path Search Results ===\n\n";

if ($outcome->paths()->isEmpty()) {
    echo "No paths found.\n";
    exit;
}

echo "Found {$outcome->paths()->count()} path(s):\n\n";

foreach ($outcome->paths() as $i => $path) {
    echo "Path " . ($i + 1) . ":\n";
    $route = [];
    foreach ($path->hopsAsArray() as $index => $hop) {
        if (0 === $index) {
            $route[] = $hop->from();
        }

        $route[] = $hop->to();
    }

    echo "  Route: " . implode(' -> ', $route) . "\n";
    echo "  Spend: {$path->totalSpent()->amount()} {$path->totalSpent()->currency()}\n";
    echo "  Receive: {$path->totalReceived()->amount()} {$path->totalReceived()->currency()}\n";
    echo "  Residual tolerance: {$path->residualTolerance()->percentage()}%\n";
    echo "  Fees: " . json_encode($path->feeBreakdownAsArray()) . "\n";
    echo "  Hops: {$path->hops()->count()}\n";

    echo "  Hops detail:\n";
    foreach ($path->hops() as $hop) {
        echo "    {$hop->from()} -> {$hop->to()}: ";
        echo "Spend {$hop->spent()->amount()} {$hop->spent()->currency()}, ";
        echo "Receive {$hop->received()->amount()} {$hop->received()->currency()} ";
        echo "via {$hop->order()->assetPair()->base()}/{$hop->order()->assetPair()->quote()}\n";
    }
    echo "\n";
}

// 5. Display search metadata
$guardReport = $outcome->guardLimits();
echo "Search Statistics:\n";
echo "  Expansions: {$guardReport->expansions()}\n";
echo "  Visited states: {$guardReport->visitedStates()}\n";
echo "  Time: {$guardReport->elapsedMilliseconds()}ms\n";
echo "  Any limit reached: " . ($guardReport->anyLimitReached() ? 'yes' : 'no') . "\n";
```

---

## ExecutionPlanService (Recommended)

Starting with version 2.0, `ExecutionPlanService` is the recommended API for path finding. Unlike `PathSearchService` which only returns linear paths, `ExecutionPlanService` can find optimal execution plans that include:

- **Multi-order same direction**: Multiple orders for USD→BTC combined
- **Split execution**: Input split across parallel routes
- **Merge execution**: Routes converging at target currency

### Basic Usage

```php
<?php

use SomeWork\P2PPathFinder\Application\PathSearch\Api\Request\PathSearchRequest;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\ExecutionPlanService;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;

// Create the execution plan service
$graphBuilder = new GraphBuilder();
$planService = new ExecutionPlanService($graphBuilder);

// Configure and run search
$config = PathSearchConfig::builder()
    ->withSpendAmount($spendAmount)
    ->withToleranceBounds('0.0', '0.10')
    ->withHopLimits(1, 3)
    ->build();

$request = new PathSearchRequest($orderBook, $config, 'BTC');
$outcome = $planService->findBestPlans($request);

// Process results
$bestPlan = $outcome->bestPath();
if (null !== $bestPlan) {
    echo "Spend: {$bestPlan->totalSpent()->amount()} {$bestPlan->totalSpent()->currency()}\n";
    echo "Receive: {$bestPlan->totalReceived()->amount()} {$bestPlan->totalReceived()->currency()}\n";
    
    foreach ($bestPlan->steps() as $step) {
        echo "Step {$step->sequenceNumber()}: {$step->from()} -> {$step->to()}\n";
        echo "  Spent: {$step->spent()->amount()} {$step->spent()->currency()}\n";
        echo "  Received: {$step->received()->amount()} {$step->received()->currency()}\n";
    }
}
```

### Split/Merge Example

Here's an example with multiple orders that can be used for split/merge execution:

```php
<?php

use SomeWork\P2PPathFinder\Domain\Money\AssetPair;
use SomeWork\P2PPathFinder\Domain\Money\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderBook;
use SomeWork\P2PPathFinder\Domain\Order\OrderBounds;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;

// Create orders for a diamond pattern: USD → EUR → BTC and USD → GBP → BTC
$orders = [
    // USD -> EUR
    Order::buy(
        AssetPair::fromString('EUR/USD'),
        OrderBounds::fromStrings('100', '5000', 2),
        ExchangeRate::fromString('EUR', 'USD', '1.10', 4),
        OrderSide::BUY
    ),
    // USD -> GBP (alternative route)
    Order::buy(
        AssetPair::fromString('GBP/USD'),
        OrderBounds::fromStrings('100', '5000', 2),
        ExchangeRate::fromString('GBP', 'USD', '1.25', 4),
        OrderSide::BUY
    ),
    // EUR -> BTC
    Order::buy(
        AssetPair::fromString('BTC/EUR'),
        OrderBounds::fromStrings('0.01', '1.0', 8),
        ExchangeRate::fromString('BTC', 'EUR', '27000', 2),
        OrderSide::BUY
    ),
    // GBP -> BTC (merge at target)
    Order::buy(
        AssetPair::fromString('BTC/GBP'),
        OrderBounds::fromStrings('0.01', '1.0', 8),
        ExchangeRate::fromString('BTC', 'GBP', '23500', 2),
        OrderSide::BUY
    ),
];

$orderBook = new OrderBook($orders);

// Search will find the optimal plan, which may split USD across EUR and GBP routes
$planService = new ExecutionPlanService(new GraphBuilder());
$outcome = $planService->findBestPlans($request);

$plan = $outcome->bestPath();

// Check if the plan is linear (single path) or has splits/merges
if ($plan->isLinear()) {
    echo "Linear execution plan (single path)\n";
    // Can convert to legacy Path format if needed
    $path = $plan->asLinearPath();
} else {
    echo "Complex execution plan with splits/merges\n";
    echo "Steps: {$plan->stepCount()}\n";
}
```

### Understanding ExecutionPlan vs Path

| Aspect | ExecutionPlan | Path |
|--------|---------------|------|
| Result type | `ExecutionStepCollection` | `PathHopCollection` |
| Element type | `ExecutionStep` | `PathHop` |
| Has sequence numbers | Yes (`sequenceNumber()`) | No (implicit ordering) |
| Supports splits | Yes | No |
| Supports merges | Yes | No |
| Check linearity | `isLinear()` | Always linear |
| Convert to Path | `asLinearPath()` | N/A |

---

## Migration from PathSearchService

`PathSearchService` is deprecated since version 2.0. Here's how to migrate:

### Before (Deprecated)

```php
use SomeWork\P2PPathFinder\Application\PathSearch\Service\PathSearchService;

$service = new PathSearchService(new GraphBuilder());
$outcome = $service->findBestPaths($request);  // Triggers deprecation warning

foreach ($outcome->paths() as $path) {
    foreach ($path->hops() as $hop) {
        echo "{$hop->from()} -> {$hop->to()}\n";
    }
}
```

### After (Recommended)

```php
use SomeWork\P2PPathFinder\Application\PathSearch\Service\ExecutionPlanService;

$service = new ExecutionPlanService(new GraphBuilder());
$outcome = $service->findBestPlans($request);

foreach ($outcome->paths() as $plan) {
    foreach ($plan->steps() as $step) {
        echo "{$step->from()} -> {$step->to()}\n";
    }
}
```

### Incremental Migration

If you need to maintain backward compatibility during migration:

```php
use SomeWork\P2PPathFinder\Application\PathSearch\Service\ExecutionPlanService;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\PathSearchService;

$planService = new ExecutionPlanService(new GraphBuilder());
$outcome = $planService->findBestPlans($request);

foreach ($outcome->paths() as $plan) {
    // Convert linear plans to legacy Path format
    if ($plan->isLinear()) {
        $path = $plan->asLinearPath();
        // Use $path with legacy code expecting PathHop objects
        processLegacyPath($path);
    } else {
        // Handle non-linear plans with new code
        processExecutionPlan($plan);
    }
}

// Or use the static helper for direct conversion
$plan = $outcome->bestPath();
if ($plan->isLinear()) {
    $path = PathSearchService::planToPath($plan);
    // Use legacy $path
}
```

### Key Differences

| PathSearchService | ExecutionPlanService |
|-------------------|---------------------|
| `findBestPaths()` | `findBestPlans()` |
| Returns `Path` | Returns `ExecutionPlan` |
| `$path->hops()` | `$plan->steps()` |
| `PathHop` | `ExecutionStep` |
| No `sequenceNumber()` | Has `sequenceNumber()` |
| Linear only | Linear + split/merge |

---

## Next Steps

Now that you've learned the basics, explore more advanced topics:

### Documentation

- **[API Stability Guide](api-stability.md)** – Complete API reference
- **[API Contracts](api-contracts.md)** – Object API specification
- **[Domain Invariants](domain-invariants.md)** – Validation rules and constraints
- **[Exception Handling](exceptions.md)** – Error handling guide
- **[Troubleshooting Guide](troubleshooting.md)** – Common issues and solutions

### Advanced Features

- **Custom Fee Policies**: Implement `FeePolicy` interface for complex fee structures
- **Custom Order Filters**: Implement `OrderFilterInterface` for advanced filtering
- **Custom Path Ordering**: Implement `PathOrderStrategy` for custom result ordering
- **Object APIs**: Use strongly-typed methods for accessing data

### Examples

Check out the [`examples/`](../examples/) directory for more:

- **Custom Ordering Strategy** (`examples/custom-ordering-strategy.php`)
- **Custom Order Filter** (`examples/custom-order-filter.php`)
- **Custom Fee Policy** (`examples/custom-fee-policy.php`)
- **Guarded Search** (`examples/guarded-search-example.php`)

### Performance Optimization

- Pre-filter order books before searching
- Use reasonable `topK` values (10-20)
- Set appropriate guard rails
- Profile with `SearchGuardReport` metrics

### Testing

- Use property-based testing for domain logic
- Test with realistic order books
- Verify determinism (same input → same output)
- Test exception scenarios

---

## Quick Reference

### Common Patterns

**Simple search**:
```php
$config = PathSearchConfig::builder()
    ->withSpendAmount($money)
    ->withToleranceBounds('0.0', '0.10')
    ->withHopLimits(1, 3)
    ->build();

$request = new PathSearchRequest($orderBook, $config, 'BTC');
$outcome = $service->findBestPaths($request);
```

**Check for results**:
```php
if ($outcome->paths()->isEmpty()) {
    // No paths found
}
```

**Get best path**:
```php
$bestPath = $outcome->paths()->first();
```

**Iterate all paths**:
```php
foreach ($outcome->paths() as $path) {
    // Process path
}
```

**Check guard limits**:
```php
if ($outcome->guardLimits()->anyLimitReached()) {
    // Search was limited
}
```

### Common Mistakes

❌ **Wrong: Negative amounts**
```php
$money = Money::fromString('USD', '-100.00', 2);  // Throws exception
```

✅ **Right: Non-negative amounts**
```php
$money = Money::fromString('USD', '100.00', 2);
```

❌ **Wrong: Invalid currency**
```php
$money = Money::fromString('US', '100.00', 2);  // Throws exception (too short)
```

✅ **Right: 3-letter ISO currency code**
```php
$money = Money::fromString('USD', '100.00', 2);
```

❌ **Wrong: Min > Max**
```php
$config = PathSearchConfig::builder()
    ->withToleranceBounds('0.10', '0.05')  // Throws exception
    ->build();
```

✅ **Right: Min ≤ Max**
```php
$config = PathSearchConfig::builder()
    ->withToleranceBounds('0.05', '0.10')
    ->build();
```

---

## Need Help?

- **Troubleshooting**: See [troubleshooting.md](troubleshooting.md)
- **GitHub Issues**: https://github.com/somework/p2p-path-finder/issues
- **Documentation**: https://github.com/somework/p2p-path-finder/tree/main/docs

---

**Happy path finding!** 🚀
