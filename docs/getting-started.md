# Getting Started with P2P Path Finder

**Version**: 2.0  
**Last Updated**: 2026-01-26

This guide will help you get started with the P2P Path Finder library in just a few minutes.

---

## Table of Contents

- [Installation](#installation)
- [Your First Path Search](#your-first-path-search)
- [Understanding the Results](#understanding-the-results)
- [Working with Orders](#working-with-orders)
- [Customizing the Search](#customizing-the-search)
- [Split/Merge Execution Plans](#splitmerge-execution-plans)
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
use SomeWork\P2PPathFinder\Application\PathSearch\Service\ExecutionPlanService;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;

// Create the execution plan service
$graphBuilder = new GraphBuilder();
$planService = new ExecutionPlanService($graphBuilder);

// Create the search request
$request = new PathSearchRequest(
    $orderBook,
    $config,
    'BTC'  // Target currency
);

// Find the best execution plan
$outcome = $planService->findBestPlans($request);
```

**What's happening here?**

- We create an **`ExecutionPlanService`** with a `GraphBuilder`
- We create a **`PathSearchRequest`** specifying the order book, configuration, and target currency (BTC)
- We call **`findBestPlans()`** to search for the optimal execution plan

### Step 4: Display the Results

```php
// Check if a plan was found
$bestPlan = $outcome->bestPath();
if (null === $bestPlan) {
    echo "No execution plan found.\n";
    exit;
}

// Display totals and plan information
echo "Best execution plan found!\n";
echo "  Source: {$bestPlan->sourceCurrency()}\n";
echo "  Target: {$bestPlan->targetCurrency()}\n";
echo "  Spend: {$bestPlan->totalSpent()->amount()} {$bestPlan->totalSpent()->currency()}\n";
echo "  Receive: {$bestPlan->totalReceived()->amount()} {$bestPlan->totalReceived()->currency()}\n";
echo "  Residual tolerance: {$bestPlan->residualTolerance()->percentage()}%\n";
echo "  Steps: {$bestPlan->stepCount()}\n";
echo "  Is linear: " . ($bestPlan->isLinear() ? 'yes' : 'no') . "\n";

// Inspect step-level Orders and amounts
foreach ($bestPlan->steps() as $step) {
    echo "  Step {$step->sequenceNumber()}: {$step->from()} -> {$step->to()}\n";
    echo "    Order pair: {$step->order()->assetPair()->base()}/{$step->order()->assetPair()->quote()}\n";
    echo "    Spent: {$step->spent()->amount()} {$step->spent()->currency()}\n";
    echo "    Received: {$step->received()->amount()} {$step->received()->currency()}\n";
}
```

**Expected output**:

```
Best execution plan found!
  Source: USD
  Target: BTC
  Spend: 1000.00 USD
  Receive: 0.03333333 BTC
  Residual tolerance: 5.00%
  Steps: 1
  Is linear: yes
  Step 1: USD -> BTC
    Order pair: BTC/USD
    Spent: 1000.00 USD
    Received: 0.03333333 BTC
```

---

## Understanding the Results

### ExecutionPlan Structure

An **`ExecutionPlan`** contains:

- **`steps()`**: Ordered collection of execution steps (`ExecutionStepCollection`)
- **`stepCount()`**: Number of steps in the plan
- **`sourceCurrency()`**: The source currency (uppercase)
- **`targetCurrency()`**: The target currency (uppercase)
- **`totalSpent()`**: Sum of amounts spent from the source currency (Money object)
- **`totalReceived()`**: Sum of amounts received in the target currency (Money object)
- **`residualTolerance()`**: Remaining tolerance after applying the plan (DecimalTolerance)
- **`feeBreakdown()`**: Aggregated fees across all steps (MoneyMap)
- **`isLinear()`**: Whether the plan is a simple linear path (no splits/merges)
- **`asLinearPath()`**: Convert to `Path` format for linear plans (returns null if non-linear)

### Execution Steps

Each **step** represents one conversion step with sequence ordering:

```php
foreach ($bestPlan->steps() as $step) {
    echo "Step {$step->sequenceNumber()}: {$step->from()} -> {$step->to()}\n";
    echo "  Spent: {$step->spent()->amount()} {$step->spent()->currency()}\n";
    echo "  Received: {$step->received()->amount()} {$step->received()->currency()}\n";

    // Fees for this step
    foreach ($step->fees() as $currency => $fee) {
        echo "    Fee: {$fee->amount()} {$currency}\n";
    }

    // Access the originating order for reconciliation or ID lookup
    $order = $step->order();
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

### Understanding Search Results

`ExecutionPlanService::findBestPlans()` returns at most **ONE** optimal execution plan. The algorithm optimizes for a single global optimum that may include split/merge execution.

```php
use SomeWork\P2PPathFinder\Application\PathSearch\Service\ExecutionPlanService;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;

$planService = new ExecutionPlanService(new GraphBuilder());
$outcome = $planService->findBestPlans($request);

$plan = $outcome->bestPath();  // Single optimal plan or null
if (null !== $plan) {
    echo "Spend: {$plan->totalSpent()->amount()} {$plan->totalSpent()->currency()}\n";
    echo "Receive: {$plan->totalReceived()->amount()} {$plan->totalReceived()->currency()}\n";
}

// Note: $outcome->paths()->count() will be 0 or 1
```

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
$outcome = $planService->findBestPlans($request);
```

---

## Complete Example

Here's a complete working example you can run:

```php
<?php

require 'vendor/autoload.php';

use SomeWork\P2PPathFinder\Application\PathSearch\Api\Request\PathSearchRequest;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\ExecutionPlanService;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;
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
    ->build();

// 3. Run search
$graphBuilder = new GraphBuilder();
$planService = new ExecutionPlanService($graphBuilder);

$request = new PathSearchRequest($orderBook, $config, 'BTC');
$outcome = $planService->findBestPlans($request);

// 4. Display results
echo "=== Execution Plan Search Results ===\n\n";

$bestPlan = $outcome->bestPath();
if (null === $bestPlan) {
    echo "No execution plan found.\n";
    exit;
}

echo "Optimal Execution Plan:\n";
echo "  Source: {$bestPlan->sourceCurrency()}\n";
echo "  Target: {$bestPlan->targetCurrency()}\n";
echo "  Spend: {$bestPlan->totalSpent()->amount()} {$bestPlan->totalSpent()->currency()}\n";
echo "  Receive: {$bestPlan->totalReceived()->amount()} {$bestPlan->totalReceived()->currency()}\n";
echo "  Residual tolerance: {$bestPlan->residualTolerance()->percentage()}%\n";
echo "  Steps: {$bestPlan->stepCount()}\n";
echo "  Is linear: " . ($bestPlan->isLinear() ? 'yes' : 'no') . "\n";

echo "\n  Steps detail:\n";
foreach ($bestPlan->steps() as $step) {
    echo "    Step {$step->sequenceNumber()}: {$step->from()} -> {$step->to()}: ";
    echo "Spend {$step->spent()->amount()} {$step->spent()->currency()}, ";
    echo "Receive {$step->received()->amount()} {$step->received()->currency()} ";
    echo "via {$step->order()->assetPair()->base()}/{$step->order()->assetPair()->quote()}\n";
}
echo "\n";

// 5. Display search metadata
$guardReport = $outcome->guardLimits();
echo "Search Statistics:\n";
echo "  Expansions: {$guardReport->expansions()}\n";
echo "  Visited states: {$guardReport->visitedStates()}\n";
echo "  Time: {$guardReport->elapsedMilliseconds()}ms\n";
echo "  Any limit reached: " . ($guardReport->anyLimitReached() ? 'yes' : 'no') . "\n";
```

---

## Split/Merge Execution Plans

The `ExecutionPlanService` can find optimal execution plans that include complex topologies beyond simple linear paths:

- **Multi-order same direction**: Multiple orders for USD→BTC combined
- **Split execution**: Input split across parallel routes
- **Merge execution**: Routes converging at target currency

### Split/Merge Example

Here's an example with multiple orders that can be used for split/merge execution:

```php
<?php

use SomeWork\P2PPathFinder\Application\PathSearch\Api\Request\PathSearchRequest;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\ExecutionPlanService;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;
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

// Configure and run search
$spendAmount = Money::fromString('USD', '10000.00', 2);
$config = PathSearchConfig::builder()
    ->withSpendAmount($spendAmount)
    ->withToleranceBounds('0.0', '0.10')
    ->withHopLimits(1, 4)
    ->build();

$request = new PathSearchRequest($orderBook, $config, 'BTC');

// Search will find the optimal plan, which may split USD across EUR and GBP routes
$planService = new ExecutionPlanService(new GraphBuilder());
$outcome = $planService->findBestPlans($request);

$plan = $outcome->bestPath();

// Check if the plan is linear (single path) or has splits/merges
if ($plan->isLinear()) {
    echo "Linear execution plan (single path)\n";
} else {
    echo "Complex execution plan with splits/merges\n";
}

echo "Steps: {$plan->stepCount()}\n";
foreach ($plan->steps() as $step) {
    echo "  Step {$step->sequenceNumber()}: {$step->from()} -> {$step->to()}\n";
}
```

### Understanding ExecutionPlan Structure

| Property | Description |
|:---------|:------------|
| `steps()` | `ExecutionStepCollection` - ordered execution steps |
| `stepCount()` | Number of steps in the plan |
| `sourceCurrency()` | Source currency (uppercase) |
| `targetCurrency()` | Target currency (uppercase) |
| `totalSpent()` | Sum of amounts spent from source |
| `totalReceived()` | Sum of amounts received in target |
| `isLinear()` | True if plan is a simple linear path |
| `asLinearPath()` | Convert to `Path` (returns null if non-linear) |

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
- Set appropriate guard rails (expansions, visited states, time budget)
- Use reasonable hop limits (1-4 for most use cases)
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
$outcome = $planService->findBestPlans($request);
```

**Check for results**:
```php
$bestPlan = $outcome->bestPath();
if (null === $bestPlan) {
    // No execution plan found
}
```

**Process the execution plan**:
```php
$bestPlan = $outcome->bestPath();
if (null !== $bestPlan) {
    echo "Spend: {$bestPlan->totalSpent()->amount()}\n";
    echo "Receive: {$bestPlan->totalReceived()->amount()}\n";
    
    foreach ($bestPlan->steps() as $step) {
        echo "{$step->from()} -> {$step->to()}\n";
    }
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
