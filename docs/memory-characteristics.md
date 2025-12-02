# Memory Characteristics

## Overview

The path finder's memory footprint scales predictably with order book size, graph density, and search depth. This document provides practical guidance for capacity planning and memory optimization in production deployments.

### Per-Component Memory Footprint

Based on benchmark profiling with PHP 8.3:

- **Per-order memory:** ~5-8 KB (includes domain objects, exchange rates, bounds)
- **Per-graph-edge memory:** ~2-3 KB (graph representation with segments and capacity bounds)
- **Per-search-state memory:** ~0.8-1.2 KB (visited state tracking in registry)
- **Registry overhead:** ~1 KB per 10 unique visited states
- **Result materialization:** ~3-5 KB per path leg

### Baseline Memory Usage

From PhpBench k-best scenarios (PHP 8.3, Ubuntu 22.04):

| Order Book Size | Peak Memory | Orders per MB  | Notes                                                  |
|-----------------|-------------|----------------|--------------------------------------------------------|
| 100 orders      | 8.3 MB      | ~12 orders/MB  | Minimal overhead, mostly framework and graph structure |
| 1,000 orders    | 12.8 MB     | ~78 orders/MB  | Efficient scaling, cached value objects                |
| 10,000 orders   | 59.1 MB     | ~169 orders/MB | Linear scaling maintained, guard limits active         |

**Key insight:** Memory scales sub-linearly due to object pooling (zero `Money` cache in `GraphBuilder`), memoized exchange rates, and efficient search state representation.

## Scaling Factors

Memory usage follows these complexity patterns:

### 1. Order Book Size: O(N)

**Formula:** `BaseMemory + (N * OrderMemory)`

- **BaseMemory:** ~6-8 MB (framework, services, base structures)
- **OrderMemory:** ~5-8 KB per order (varies with scale precision and fee complexity)

**Example:**
- 100 orders: 8.3 MB ≈ 7 MB base + (100 × 0.013 MB)
- 1,000 orders: 12.8 MB ≈ 7 MB base + (1,000 × 0.0058 MB)
- 10,000 orders: 59.1 MB ≈ 7 MB base + (10,000 × 0.0052 MB)

### 2. Graph Density: O(N × M)

Where M = average edges per node (fan-out factor)

- **Low density** (M = 1-3): Minimal overhead, typical for liquid pairs
- **Medium density** (M = 4-10): Moderate increase, common in multi-hop scenarios
- **High density** (M > 20): Significant increase, use guard limits to cap

**Dense graph scenarios** (from benchmarks):
- `dense-4x4-hop-5`: 256 assets, fan-out 4 → managed with 20,000 state limit
- `dense-3x7-hop-6`: 343 assets, fan-out 3 → managed with 20,000 state limit

### 3. Search Depth: O(D × S)

Where D = hop depth, S = states per hop

- **States per hop:** Varies with graph density and tolerance window
- **Typical values:** 50-500 states per hop for moderate graphs
- **Dense graphs:** 1,000-5,000+ states per hop (guard limits essential)

**Search state memory:**
- Each state: ~1 KB (includes path history, cost, currency trail)
- Default `maxVisitedStates` = 250,000 → ~250 MB theoretical max
- Typical usage: 1,000-10,000 states → 1-10 MB

### 4. Result Storage: O(K × H)

Where K = resultLimit, H = average hops per path

- **Result limit impact:** Minimal (default K = 1-10)
- **Per-path overhead:** ~3-5 KB × hop count
- **Example:** 10 results × 3 hops × 4 KB ≈ 120 KB

## Practical Limits and Recommendations

### Recommended Limits

Based on production testing and benchmark validation:

| Parameter                | Conservative | Moderate  | Aggressive | Notes                           |
|--------------------------|--------------|-----------|------------|---------------------------------|
| **Max order book size**  | 5,000        | 20,000    | 50,000     | Orders in single search         |
| **Max hop depth**        | 4            | 6         | 8          | Balance accuracy vs. complexity |
| **Max visited states**   | 10,000       | 100,000   | 250,000    | Primary memory control          |
| **Max expansions**       | 25,000       | 100,000   | 250,000    | Computation control             |
| **Search time budget**   | 50 ms        | 500 ms    | 2,000 ms   | Wall-clock safety net           |
| **Expected peak memory** | 20-50 MB     | 50-200 MB | 200-500 MB | Total process memory            |

### Conservative Profile (Latency-sensitive APIs)

```php
$config = PathSearchConfig::builder()
    ->withSpendAmount($amount)
    ->withToleranceBounds('0.00', '0.05')
    ->withHopLimits(1, 4)
    ->withResultLimit(5)
    ->withSearchGuards(10000, 25000)  // visited states, expansions
    ->withSearchTimeBudget(50)         // milliseconds
    ->build();
```

**Expected memory:** 10-30 MB  
**Use case:** Public APIs, high-frequency trading, latency < 100ms

### Moderate Profile (Background processing)

```php
$config = PathSearchConfig::builder()
    ->withSpendAmount($amount)
    ->withToleranceBounds('0.00', '0.10')
    ->withHopLimits(1, 6)
    ->withResultLimit(10)
    ->withSearchGuards(100000, 100000)
    ->withSearchTimeBudget(500)
    ->build();
```

**Expected memory:** 30-150 MB  
**Use case:** Batch processing, analytics, tolerance for 500ms+ latency

### Aggressive Profile (Deep search, large books)

```php
$config = PathSearchConfig::builder()
    ->withSpendAmount($amount)
    ->withToleranceBounds('0.00', '0.20')
    ->withHopLimits(1, 8)
    ->withResultLimit(20)
    ->withSearchGuards(250000, 250000)
    ->withSearchTimeBudget(2000)
    ->build();
```

**Expected memory:** 100-500 MB  
**Use case:** Research, optimal path discovery, offline analysis

## Order Book Optimization Strategies

Optimize your order book **before** the search to reduce memory at the source. This is the most effective optimization strategy.

### Strategy 1: Pre-filter by Amount Range

Filter out orders that cannot possibly satisfy the requested spend amount:

```php
use SomeWork\P2PPathFinder\Application\Order\Filter\MaximumAmountFilter;use SomeWork\P2PPathFinder\Application\Order\Filter\MinimumAmountFilter;use SomeWork\P2PPathFinder\Domain\Money\Money;

$spendAmount = Money::fromString('USD', '1000.00', 2);

// Remove orders with bounds below our minimum viable amount
$minFilter = new MinimumAmountFilter(
    $spendAmount->multipliedBy('0.1') // 10% of spend as minimum
);

// Remove orders with bounds above our maximum realistic amount
$maxFilter = new MaximumAmountFilter(
    $spendAmount->multipliedBy('10.0') // 10x spend as maximum
);

$filtered = $orderBook->filter($minFilter, $maxFilter);
```

**Impact:** 40-60% reduction for typical order books  
**When to use:** Always, unless you need all possible paths regardless of amount

### Strategy 2: Pre-filter by Tolerance Window

Remove orders with rates outside the acceptable tolerance:

```php
use SomeWork\P2PPathFinder\Application\Order\Filter\ToleranceWindowFilter;

$config = PathSearchConfig::builder()
    ->withSpendAmount($amount)
    ->withToleranceBounds('0.00', '0.05') // 0-5% tolerance
    ->build();

$filter = new ToleranceWindowFilter($config);
$filtered = $orderBook->filter($filter);
```

**Impact:** 20-40% reduction depending on tolerance window  
**When to use:** When you have a narrow tolerance window (< 10%)

### Strategy 3: Pre-filter by Currency Pair

Focus on relevant currency pairs for your search:

```php
use SomeWork\P2PPathFinder\Application\Order\Filter\CurrencyPairFilter;

// Only include orders involving USD, EUR, or BTC
$filter = new CurrencyPairFilter(['USD', 'EUR', 'BTC']);
$filtered = $orderBook->filter($filter);
```

**Impact:** 50-90% reduction for large, diverse order books  
**When to use:** When you know which currencies are relevant for your path

### Strategy 4: Combine Multiple Filters

Chain filters for maximum memory reduction:

```php
$filtered = $orderBook->filter(
    new MinimumAmountFilter($minAmount),
    new MaximumAmountFilter($maxAmount),
    new ToleranceWindowFilter($config),
    new CurrencyPairFilter(['USD', 'EUR', 'BTC'])
);

// Typical combined reduction: 60-85%
```

**Impact:** 60-85% reduction for well-targeted searches  
**Trade-off:** May miss some alternative paths, but primary paths remain

### Strategy 5: Remove Stale or Inactive Orders

Remove orders that are no longer valid:

```php
// Custom filter for active orders only
class ActiveOrdersFilter implements OrderFilterInterface
{
    public function accepts(Order $order): bool
    {
        // Your business logic (e.g., check timestamp, status, etc.)
        return $order->isActive() && !$order->isExpired();
    }
}

$filtered = $orderBook->filter(new ActiveOrdersFilter());
```

**Impact:** Varies (10-50% depending on order freshness)  
**When to use:** Production systems with order expiration or status tracking

## Filter Strategy Decision Guide

Use this guide to choose the right filtering strategy:

### For Latency-Sensitive APIs (< 100ms target)

```php
// Aggressive filtering for speed
$filtered = $orderBook->filter(
    new MinimumAmountFilter($amount->multipliedBy('0.5')), // 50% of spend
    new MaximumAmountFilter($amount->multipliedBy('2.0')), // 2x spend
    new ToleranceWindowFilter($config),
    new CurrencyPairFilter($relevantCurrencies) // Only relevant pairs
);

// Expected: 70-90% reduction, minimal impact on primary paths
```

### For Balanced Use Cases (100-500ms acceptable)

```php
// Moderate filtering for balance
$filtered = $orderBook->filter(
    new MinimumAmountFilter($amount->multipliedBy('0.1')), // 10% of spend
    new MaximumAmountFilter($amount->multipliedBy('10.0')), // 10x spend
    new ToleranceWindowFilter($config)
);

// Expected: 50-70% reduction, preserves alternative paths
```

### For Comprehensive Search (500ms+ acceptable)

```php
// Minimal filtering for completeness
$filtered = $orderBook->filter(
    new MinimumAmountFilter($amount->multipliedBy('0.01')), // 1% of spend
    new MaximumAmountFilter($amount->multipliedBy('100.0')) // 100x spend
);

// Expected: 30-50% reduction, maximum path coverage
```

### For Research/Analytics (No time constraints)

```php
// No filtering, full order book
$service->findBestPaths(new PathSearchRequest($orderBook, $config, $target));

// Expected: 0% reduction, complete path discovery
```

## Memory Optimization Strategies

### 1. Pre-filter Order Book (Primary Strategy)

See "Order Book Optimization Strategies" section above.

**Impact:** 30-85% memory reduction for typical scenarios

### 2. Tune Guard Limits (Secondary Strategy)

Use the minimum viable limits for your use case. See "Guard Limit Decision Tree" section below for a systematic approach.

**Quick guide:**

- **Start conservative:** 10,000 visited states, 25,000 expansions
- **Monitor guard reports:** Check `SearchGuardReport::metrics()`
- **Increase incrementally:** Only if searches hit limits frequently

**Example guard tuning:**

```php
// Monitor actual usage
$outcome = $service->findBestPaths($request);
$metrics = $outcome->guardLimits()->metrics();

if ($metrics['visited_states'] > 0.8 * $config->searchGuards()->maxVisitedStates()) {
    // Consider increasing limit if results are incomplete
}
```

See "Guard Limit Decision Tree" below for systematic tuning.

### 3. Limit Result Count

Keep `resultLimit` low unless you need many alternatives:

- **resultLimit = 1:** Fastest, lowest memory (single best path)
- **resultLimit = 5:** Good balance (top 5 paths)
- **resultLimit = 20+:** Higher overhead, use only when needed

### 4. Reduce Hop Depth

Each additional hop increases search space exponentially:

- **1-3 hops:** Most direct paths, minimal memory
- **4-6 hops:** Comprehensive coverage, moderate memory
- **7+ hops:** Rarely yields better results, expensive

### 5. Use Time Budgets

Wall-clock budgets prevent runaway memory growth:

```php
->withSearchTimeBudget(50)  // Halt after 50ms regardless of state count
```

**Benefit:** Guarantees bounded latency and memory usage

## Guard Limit Decision Tree

Use this systematic approach to choose appropriate guard limits for your use case.

### Step 1: Identify Your Use Case

Choose the category that best matches your requirements:

| Use Case                  | Latency Target | Memory Budget        | Completeness Priority  |
|---------------------------|----------------|----------------------|------------------------|
| **A. Real-time API**      | < 50ms         | Low (20-50 MB)       | Medium (best effort)   |
| **B. User-facing UI**     | < 200ms        | Moderate (50-150 MB) | High (comprehensive)   |
| **C. Background jobs**    | < 1000ms       | High (150-300 MB)    | Very High (exhaustive) |
| **D. Analytics/Research** | No limit       | Very High (300+ MB)  | Maximum (complete)     |

### Step 2: Choose Base Guard Limits

Based on your use case from Step 1:

#### Use Case A: Real-time API

```php
$config = PathSearchConfig::builder()
    ->withSearchGuards(5000, 10000)    // visited, expansions
    ->withSearchTimeBudget(25)         // 25ms safety net
    ->withHopLimits(1, 3)              // Limit search depth
    ->withResultLimit(1)               // Single best path
    ->build();
```

**Expected:** 10-30 MB peak, 15-40ms latency  
**Trade-off:** May miss some alternative paths, prioritizes speed

#### Use Case B: User-facing UI

```php
$config = PathSearchConfig::builder()
    ->withSearchGuards(25000, 50000)
    ->withSearchTimeBudget(150)
    ->withHopLimits(1, 4)
    ->withResultLimit(5)
    ->build();
```

**Expected:** 30-100 MB peak, 50-150ms latency  
**Trade-off:** Good balance of coverage and responsiveness

#### Use Case C: Background Jobs

```php
$config = PathSearchConfig::builder()
    ->withSearchGuards(100000, 150000)
    ->withSearchTimeBudget(800)
    ->withHopLimits(1, 6)
    ->withResultLimit(10)
    ->build();
```

**Expected:** 100-250 MB peak, 200-800ms latency  
**Trade-off:** Comprehensive search, suitable for batch processing

#### Use Case D: Analytics/Research

```php
$config = PathSearchConfig::builder()
    ->withSearchGuards(250000, 250000)
    ->withSearchTimeBudget(5000)
    ->withHopLimits(1, 8)
    ->withResultLimit(100)
    ->build();
```

**Expected:** 200-500 MB peak, 1-5 seconds latency  
**Trade-off:** Maximum coverage, only for offline analysis

### Step 3: Adjust for Order Book Size

Multiply the base limits from Step 2 by the order book size factor:

| Order Book Size     | Multiply Limits By | Memory Adjustment    |
|---------------------|--------------------|----------------------|
| < 100 orders        | 0.5×               | -50% (smaller graph) |
| 100-500 orders      | 1.0×               | No change (baseline) |
| 500-2,000 orders    | 1.5×               | +50% (medium graph)  |
| 2,000-10,000 orders | 2.5×               | +150% (large graph)  |
| 10,000+ orders      | 4.0×               | +300% (very large)   |

**Example:**
```php
// Use Case B (User-facing UI) with 5,000 orders
// Base: 25,000 visited states, 50,000 expansions
// Adjustment: 2.5× (2,000-10,000 range)
// Final: 62,500 visited states, 125,000 expansions

$config = PathSearchConfig::builder()
    ->withSearchGuards(62500, 125000)
    ->withSearchTimeBudget(150)
    ->build();
```

### Step 4: Adjust for Graph Density

If your order book has high currency pair diversity (dense graph), apply an additional multiplier:

| Graph Density  | Avg. Edges per Node      | Additional Multiplier |
|----------------|--------------------------|-----------------------|
| **Sparse**     | 1-3 (few currency pairs) | 1.0× (no change)      |
| **Moderate**   | 4-10 (typical multi-hop) | 1.5× (+50%)           |
| **Dense**      | 11-20 (high diversity)   | 2.5× (+150%)          |
| **Very Dense** | 20+ (complete graph)     | 4.0× (+300%)          |

**How to estimate edges per node:**
- Count unique currency pairs in your order book
- Divide by number of unique currencies
- Typical: 4-8 edges per node

**Example:**
```php
// Continuing from Step 3: 62,500 visited, 125,000 expansions
// Graph is Dense (15 edges per node average)
// Additional multiplier: 2.5×
// Final: 156,250 visited states, 312,500 expansions

$config = PathSearchConfig::builder()
    ->withSearchGuards(156250, 312500)
    ->withSearchTimeBudget(150)
    ->build();
```

### Step 5: Monitor and Tune

After deploying with your calculated limits, monitor these metrics:

```php
$outcome = $service->findBestPaths($request);
$report = $outcome->guardLimits();
$metrics = $report->expansions(); // Access metrics directly

// Calculate utilization percentages
$visitedUtilization = $metrics['visited_states'] / $config->searchGuards()->maxVisitedStates();
$expansionUtilization = $metrics['expansions'] / $config->searchGuards()->maxExpansions();
$timeUtilization = $metrics['elapsed_ms'] / $config->searchGuards()->timeBudgetMs();
```

**Tuning guide:**

| Utilization | Action                  | Reason                              |
|-------------|-------------------------|-------------------------------------|
| **< 20%**   | Reduce limits by 50%    | Over-provisioned, wasting resources |
| **20-50%**  | Reduce limits by 25%    | Slightly over-provisioned           |
| **50-80%**  | ✅ **Optimal**           | Well-tuned configuration            |
| **80-95%**  | Increase limits by 50%  | Near saturation, may miss paths     |
| **> 95%**   | Increase limits by 100% | Hitting limits frequently           |

**Example tuning iteration:**
```php
// Initial config: 62,500 visited states
// Observed: 55,000 visited states (88% utilization)
// Action: Increase by 50%
// New config: 93,750 visited states

$config = PathSearchConfig::builder()
    ->withSearchGuards(93750, 187500) // Increased
    ->build();
```

### Quick Decision Flowchart

```text
START
  │
  ├─ What's your latency target?
  │  ├─ < 50ms    → Use Case A (Real-time API)
  │  ├─ < 200ms   → Use Case B (User-facing UI)
  │  ├─ < 1000ms  → Use Case C (Background jobs)
  │  └─ No limit  → Use Case D (Analytics)
  │
  ├─ How many orders?
  │  ├─ < 100       → Multiply by 0.5×
  │  ├─ 100-500     → Multiply by 1.0×
  │  ├─ 500-2,000   → Multiply by 1.5×
  │  ├─ 2,000-10k   → Multiply by 2.5×
  │  └─ 10,000+     → Multiply by 4.0×
  │
  ├─ How dense is your graph?
  │  ├─ Sparse (1-3 edges/node)   → Multiply by 1.0×
  │  ├─ Moderate (4-10)            → Multiply by 1.5×
  │  ├─ Dense (11-20)              → Multiply by 2.5×
  │  └─ Very Dense (20+)           → Multiply by 4.0×
  │
  ├─ Deploy with calculated limits
  │
  ├─ Monitor utilization
  │  ├─ < 20%  → Reduce limits by 50%
  │  ├─ 20-50% → Reduce limits by 25%
  │  ├─ 50-80% → ✅ Optimal, no change
  │  ├─ 80-95% → Increase limits by 50%
  │  └─ > 95%  → Increase limits by 100%
  │
  └─ Repeat monitoring cycle
```

### Common Scenarios

#### Scenario 1: High-Frequency Trading API

- **Requirements:** < 30ms latency, 500 orders, sparse graph
- **Calculation:** Use Case A × 1.0× (500 orders) × 1.0× (sparse)
- **Config:** 5,000 visited, 10,000 expansions, 25ms budget

```php
->withSearchGuards(5000, 10000)->withSearchTimeBudget(25)
```

#### Scenario 2: E-commerce Currency Converter

- **Requirements:** < 150ms latency, 2,000 orders, moderate graph
- **Calculation:** Use Case B × 1.5× (2,000 orders) × 1.5× (moderate)
- **Config:** 56,250 visited, 112,500 expansions, 150ms budget

```php
->withSearchGuards(56250, 112500)->withSearchTimeBudget(150)
```

#### Scenario 3: Crypto Arbitrage Scanner

- **Requirements:** < 500ms latency, 10,000 orders, dense graph
- **Calculation:** Use Case C × 4.0× (10k orders) × 2.5× (dense)
- **Config:** 1,000,000 visited, 1,500,000 expansions, 800ms budget

```php
->withSearchGuards(1000000, 1500000)->withSearchTimeBudget(800)
```

**Note:** This is an extreme case. Consider aggressive pre-filtering instead:

```php
// Better approach: Filter first, then use moderate limits
$filtered = $orderBook->filter(
    new MinimumAmountFilter($amount->multipliedBy('0.5')),
    new CurrencyPairFilter($targetCurrencies)
);
// Reduces to ~2,000 orders → use 2.5× multiplier instead of 4.0×
```

#### Scenario 4: Overnight Batch Analytics

- **Requirements:** No time limit, 50,000 orders, very dense graph
- **Calculation:** Use Case D × 4.0× (50k orders) × 4.0× (very dense)
- **Config:** 4,000,000 visited, 4,000,000 expansions, 10,000ms budget

```php
->withSearchGuards(4000000, 4000000)->withSearchTimeBudget(10000)
```

**Warning:** This may consume 1-2 GB of memory. Use PHP's memory_limit accordingly:
```ini
memory_limit = 2048M
```

## Memory vs. Performance Trade-offs

### Higher Guard Limits

**✅ Pros:**
- More complete path coverage
- Better results for complex graphs
- Fewer incomplete searches

**❌ Cons:**
- Higher peak memory
- Longer search times
- Risk of OOM on adversarial inputs

### Lower Guard Limits

**✅ Pros:**
- Bounded memory usage
- Predictable latency
- Safe for production APIs

**❌ Cons:**
- May miss optimal paths
- Guard breaches more frequent
- Requires careful tuning

### Optimal Balance

For most production use cases:

```php
->withSearchGuards(50000, 100000)  // 50k states, 100k expansions
->withSearchTimeBudget(200)        // 200ms cap
```

**Expected:** ~50-150 MB peak memory, < 200ms latency for 1,000-5,000 order books

## Monitoring and Diagnostics

### Check Guard Metrics

```php
$outcome = $service->findBestPaths($request);
$report = $outcome->guardLimits();

$metrics = [
    'expansions' => $report->expansions(),
    'visited_states' => $report->visitedStates(),
    'elapsed_ms' => $report->elapsedMilliseconds(),
];
// {
//   "limits": {
//     "expansions": 100000,
//     "visited_states": 50000,
//     "time_budget_ms": 200
//   },
//   "metrics": {
//     "expansions": 8432,
//     "visited_states": 3891,
//     "elapsed_ms": 47.3
//   },
//   "breached": {
//     "expansions": false,
//     "visited_states": false,
//     "time_budget": false,
//     "any": false
//   }
// }
```

### Interpret Memory Pressure

**Low utilization** (< 20% of limits):
- Limits are too high for workload
- Consider lowering to improve latency
- More predictable resource usage

**Moderate utilization** (20-80% of limits):
- Well-tuned configuration
- Good balance of coverage and performance
- Monitor for occasional breaches

**High utilization** (> 80% of limits):
- Hitting limits frequently
- Results may be incomplete
- Consider increasing limits or filtering orders

### Production Monitoring

Key metrics to track:

1. **Peak memory per request** (`memory_get_peak_usage()`)
2. **Guard breach rate** (`$report->anyLimitReached()`)
3. **Search completion rate** (`$outcome->hasPaths()`)
4. **Average visited states** (`$metrics['visited_states']`)
5. **95th percentile latency** (external monitoring)

## When OOM Occurs

### Prevention

1. **Set PHP memory_limit:** Allow 2-3× expected peak
   ```ini
   memory_limit = 512M  ; For moderate workloads
   ```

2. **Use guard limits:** Always configure `withSearchGuards()`

3. **Use time budgets:** Always configure `withSearchTimeBudget()`

4. **Filter aggressively:** Pre-filter order books to relevant liquidity

### Detection

The library does not catch OOM errors (they're fatal). Instead:

- **Monitor PHP logs** for OOM messages
- **Track memory_get_peak_usage()** after searches
- **Set conservative limits** until profiling is complete

### Recovery

If OOM occurs in production:

1. **Immediate:** Reduce guard limits by 50%
2. **Short-term:** Add more aggressive order filtering
3. **Long-term:** Increase server memory or optimize query patterns

## Benchmark Data Reference

Full benchmark data from PhpBench (PHP 8.3, Ubuntu 22.04, Xeon vCPU):

| Scenario    | Orders | Mean Time  | Peak Memory | Visited States | Expansions     |
|-------------|--------|------------|-------------|----------------|----------------|
| k-best-n1e2 | 100    | 25.5 ms    | 8.3 MB      | ~500-1,000     | ~1,500-3,000   |
| k-best-n1e3 | 1,000  | 216.3 ms   | 12.8 MB     | ~2,000-5,000   | ~8,000-15,000  |
| k-best-n1e4 | 10,000 | 2,154.7 ms | 59.1 MB     | ~10,000-20,000 | ~40,000-80,000 |

**Notes:**
- All scenarios use default guard limits (250,000 visited states, 250,000 expansions)
- Memory includes full PHP runtime, framework, and search state
- Actual visited states and expansions vary by graph structure
- Results are for k=16 (finding top 16 paths)

## Summary

**Key Takeaways:**

1. **Memory scales predictably:** ~5-10 KB per order, ~1 KB per search state
2. **Guard limits are essential:** Primary control for memory and latency
3. **Pre-filtering is effective:** 30-70% memory reduction possible
4. **Conservative defaults work:** 10k-50k states handle most production use cases
5. **Monitor metrics:** Use `SearchGuardReport` to tune configuration
6. **Plan for 2-3× peak:** Allow headroom in PHP memory_limit

**Quick Reference:**

- **Small book (< 1,000 orders):** 10-30 MB expected
- **Medium book (1,000-10,000 orders):** 30-150 MB expected  
- **Large book (10,000-50,000 orders):** 150-500 MB expected

## PHP Memory Limits

### Recommended memory_limit Configuration

Set PHP `memory_limit` based on your workload:

| Workload                   | Expected Peak | Recommended memory_limit | Safety Factor |
|----------------------------|---------------|--------------------------|---------------|
| **Small** (< 1,000 orders) | 10-30 MB      | 128M                     | 4-12×         |
| **Medium** (1,000-10,000)  | 30-150 MB     | 256M-512M                | 3-8×          |
| **Large** (10,000-50,000)  | 150-500 MB    | 1G-2G                    | 2-4×          |
| **Dense graphs**           | 200-600 MB    | 1G-2G                    | 2-5×          |

**Safety factor:** Allow 2-4× expected peak to handle variance and prevent OOM errors.

### Memory vs. Guard Limits

The primary memory control mechanism is `maxVisitedStates`:

| Guard Limit              | Typical Peak Memory | Use Case                        |
|--------------------------|---------------------|---------------------------------|
| 10,000 states            | +10-15 MB           | Conservative, latency-sensitive |
| 50,000 states            | +50-75 MB           | Moderate, balanced              |
| 100,000 states           | +100-150 MB         | Comprehensive search            |
| 250,000 states (default) | +250-375 MB         | Maximum coverage                |

**Note:** Actual memory overhead includes base memory (~8 MB) plus per-order overhead (~5-10 KB per order).

### Monitoring Memory in Production

Track these metrics to understand memory behavior:

```php
$startMemory = memory_get_usage(true);
$outcome = $service->findBestPaths($request);
$peakMemory = memory_get_peak_usage(true);
$report = $outcome->guardLimits();
$metrics = [
    'expansions' => $report->expansions(),
    'visited_states' => $report->visitedStates(),
    'elapsed_ms' => $report->elapsedMilliseconds(),
];

$memoryUsed = ($peakMemory - $startMemory) / 1024 / 1024; // MB
$memoryPerState = $metrics['visited_states'] > 0 
    ? $memoryUsed / $metrics['visited_states'] 
    : 0;

// Log for analysis
$log = [
    'memory_mb' => $memoryUsed,
    'memory_per_state_kb' => $memoryPerState * 1024,
    'visited_states' => $metrics['visited_states'],
    'order_count' => $orderBook->count(),
    'elapsed_ms' => $metrics['elapsed_ms'],
];
```

### Memory Optimization Checklist

If memory usage exceeds expectations:

1. ✅ **Pre-filter order book** with `MinimumAmountFilter`, `MaximumAmountFilter`, `ToleranceWindowFilter`
2. ✅ **Reduce `maxVisitedStates`** guard limit
3. ✅ **Lower `maxHops`** to 4-6 instead of 8
4. ✅ **Decrease `resultLimit`** to 1-5 instead of 10+
5. ✅ **Enable `withSearchTimeBudget()`** to halt runaway searches
6. ✅ **Monitor guard metrics** via `SearchGuardReport` to tune configuration

## Performance Characteristics

### Component Memory Breakdown

Based on profiling with PHP 8.3:

**Graph construction** (`GraphBuilder`):
- Allocation pattern: In-place mutation with zero-Money cache
- Memory share: ~10-20% of peak (depending on order count)
- Optimization: Reuses zero-value `Money` instances, reducing allocations by ~40%

**Search state tracking** (`SearchStateRegistry`):
- Allocation pattern: Hash-based registry for visited states
- Memory share: ~15-25% of peak (scales with hop depth and graph density)
- Typical size: 1,000-20,000 states for moderate graphs
- Per-state cost: ~1 KB (includes path history, cost, currency trail)

**Order book and domain objects**:
- Allocation pattern: Immutable value objects (Money, ExchangeRate, OrderBounds)
- Memory share: ~40-60% of peak
- Optimization: Memoized ExchangeRate instances shared across orders

**Result materialization** (`LegMaterializer`):
- Allocation pattern: Stack-allocated candidate DTOs, lazy finalization
- Memory share: ~5-10% of peak
- Optimization: Buffers reused across expansions, allocations deferred until tolerance passes

### Memory Growth Patterns

**Linear growth region** (< 10,000 orders):
- Predictable scaling: ~5-10 KB per additional order
- Dominated by domain object allocations
- Guard limits rarely engaged

**Sub-linear region** (10,000-50,000 orders):
- Improved efficiency from caching
- Search state tracking becomes significant
- Guard limits help cap growth

**Dense graph scenarios**:
- Memory dominated by search state (visited states × ~1 KB)
- Guard limits essential to prevent runaway growth
- Example: Dense 4×4 graph (hop-5) with 20,000 state limit → ~20-40 MB for search state alone

## Related Documentation

- [Architecture Guide](architecture.md) - System design and component interactions
- [Troubleshooting Guide](troubleshooting.md) - Common issues and solutions
- [Getting Started Guide](getting-started.md) - Quick start tutorial

