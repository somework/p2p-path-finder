# Troubleshooting Guide

Quick solutions to common issues when using the P2P Path Finder library.

## Table of Contents

- [Quick Diagnosis](#quick-diagnosis)
- [Common Issues](#common-issues)
- [Performance Issues](#performance-issues)
- [Debug Checklist](#debug-checklist)
- [Getting Help](#getting-help)

---

## Quick Diagnosis

| Symptom                      | Most Likely Cause                 | Quick Fix                                  |
|------------------------------|-----------------------------------|--------------------------------------------|
| **Empty paths**              | No connecting orders              | Check order book connectivity              |
| **Guard limits hit**         | Large order book or deep graph    | Increase guard limits or pre-filter orders |
| **`InvalidInput` exception** | Invalid config/data               | Check error message, validate inputs       |
| **Slow searches**            | Too many orders or high hop depth | Pre-filter orders, reduce hop limit        |
| **`PrecisionViolation`**     | Extreme scale differences         | Use reasonable scales (0-30)               |
| **Currency mismatch**        | Wrong currency codes              | Verify 3-letter ISO codes                  |
| **Out of memory**            | Guard limits too high             | Reduce `maxVisitedStates`                  |

---

## Common Issues

### Issue 1: No Paths Found

**Symptom**: `$outcome->paths()->isEmpty()` returns `true`

**Diagnostic Steps**:
```php
$outcome = $service->findBestPlans($request);
$report = $outcome->guardLimits();

echo "Expansions: {$report->expansions()}\n";
echo "Visited states: {$report->visitedStates()}\n";
```

**Interpretation**:

| Expansions | Diagnosis                         | Solution                                 |
|------------|-----------------------------------|------------------------------------------|
| < 10       | Order book issue                  | Check if orders connect source to target |
| 10-100     | Limited connectivity              | Increase hop limit or add bridge orders  |
| > 100      | Search explored but found nothing | Widen tolerance or check amount bounds   |

**Solutions**:

1. **Check order book connectivity**:
    ```php
    // Count orders involving your currencies
    $relevantOrders = $orderBook->filter(
        new CurrencyPairFilter(['USD', 'BTC', 'EUR'])
    );
    echo "Relevant orders: " . count(iterator_to_array($relevantOrders)) . "\n";
    ```

2. **Widen tolerance window**:
    ```php
    ->withToleranceBounds('0.0', '0.20')  // 0-20% tolerance
    ```

3. **Increase hop limit**:
    ```php
    ->withHopLimits(1, 5)  // Allow up to 5 hops
    ```

4. **Check amount bounds**:
    ```php
    // Ensure spend amount is within order bounds
    $amount = Money::fromString('USD', '100.00', 2);
    $minFilter = new MinimumAmountFilter($amount->multipliedBy('0.1'));
    $maxFilter = new MaximumAmountFilter($amount->multipliedBy('10.0'));
    $viable = $orderBook->filter($minFilter, $maxFilter);
    ```

---

### Issue 2: Guard Limits Hit

**Symptom**: `$outcome->guardLimits()->anyLimitReached()` returns `true`

**Diagnostic**:
```php
$report = $outcome->guardLimits();
echo "Expansions: {$report->expansions()} / {$report->expansionLimit()}\n";
echo "States: {$report->visitedStates()} / {$report->visitedStateLimit()}\n";
echo "Time: {$report->elapsedMilliseconds()}ms\n";
```

**Solutions**:

| If Hitting...            | Solution                                          |
|--------------------------|---------------------------------------------------|
| **Expansion limit**      | Increase `maxExpansions` or pre-filter order book |
| **Visited states limit** | Increase `maxVisitedStates` or reduce hop depth   |
| **Time budget**          | Increase time budget or optimize search space     |

**Quick fixes**:

```php
// Option 1: Increase limits
->withSearchGuards(100000, 150000, 500)  // states, expansions, time(ms)

// Option 2: Pre-filter order book
$filtered = $orderBook->filter(
    new MinimumAmountFilter($minAmount),
    new ToleranceWindowFilter($config)
);
$request = new PathSearchRequest($filtered, $config, $target);

// Option 3: Reduce hop depth
->withHopLimits(1, 4)  // Instead of 1, 6
```

**Trade-offs**:
- **Higher limits** = More complete results, but slower and more memory
- **Pre-filtering** = Faster, less memory, but may miss some paths
- **Lower hops** = Much faster, but may miss multi-hop opportunities

---

### Issue 3: InvalidInput Exception

**Symptom**: Exception thrown during configuration or execution

**Common causes**:

| Error Message Contains | Cause                 | Fix                                        |
|------------------------|-----------------------|--------------------------------------------|
| "negative"             | Negative amount       | Use positive amounts only                  |
| "scale"                | Invalid scale         | Use scale 0-30                             |
| "currency"             | Invalid currency code | Use 3-12 letter codes (e.g., "USD", "BTC") |
| "hops"                 | min > max hops        | Ensure `minHops ≤ maxHops`                 |
| "tolerance"            | min > max tolerance   | Ensure `minTolerance ≤ maxTolerance`       |
| "distinct currencies"  | Same base and quote   | Use different currencies                   |

**Prevention**:
```php
// Validate before library calls
if ($amount < 0) {
    throw new \InvalidArgumentException("Amount must be positive");
}

if ($minHops > $maxHops) {
    throw new \InvalidArgumentException("Invalid hop range");
}

// Then build config safely
$config = PathSearchConfig::builder()
    ->withSpendAmount(Money::fromString($currency, $amount, $scale))
    ->withHopLimits($minHops, $maxHops)
    ->build();
```

---

### Issue 4: Slow Performance

**Symptom**: Searches take > 500ms or use excessive memory

**Diagnostic**:
```php
$start = microtime(true);
$outcome = $service->findBestPlans($request);
$elapsed = (microtime(true) - $start) * 1000;

echo "Time: {$elapsed}ms\n";
echo "Memory: " . (memory_get_peak_usage(true) / 1024 / 1024) . "MB\n";
echo "Expansions: {$outcome->guardLimits()->expansions()}\n";
```

**Solutions by order book size**:

| Order Count  | Expected Time | If Slower, Try...                          |
|--------------|---------------|--------------------------------------------|
| < 1,000      | < 100ms       | Pre-filter by amount/currency              |
| 1,000-10,000 | 100-500ms     | Reduce hop limit to 4                      |
| 10,000+      | 500-2000ms    | Aggressive filtering + conservative guards |

**Performance tuning**:

```php
// 1. Pre-filter aggressively
$filtered = $orderBook->filter(
    new MinimumAmountFilter($amount->multipliedBy('0.5')),
    new MaximumAmountFilter($amount->multipliedBy('2.0')),
    new CurrencyPairFilter($relevantCurrencies)
);
// Expected: 60-80% reduction

// 2. Use conservative guards
->withSearchGuards(25000, 50000)  // visited, expansions
->withSearchTimeBudget(200)       // 200ms max

// 3. Limit hop depth
->withHopLimits(1, 4)  // Each hop increases complexity exponentially

// 4. Reduce result limit
->withResultLimit(5)  // Instead of 20
```

See [Memory Characteristics](memory-characteristics.md) for detailed tuning.

---

### Issue 5: Out of Memory

**Symptom**: PHP fatal error: "Allowed memory size exhausted"

**Immediate fix**:
```php
// Reduce guard limits by 50%
$currentStates = 250000;
$newStates = 125000;

->withSearchGuards($newStates, $newStates * 2)
```

**Long-term solutions**:

1. **Increase PHP memory limit** (in `php.ini`):
```ini
memory_limit = 512M  ; or higher
```

2. **Pre-filter order book**:
```php
// Remove 70% of orders
$filtered = $orderBook->filter(
    new MinimumAmountFilter($minAmount),
    new CurrencyPairFilter($targetCurrencies)
);
```

3. **Use time budget as safety net**:
```php
->withSearchTimeBudget(200)  // Halt after 200ms
```

**Memory by guard limit**:

| Max Visited States | Expected Memory | Recommended PHP Limit |
|--------------------|-----------------|-----------------------|
| 10,000             | 10-30 MB        | 128M                  |
| 50,000             | 50-150 MB       | 256M                  |
| 100,000            | 100-300 MB      | 512M                  |
| 250,000            | 250-750 MB      | 1G                    |

---

### Issue 6: Currency Mismatch

**Symptom**: `InvalidInput: Currency code must be 3-12 uppercase letters`

**Common mistakes**:
```php
// ❌ Wrong
Money::fromString('US', '100.00', 2);     // Too short
Money::fromString('us', '100.00', 2);     // Lowercase
Money::fromString('$', '100.00', 2);      // Symbol

// ✅ Correct
Money::fromString('USD', '100.00', 2);
Money::fromString('EUR', '100.00', 2);
Money::fromString('BTC', '0.001', 8);
```

**Solution**: Always use ISO 4217 currency codes (3 letters, uppercase).

---

### Issue 7: Precision Errors

**Symptom**: `PrecisionViolation` exception (rare)

**Causes**:
- Scale > 30 (exceeds library limit)
- Extreme value differences (e.g., mixing scale 2 and scale 30)
- Division by near-zero values

**Solutions**:
```php
// Use reasonable scales
Money::fromString('USD', '100.00', 2);     // ✅ Good
Money::fromString('BTC', '0.12345678', 8); // ✅ Good
Money::fromString('ETH', '1.23456789012345', 35); // ❌ Too high

// Avoid extreme ratios
ExchangeRate::fromString('BTC', 'SAT', '100000000', 8); // ✅ Reasonable
ExchangeRate::fromString('BTC', 'SAT', '100000000', 50); // ❌ Excessive
```

---

## Performance Issues

### Slow Searches

**Target benchmarks**:
- 100 orders: < 50ms
- 1,000 orders: < 200ms
- 10,000 orders: < 2000ms

**If slower**:

1. **Profile the search**:
```php
$report = $outcome->guardLimits();
$efficiency = $report->expansions() / max(1, count($orderBook));
// < 10: Efficient
// 10-50: Normal
// > 50: Inefficient (pre-filter recommended)
```

2. **Apply appropriate strategy** (see Issue 4 above)

### High Memory Usage

**Target memory**:
- 100 orders: < 20 MB
- 1,000 orders: < 50 MB
- 10,000 orders: < 200 MB

**If higher**, see Issue 5 above.

---

## Debug Checklist

When debugging issues, check these in order:

### 1. Verify Input Data
- [ ] All currency codes are valid (3-12 uppercase letters)
- [ ] All amounts are positive
- [ ] All scales are 0-30
- [ ] Order bounds: min ≤ max

### 2. Check Configuration
- [ ] Tolerance bounds: 0 ≤ min ≤ max < 1
- [ ] Hop limits: 1 ≤ min ≤ max ≤ 10
- [ ] Result limit: ≥ 1
- [ ] Guard limits: reasonable for order book size

### 3. Inspect Order Book
```php
echo "Total orders: " . count($orderBook) . "\n";

// Check connectivity
$currencies = [];
foreach ($orderBook as $order) {
    $currencies[] = $order->assetPair()->base();
    $currencies[] = $order->assetPair()->quote();
}
$uniqueCurrencies = array_unique($currencies);
echo "Unique currencies: " . count($uniqueCurrencies) . "\n";
echo "Currencies: " . implode(', ', $uniqueCurrencies) . "\n";
```

### 4. Enable Verbose Logging
```php
$outcome = $service->findBestPlans($request);
$report = $outcome->guardLimits();

error_log(json_encode([
    'paths_found' => $outcome->paths()->count(),
    'expansions' => $report->expansions(),
    'visited_states' => $report->visitedStates(),
    'elapsed_ms' => $report->elapsedMilliseconds(),
    'limits_reached' => $report->anyLimitReached(),
    'order_count' => count($orderBook),
]));
```

### 5. Test with Minimal Example
```php
// Simplest possible search
$order = new Order(
    OrderSide::BUY,
    AssetPair::fromString('BTC', 'USD'),
    OrderBounds::from(
        Money::fromString('BTC', '0.01', 8),
        Money::fromString('BTC', '1.0', 8)
    ),
    ExchangeRate::fromString('BTC', 'USD', '30000', 2)
);

$orderBook = new OrderBook([$order]);

$config = PathSearchConfig::builder()
    ->withSpendAmount(Money::fromString('USD', '1000.00', 2))
    ->withToleranceBounds('0.0', '0.1')
    ->withHopLimits(1, 1)
    ->build();

$request = new PathSearchRequest($orderBook, $config, 'BTC');
$outcome = $service->findBestPlans($request);

// Should find exactly 1 path
assert($outcome->paths()->count() === 1);
```

---

## Getting Help

### Before Asking for Help

1. Check this troubleshooting guide
2. Review [Getting Started Guide](getting-started.md)
3. Search [GitHub Issues](https://github.com/somework/p2p-path-finder/issues)
4. Try minimal example (above)

### When Reporting Issues

Include:
- **Library version**: `composer show somework/p2p-path-finder`
- **PHP version**: `php -v`
- **Minimal reproducible example**
- **Expected vs actual behavior**
- **Guard report**: `$outcome->guardLimits()->expansions()` and other methods
- **Error messages**: Full exception message and stack trace

**Example report**:
```
Library: 1.5.3
PHP: 8.3.0

Issue: No paths found despite having orders

Order book: 1,000 orders connecting USD->EUR->BTC
Config: 1-4 hops, 0-10% tolerance
Guard report: 234 expansions, 127 states, no limits hit

Expected: At least 1 path
Actual: Empty paths

Code: [minimal example]
```

### Support Channels

- **GitHub Issues**: Bug reports and feature requests
- **GitHub Discussions**: Questions and help
- **Documentation**: Comprehensive guides in `docs/`
- **Examples**: Working code in `examples/`

---

## Related Documentation

- [Exception Handling](exceptions.md) - Error handling patterns
- [Memory Characteristics](memory-characteristics.md) - Performance tuning
- [Getting Started](getting-started.md) - Basic usage
- [Domain Invariants](domain-invariants.md) - Validation rules

---

*Most issues can be resolved by checking input validation, adjusting guard limits, or pre-filtering the order book.*
