# Getting Started with P2P Path Finder

**Version**: 1.0  
**Last Updated**: 2024-11-22

This guide will help you get started with the P2P Path Finder library in just a few minutes.

---

## Table of Contents

- [Installation](#installation)
- [Your First Path Search](#your-first-path-search)
- [Understanding the Results](#understanding-the-results)
- [Working with Orders](#working-with-orders)
- [Customizing the Search](#customizing-the-search)
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

use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\AssetPair;
use SomeWork\P2PPathFinder\Domain\ValueObject\OrderBounds;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;

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
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

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
use SomeWork\P2PPathFinder\Application\Service\PathFinderService;
use SomeWork\P2PPathFinder\Application\Service\PathSearchRequest;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;

// Create the path finder service
$graphBuilder = new GraphBuilder();
$pathFinderService = new PathFinderService($graphBuilder);

// Create the search request
$request = new PathSearchRequest(
    $orderBook,
    $config,
    'BTC'  // Target currency
);

// Find the best paths
$outcome = $pathFinderService->findBestPaths($request);
```

**What's happening here?**

- We create a **`PathFinderService`** with a `GraphBuilder`
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
$bestPath = $outcome->paths()->first();

echo "Best path found!\n";
echo "  Route: {$bestPath->route()}\n";
echo "  Spend: {$bestPath->totalSpent()->amount()} {$bestPath->totalSpent()->currency()}\n";
echo "  Receive: {$bestPath->totalReceived()->amount()} {$bestPath->totalReceived()->currency()}\n";
echo "  Cost: {$bestPath->cost()}\n";
echo "  Hops: {$bestPath->hops()}\n";
```

**Expected output**:

```
Best path found!
  Route: USD->BTC
  Spend: 1000.00 USD
  Receive: 0.03333333 BTC
  Cost: 1.000000000000000000
  Hops: 1
```

---

## Understanding the Results

### Path Structure

A **`PathResult`** contains:

- **`route()`**: String representation of the path (e.g., "USD->BTC" or "USD->EUR->BTC")
- **`totalSpent()`**: Total amount spent (Money object)
- **`totalReceived()`**: Total amount received (Money object)
- **`cost()`**: Normalized cost metric (lower is better)
- **`hops()`**: Number of hops in the path
- **`legs()`**: Individual legs of the path (PathLegCollection)

### Path Legs

Each **leg** represents one hop in the path:

```php
foreach ($bestPath->legs() as $leg) {
    echo "Leg: {$leg->from()} -> {$leg->to()}\n";
    echo "  Spent: {$leg->spent()->amount()} {$leg->spent()->currency()}\n";
    echo "  Received: {$leg->received()->amount()} {$leg->received()->currency()}\n";
    echo "  Fees: \n";
    
    foreach ($leg->fees() as $currency => $fee) {
        echo "    {$fee->amount()} {$currency}\n";
    }
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
use SomeWork\P2PPathFinder\Domain\Order\FeePolicy;
use SomeWork\P2PPathFinder\Domain\Order\FeeBreakdown;

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

### Requesting Multiple Paths

By default, the search returns up to 5 paths. You can request more:

```php
$config = PathSearchConfig::builder()
    ->withSpendAmount($spendAmount)
    ->withToleranceBounds('0.0', '0.10')
    ->withHopLimits(1, 3)
    ->withTopK(10)  // Request top 10 paths
    ->build();

// Iterate through all paths
foreach ($outcome->paths() as $path) {
    echo "Path: {$path->route()}\n";
    echo "  Cost: {$path->cost()}\n";
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
use SomeWork\P2PPathFinder\Application\Config\SearchGuardConfig;

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
use SomeWork\P2PPathFinder\Application\Filter\MinimumAmountFilter;
use SomeWork\P2PPathFinder\Application\Filter\MaximumAmountFilter;
use SomeWork\P2PPathFinder\Application\Filter\ToleranceWindowFilter;

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
$outcome = $pathFinderService->findBestPaths($request);
```

---

## Complete Example

Here's a complete working example you can run:

```php
<?php

require 'vendor/autoload.php';

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
$pathFinderService = new PathFinderService($graphBuilder);

$request = new PathSearchRequest($orderBook, $config, 'BTC');
$outcome = $pathFinderService->findBestPaths($request);

// 4. Display results
echo "=== Path Search Results ===\n\n";

if ($outcome->paths()->isEmpty()) {
    echo "No paths found.\n";
    exit;
}

echo "Found {$outcome->paths()->count()} path(s):\n\n";

foreach ($outcome->paths() as $i => $path) {
    echo "Path " . ($i + 1) . ":\n";
    echo "  Route: {$path->route()}\n";
    echo "  Spend: {$path->totalSpent()->amount()} {$path->totalSpent()->currency()}\n";
    echo "  Receive: {$path->totalReceived()->amount()} {$path->totalReceived()->currency()}\n";
    echo "  Cost: {$path->cost()}\n";
    echo "  Hops: {$path->hops()}\n";
    
    echo "  Legs:\n";
    foreach ($path->legs() as $leg) {
        echo "    {$leg->from()} -> {$leg->to()}: ";
        echo "Spend {$leg->spent()->amount()}, ";
        echo "Receive {$leg->received()->amount()}\n";
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

## Next Steps

Now that you've learned the basics, explore more advanced topics:

### Documentation

- **[API Stability Guide](api-stability.md)** – Complete API reference
- **[API Contracts](api-contracts.md)** – JSON serialization format
- **[Domain Invariants](domain-invariants.md)** – Validation rules and constraints
- **[Exception Handling](exceptions.md)** – Error handling guide
- **[Troubleshooting Guide](troubleshooting.md)** – Common issues and solutions

### Advanced Features

- **Custom Fee Policies**: Implement `FeePolicy` interface for complex fee structures
- **Custom Order Filters**: Implement `OrderFilterInterface` for advanced filtering
- **Custom Path Ordering**: Implement `PathOrderStrategy` for custom result ordering
- **JSON Serialization**: Use `jsonSerialize()` for API responses

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

