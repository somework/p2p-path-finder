# Troubleshooting Guide

**Version**: 1.0  
**Last Updated**: 2024-11-22

This guide helps you diagnose and resolve common issues when using the P2P Path Finder library.

---

## Table of Contents

- [Common Issues](#common-issues)
  - [No Paths Found](#no-paths-found)
  - [Guard Limits Hit](#guard-limits-hit)
  - [Precision Errors](#precision-errors)
  - [Currency Mismatches](#currency-mismatches)
  - [Invalid Input Errors](#invalid-input-errors)
- [Performance Issues](#performance-issues)
- [Debugging Tips](#debugging-tips)
- [Getting Help](#getting-help)

---

## Common Issues

### No Paths Found

**Symptom**: `SearchOutcome::paths()` returns an empty collection.

```php
$outcome = $pathFinderService->findBestPaths($request);
if ($outcome->paths()->isEmpty()) {
    // No paths found!
}
```

#### Causes and Solutions

**1. No Direct or Indirect Route Exists**

**Cause**: The order book doesn't contain orders that connect your source currency to the target currency.

**Solution**:
```php
// Check if any orders exist for your currency pair
$filteredOrders = $orderBook->filter(
    new CurrencyPairFilter($sourceCurrency, $targetCurrency)
);

if (empty(iterator_to_array($filteredOrders))) {
    // No direct orders - check for bridge currencies
    echo "No orders found for {$sourceCurrency} -> {$targetCurrency}\n";
}
```

**2. Spend Amount Outside Order Bounds**

**Cause**: Your spend amount is below the minimum or above the maximum of all available orders.

**Solution**:
```php
use SomeWork\P2PPathFinder\Application\Filter\MinimumAmountFilter;
use SomeWork\P2PPathFinder\Application\Filter\MaximumAmountFilter;

// Check if orders exist in your amount range
$minFilter = new MinimumAmountFilter(
    Money::fromString('USD', '100.00', 2)
);
$maxFilter = new MaximumAmountFilter(
    Money::fromString('USD', '1000.00', 2)
);

$inRange = $orderBook->filter($minFilter, $maxFilter);
if (empty(iterator_to_array($inRange))) {
    echo "No orders accept amounts in your range\n";
}
```

**3. Tolerance Window Too Narrow**

**Cause**: Your tolerance bounds are too restrictive, filtering out all potential paths.

**Solution**:
```php
// Try widening the tolerance window
$config = PathSearchConfig::builder()
    ->withSpendAmount($spendMoney)
    ->withToleranceBounds('0.0', '0.20')  // 0-20% tolerance (wider)
    ->withHopLimits(1, 3)
    ->build();
```

**4. Hop Limit Too Restrictive**

**Cause**: `maximumHops` is too low to reach the target currency.

**Solution**:
```php
// Increase maximum hops
$config = PathSearchConfig::builder()
    ->withSpendAmount($spendMoney)
    ->withToleranceBounds('0.05', '0.10')
    ->withHopLimits(1, 5)  // Allow up to 5 hops
    ->build();
```

**5. Mandatory Fees Exceed Spend Amount**

**Cause**: Orders have minimum fees that exceed your spend amount.

**Solution**: Increase your spend amount or find orders with lower fees.

**Diagnostic Tip**:
```php
// Enable guard reporting to see search statistics
$outcome = $pathFinderService->findBestPaths($request);
$guardReport = $outcome->guardLimits();

echo "Expansions: {$guardReport->expansions()}\n";
echo "Visited states: {$guardReport->visitedStates()}\n";

// Low expansion count suggests the search space was very limited
if ($guardReport->expansions() < 10) {
    echo "Very few paths explored - likely order book issue\n";
}
```

---

### Guard Limits Hit

**Symptom**: Search terminates early with partial results or throws `GuardLimitExceeded` exception.

```php
// Metadata mode (default)
$outcome = $pathFinderService->findBestPaths($request);
if ($outcome->guardLimits()->anyLimitReached()) {
    echo "Search was limited by guards\n";
    // Partial results available in $outcome->paths()
}

// Exception mode
try {
    $config = PathSearchConfig::builder()
        ->withSpendAmount($spendMoney)
        ->withToleranceBounds('0.05', '0.10')
        ->withHopLimits(1, 3)
        ->withSearchGuardConfig(
            SearchGuardConfig::strict()
                ->withMaxExpansions(5000)
                ->withThrowOnLimit()  // Enable exception mode
        )
        ->build();
    
    $outcome = $pathFinderService->findBestPaths($request);
} catch (GuardLimitExceeded $e) {
    $report = $e->getReport();
    echo "Limit reached: {$e->getMessage()}\n";
}
```

#### Causes and Solutions

**1. Expansion Limit Reached**

**Cause**: The search explored the maximum allowed number of graph nodes.

**Solution**:
```php
// Increase expansion limit
$guardConfig = SearchGuardConfig::strict()
    ->withMaxExpansions(10000);  // Default is 5000

$config = PathSearchConfig::builder()
    ->withSpendAmount($spendMoney)
    ->withToleranceBounds('0.05', '0.10')
    ->withHopLimits(1, 3)
    ->withSearchGuardConfig($guardConfig)
    ->build();
```

**Trade-offs**:
- Higher limits → more memory usage, longer execution
- Lower limits → faster results, but may miss optimal paths

**2. Visited State Limit Reached**

**Cause**: The search tracked the maximum allowed number of unique node states.

**Solution**:
```php
// Increase visited state limit
$guardConfig = SearchGuardConfig::strict()
    ->withMaxVisitedStates(20000);  // Default is 10000
```

**3. Time Budget Exceeded**

**Cause**: The search exceeded the wall-clock time budget.

**Solution**:
```php
// Increase time budget (in milliseconds)
$guardConfig = SearchGuardConfig::strict()
    ->withTimeBudget(5000);  // 5 seconds (default is 3000ms)
```

**When to Use Each Guard**:

| Guard | Use Case |
|-------|----------|
| **Expansion Limit** | Control computational cost per search |
| **Visited State Limit** | Control memory usage |
| **Time Budget** | Enforce strict latency SLA (e.g., API response time) |

**Recommendation**: Use **time budget** for user-facing APIs and **expansion limits** for batch processing.

---

### Precision Errors

**Symptom**: `PrecisionViolation` exception thrown.

```php
try {
    $config = PathSearchConfig::builder()
        ->withSpendAmount($spendMoney)
        ->withToleranceBounds('0.00000001', '0.00000002')  // Too tight!
        ->build();
} catch (PrecisionViolation $e) {
    echo "Precision error: {$e->getMessage()}\n";
}
```

#### Causes and Solutions

**1. Tolerance Window Collapses Due to Scale**

**Cause**: The spend amount's scale is too low to represent the tight tolerance bounds.

**Example**:
```php
// WRONG: Scale 2 can't represent tolerance window 0.00000001-0.00000002
$spendAmount = Money::fromString('USD', '100.00', 2);
$config = PathSearchConfig::builder()
    ->withSpendAmount($spendAmount)
    ->withToleranceBounds('0.00000001', '0.00000002')  // ❌ Collapses!
    ->build();
```

**Solution**: Use higher scale for the spend amount:
```php
// CORRECT: Scale 6 can represent the tolerance window
$spendAmount = Money::fromString('USD', '100.000000', 6);
$config = PathSearchConfig::builder()
    ->withSpendAmount($spendAmount)
    ->withToleranceBounds('0.00000001', '0.00000002')  // ✅ Works
    ->build();
```

**Rule of Thumb**: Spend amount scale should be ≥ 6 for tight tolerance windows.

**2. Currency Scale Mismatch**

**Cause**: Mixing different scales across currencies without accounting for precision loss.

**Solution**: Ensure all monetary amounts for a currency use consistent scales:
```php
// Define standard scales per currency
const SCALES = [
    'USD' => 2,   // Fiat
    'BTC' => 8,   // Bitcoin
    'ETH' => 18,  // Ethereum
];

$spendAmount = Money::fromString('USD', '100.00', SCALES['USD']);
```

---

### Currency Mismatches

**Symptom**: `InvalidInput` exception with "Currency mismatch" message.

```php
try {
    // Trying to add USD + EUR
    $result = $usdAmount->add($eurAmount, 2);
} catch (InvalidInput $e) {
    echo "Currency mismatch: {$e->getMessage()}\n";
}
```

#### Causes and Solutions

**1. Arithmetic on Different Currencies**

**Cause**: Attempting operations (add, subtract, compare) on Money objects with different currencies.

**Solution**: Convert to a common currency before arithmetic:
```php
// Use exchange rates to convert
$usdAmount = Money::fromString('USD', '100.00', 2);
$eurAmount = Money::fromString('EUR', '92.00', 2);
$rate = ExchangeRate::fromString('EUR', 'USD', '1.0870', 4);

// Convert EUR to USD
$eurInUsd = $rate->convert($eurAmount, 2);

// Now you can add them
$total = $usdAmount->add($eurInUsd, 2);
```

**2. Order Book Currency Mismatch**

**Cause**: Orders in the book don't match the source or target currency.

**Solution**: Filter orders by currency pair:
```php
use SomeWork\P2PPathFinder\Application\Filter\CurrencyPairFilter;

// Filter orders for specific currency pair
$filter = new CurrencyPairFilter('USD', 'BTC');
$filtered = $orderBook->filter($filter);
```

---

### Invalid Input Errors

**Symptom**: `InvalidInput` exception on object construction or configuration.

```php
try {
    $money = Money::fromString('USD', '-100.00', 2);
} catch (InvalidInput $e) {
    echo "Invalid input: {$e->getMessage()}\n";
}
```

#### Common Validation Failures

**1. Negative Money Amounts**

```php
// ❌ WRONG: Negative amounts not allowed
$money = Money::fromString('USD', '-100.00', 2);

// ✅ CORRECT: Use non-negative amounts
$money = Money::fromString('USD', '100.00', 2);
```

**Rationale**: In the path-finding domain, negative amounts have no semantic meaning.

**2. Invalid Currency Codes**

```php
// ❌ WRONG: Invalid currency code
$money = Money::fromString('US', '100.00', 2);  // Too short

// ✅ CORRECT: Use standard 3-letter ISO currency codes
$money = Money::fromString('USD', '100.00', 2);
```

**Validation Rules**:
- Must be exactly 3 characters
- Must be alphabetic (A-Z)
- Case-insensitive (converted to uppercase)

**3. Invalid Scales**

```php
// ❌ WRONG: Scale too high
$money = Money::fromString('USD', '100.00', 100);

// ✅ CORRECT: Use reasonable scales (0-30)
$money = Money::fromString('USD', '100.00', 2);
```

**Scale Limits**: 0 ≤ scale ≤ 30

**4. Invalid Tolerance Bounds**

```php
// ❌ WRONG: minimum > maximum
$config = PathSearchConfig::builder()
    ->withToleranceBounds('0.10', '0.05')  // Invalid
    ->build();

// ✅ CORRECT: minimum ≤ maximum
$config = PathSearchConfig::builder()
    ->withToleranceBounds('0.05', '0.10')  // Valid
    ->build();
```

**5. Invalid Hop Limits**

```php
// ❌ WRONG: minimumHops > maximumHops
$config = PathSearchConfig::builder()
    ->withHopLimits(3, 1)  // Invalid
    ->build();

// ✅ CORRECT: minimumHops ≤ maximumHops
$config = PathSearchConfig::builder()
    ->withHopLimits(1, 3)  // Valid
    ->build();
```

---

## Performance Issues

### Slow Path Finding

**Symptom**: `findBestPaths()` takes several seconds to complete.

#### Causes and Solutions

**1. Too Many Orders in Order Book**

**Cause**: Large order book (1000+ orders) without filtering.

**Solution**: Pre-filter orders before searching:
```php
use SomeWork\P2PPathFinder\Application\Filter\ToleranceWindowFilter;

$referenceRate = ExchangeRate::fromString('BTC', 'USD', '30000', 2);
$toleranceFilter = new ToleranceWindowFilter($referenceRate, '0.10');

// Filter before searching
$filteredOrders = $orderBook->filter($toleranceFilter);
$filteredBook = new OrderBook(iterator_to_array($filteredOrders));

// Now search on filtered book
$request = new PathSearchRequest($filteredBook, $config, 'BTC');
$outcome = $pathFinderService->findBestPaths($request);
```

**Performance Impact**: Filtering 1000 orders to 100 relevant ones can improve search speed 10x.

**2. High `topK` Value**

**Cause**: Requesting many paths (topK > 100) slows down result ordering.

**Solution**: Request only what you need:
```php
// ❌ SLOW: Requesting 1000 paths
$config = PathSearchConfig::builder()
    ->withSpendAmount($spendMoney)
    ->withToleranceBounds('0.05', '0.10')
    ->withTopK(1000)  // Expensive!
    ->build();

// ✅ FAST: Request 10-20 paths
$config = PathSearchConfig::builder()
    ->withSpendAmount($spendMoney)
    ->withToleranceBounds('0.05', '0.10')
    ->withTopK(10)  // Sufficient for most use cases
    ->build();
```

**3. Wide Tolerance Window + High Max Hops**

**Cause**: Wide tolerance + many hops creates a massive search space.

**Solution**: Use guard rails to limit search:
```php
$guardConfig = SearchGuardConfig::strict()
    ->withMaxExpansions(5000)
    ->withTimeBudget(3000);  // 3 seconds max

$config = PathSearchConfig::builder()
    ->withSpendAmount($spendMoney)
    ->withToleranceBounds('0.05', '0.10')  // Narrower tolerance
    ->withHopLimits(1, 3)  // Fewer hops
    ->withSearchGuardConfig($guardConfig)
    ->build();
```

**4. Complex Fee Policies**

**Cause**: Fee policies with expensive calculations slow down edge evaluation.

**Solution**: Optimize fee policy implementations:
```php
// Cache fee calculations if possible
class CachedFeePolicy implements FeePolicy
{
    private array $cache = [];
    
    public function calculate(
        OrderSide $side,
        Money $baseAmount,
        Money $quoteAmount
    ): FeeBreakdown {
        $key = "{$side->value}:{$baseAmount->amount()}:{$quoteAmount->amount()}";
        
        if (!isset($this->cache[$key])) {
            $this->cache[$key] = $this->computeFee($baseAmount, $quoteAmount);
        }
        
        return $this->cache[$key];
    }
}
```

### High Memory Usage

**Symptom**: Script uses excessive memory (> 128MB) or hits memory limit.

#### Causes and Solutions

**1. Large Order Book**

**Solution**: Filter orders or increase PHP memory limit:
```php
// Increase memory limit in php.ini or runtime
ini_set('memory_limit', '256M');
```

**2. High Visited State Limit**

**Solution**: Reduce visited state limit:
```php
$guardConfig = SearchGuardConfig::strict()
    ->withMaxVisitedStates(5000);  // Lower limit
```

**3. Requesting Too Many Paths (topK)**

**Solution**: Request fewer paths (see "High topK Value" above).

---

## Debugging Tips

### Enable Verbose Logging

```php
// Log search progress
$outcome = $pathFinderService->findBestPaths($request);
$guardReport = $outcome->guardLimits();

error_log("Search completed:");
error_log("  Paths found: " . $outcome->paths()->count());
error_log("  Expansions: {$guardReport->expansions()}");
error_log("  Visited states: {$guardReport->visitedStates()}");
error_log("  Time: {$guardReport->elapsedMilliseconds()}ms");
error_log("  Any limit reached: " . ($guardReport->anyLimitReached() ? 'yes' : 'no'));
```

### Inspect Individual Paths

```php
foreach ($outcome->paths() as $path) {
    echo "Path: {$path->route()}\n";
    echo "  Cost: {$path->cost()}\n";
    echo "  Hops: {$path->hops()}\n";
    echo "  Spent: {$path->totalSpent()->amount()} {$path->totalSpent()->currency()}\n";
    echo "  Received: {$path->totalReceived()->amount()} {$path->totalReceived()->currency()}\n";
    
    foreach ($path->legs() as $leg) {
        echo "    {$leg->from()} -> {$leg->to()}: ";
        echo "Spent {$leg->spent()->amount()}, ";
        echo "Received {$leg->received()->amount()}\n";
    }
}
```

### Test with Minimal Order Book

```php
// Create a minimal order book for testing
$orderBook = new OrderBook([
    Order::buy(
        AssetPair::fromString('BTC/USD'),
        OrderBounds::fromStrings('0.1', '1.0', 8),
        ExchangeRate::fromString('BTC', 'USD', '30000', 2),
        OrderSide::BUY
    ),
]);

// If this works, gradually add more orders to isolate the issue
```

### Use Guard Reports to Diagnose Search Behavior

```php
$guardReport = $outcome->guardLimits();

// Check if search was constrained
if ($guardReport->expansionsReached()) {
    echo "Hit expansion limit - consider increasing or filtering orders\n";
}

if ($guardReport->visitedStatesReached()) {
    echo "Hit visited state limit - search space too large\n";
}

if ($guardReport->timeBudgetReached()) {
    echo "Hit time budget - search too slow, consider optimizations\n";
}

// Low expansion count suggests limited search space
if ($guardReport->expansions() < 100) {
    echo "Very few expansions - order book may not support your request\n";
}
```

### Validate Order Book

```php
// Check order book size
$orders = iterator_to_array($orderBook);
echo "Order book contains " . count($orders) . " orders\n";

// Check for relevant currency pairs
$sourceCurrency = 'USD';
$targetCurrency = 'BTC';
$relevantOrders = 0;

foreach ($orders as $order) {
    $pair = $order->assetPair();
    if ($pair->base() === $sourceCurrency || $pair->quote() === $sourceCurrency ||
        $pair->base() === $targetCurrency || $pair->quote() === $targetCurrency) {
        $relevantOrders++;
    }
}

echo "Found {$relevantOrders} orders involving {$sourceCurrency} or {$targetCurrency}\n";
```

### Test Exception Handling

```php
use SomeWork\P2PPathFinder\Exception\ExceptionInterface;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\GuardLimitExceeded;

try {
    $outcome = $pathFinderService->findBestPaths($request);
} catch (InvalidInput $e) {
    // Invalid input - fix configuration
    error_log("Invalid input: " . $e->getMessage());
    error_log("Trace: " . $e->getTraceAsString());
} catch (GuardLimitExceeded $e) {
    // Guard limit exceeded - adjust limits or filters
    error_log("Guard limit exceeded: " . $e->getMessage());
    $report = $e->getReport();
    error_log("  Expansions: {$report->expansions()} / {$report->expansionLimit()}");
    error_log("  Visited: {$report->visitedStates()} / {$report->visitedStateLimit()}");
    error_log("  Time: {$report->elapsedMilliseconds()}ms / {$report->timeBudgetLimit()}ms");
} catch (ExceptionInterface $e) {
    // Any other library exception
    error_log("Library error: " . $e->getMessage());
}
```

---

## Getting Help

### Before Asking for Help

1. **Check this guide** for your specific issue
2. **Read the error message carefully** - they include helpful context
3. **Review the relevant documentation**:
   - [Exception Handling Guide](exceptions.md) for error messages
   - [Domain Invariants](domain-invariants.md) for validation rules
   - [API Contracts](api-contracts.md) for JSON structures
4. **Create a minimal reproducible example** with a small order book

### Reporting Issues

When reporting issues, include:

1. **PHP Version**: `php -v`
2. **Library Version**: Check `composer.json` or `composer show somework/p2p-path-finder`
3. **Complete Error Message**: Including stack trace
4. **Minimal Code Example**: That reproduces the issue
5. **Order Book Size**: Number of orders
6. **Configuration**: PathSearchConfig values

**Example Issue Report**:

```
Environment:
- PHP 8.2.12
- p2p-path-finder 1.0.0
- 500 orders in order book

Problem:
No paths found for USD -> BTC

Configuration:
- Spend amount: USD 100.00 (scale 2)
- Tolerance: 0.05 - 0.10
- Hop limits: 1-3
- topK: 5

Code:
[minimal example here]

Expected: At least one path
Actual: Empty result set

Guard Report:
- Expansions: 12 / 5000
- Visited states: 8 / 10000
- Time: 5ms / 3000ms
```

### Resources

- **GitHub Issues**: https://github.com/somework/p2p-path-finder/issues
- **Documentation**: https://github.com/somework/p2p-path-finder/tree/main/docs
- **Examples**: https://github.com/somework/p2p-path-finder/tree/main/examples

---

## Quick Reference

### Common Fixes Checklist

- [ ] Order book contains orders for your currency pair
- [ ] Spend amount is within order bounds
- [ ] Tolerance window is wide enough (try 0.0 - 0.20)
- [ ] Max hops is ≥ 3
- [ ] Currency codes are valid (3 letters, A-Z)
- [ ] Money amounts are non-negative
- [ ] Scales are reasonable (0-30)
- [ ] Guard limits are not too restrictive
- [ ] topK is reasonable (10-20)

### Performance Tuning Checklist

- [ ] Pre-filter order book before searching
- [ ] Use reasonable topK (10-20)
- [ ] Set guard rails (expansion limit, time budget)
- [ ] Narrow tolerance window if possible
- [ ] Reduce max hops if possible
- [ ] Cache fee policy calculations
- [ ] Increase PHP memory limit if needed

---

**Next Steps**: 
- [Getting Started Guide](getting-started.md) - Step-by-step tutorial
- [Exception Handling Guide](exceptions.md) - Complete error reference
- [Domain Invariants](domain-invariants.md) - Validation rules reference

