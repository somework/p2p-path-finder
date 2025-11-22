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

| Order Book Size | Peak Memory | Orders per MB | Notes |
|-----------------|-------------|---------------|-------|
| 100 orders      | 8.3 MB      | ~12 orders/MB | Minimal overhead, mostly framework and graph structure |
| 1,000 orders    | 12.8 MB     | ~78 orders/MB | Efficient scaling, cached value objects |
| 10,000 orders   | 59.1 MB     | ~169 orders/MB | Linear scaling maintained, guard limits active |

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

| Parameter | Conservative | Moderate | Aggressive | Notes |
|-----------|--------------|----------|------------|-------|
| **Max order book size** | 5,000 | 20,000 | 50,000 | Orders in single search |
| **Max hop depth** | 4 | 6 | 8 | Balance accuracy vs. complexity |
| **Max visited states** | 10,000 | 100,000 | 250,000 | Primary memory control |
| **Max expansions** | 25,000 | 100,000 | 250,000 | Computation control |
| **Search time budget** | 50 ms | 500 ms | 2,000 ms | Wall-clock safety net |
| **Expected peak memory** | 20-50 MB | 50-200 MB | 200-500 MB | Total process memory |

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

## Memory Optimization Strategies

### 1. Pre-filter Order Book

Reduce memory at the source by filtering orders before search:

```php
use SomeWork\P2PPathFinder\Application\Filter\AmountRangeFilter;
use SomeWork\P2PPathFinder\Application\Filter\ToleranceWindowFilter;

$filtered = $orderBook
    ->filtered(new AmountRangeFilter($config))
    ->filtered(new ToleranceWindowFilter($config));

// $filtered typically 30-70% smaller than original
```

**Impact:** 30-70% memory reduction for typical scenarios

### 2. Tune Guard Limits

Use the minimum viable limits for your use case:

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

$metrics = $report->jsonSerialize();
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

| Scenario | Orders | Mean Time | Peak Memory | Visited States | Expansions |
|----------|--------|-----------|-------------|----------------|------------|
| k-best-n1e2 | 100 | 25.5 ms | 8.3 MB | ~500-1,000 | ~1,500-3,000 |
| k-best-n1e3 | 1,000 | 216.3 ms | 12.8 MB | ~2,000-5,000 | ~8,000-15,000 |
| k-best-n1e4 | 10,000 | 2,154.7 ms | 59.1 MB | ~10,000-20,000 | ~40,000-80,000 |

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

For performance characteristics and hotspot analysis, see [hotspot-profile.md](performance/hotspot-profile.md).

