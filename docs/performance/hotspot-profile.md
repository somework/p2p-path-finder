# Hotspot profiling notes

## Methodology

### Prerequisites

* Ensure the Xdebug PHP extension is installed locally. We do not pin it via `composer.json`,
  so install it through your PHP runtime manager or package manager as needed.
* Use Xdebug **3.x** – legacy 2.x builds do not support the `xdebug.mode` flags required below.
* Create the profiler output directory ahead of time: `mkdir -p .xdebug`. Xdebug will not
  create it automatically, and runs will fail if the directory is missing. The folder is
  gitignored so that Cachegrind artefacts are not committed.

The baseline profile targets the PhpBench subject `PathFinderBench::benchFindBottleneckMandatoryMinima`
so that we exercise both the legacy bottleneck workload and the newer high-fan-out
scenario defined in [`benchmarks/PathFinderBench.php`](../../benchmarks/PathFinderBench.php). For each run we execute PhpBench
with the aggregate report and force Xdebug's profiler so that inclusive time and
allocation data is written into Cachegrind files under `.xdebug/`:

```bash
php -d xdebug.mode=profile \
    -d xdebug.start_with_request=yes \
    -d xdebug.output_dir=.xdebug \
    vendor/bin/phpbench run \
        --config=phpbench.json \
        --report=aggregate \
        --filter=benchFindBottleneckMandatoryMinima \
        --iterations=1 \
        --revs=1
```

The generated Cachegrind artefacts can be inspected with `callgrind_annotate` or a
small parser to surface the dominant functions. The profiling focuses on the legacy
`bottleneck-hop-3` dataset and the `bottleneck-high-fanout-hop-4` dataset so that the
critical queueing paths in [`src/Application/PathFinder/PathFinder.php`](../../src/Application/PathFinder/PathFinder.php#L727-L780) are
included alongside the materialisation and graph construction code paths.

> ℹ️  Since the BigDecimal migration, `Money::fromString()` and `ExchangeRate::fromString()`
> pay the one-time cost of normalising Brick decimals during fixture warm-up. Expect
> them to appear in profiles that include cold-start hydration; steady-state runs
> amortise the work because downstream services reuse the cached `BigDecimal` values.

Percentages below are inclusive – a parent function's time can exceed 100% when child
costs are counted multiple times – so the tables should be interpreted as “share of
work attributable to this component” rather than a disjoint partition. For the DTO
stack allocation breakdown and guard-rail behaviour that complements these hotspot
figures, see [dto-stack-allocation.md](./dto-stack-allocation.md).

## Results summary

### `bottleneck-hop-3`

| Component | Time % | Memory % | Notes | Suggested mitigation |
| --- | ---:| ---:| --- | --- |
| `PathFinderBench->benchFindBottleneckMandatoryMinima` | 19.6 | 8.0 | Harness cost for invoking the service across both datasets. | N/A (benchmark scaffolding). |
| `GraphBuilder->build` | 5.9 | 0.4 | Mutating the graph by reference and reusing zero `Money` instances removes the copy-on-write churn from the old implementation. | **P1.** Confirmed fixed – see [issue](./issues/p1-graph-builder.md) for follow-up tweaks. |
| `PathFinderService->findBestPaths` | 7.7 | 0.7 | Reworked materialisation keeps candidate DTOs on stack and defers allocations until tolerance checks pass. | **P1.** Acceptance criteria met – see [issue](./issues/p1-pathfinder-callback.md). |
| `ExchangeRate` value-object creation in `setUp()` | 0.3 | 0.2 | Fixture bootstrap now just pays the cold-start hydration before the memoised `ExchangeRate` cache is populated. | N/A – cached across runs; cold-start only. |
| `FeeBreakdown` helpers (via `GraphBuilder->build`) | 0.5 | 0.2 | Fee resolution now short-circuits the zero-fee path, so the remaining cost comes from the handful of orders that still charge fees. | P3. Keep an eye on this if fee-heavy datasets are introduced. |

Queue operations (`SearchStateQueue->insert` / `extract`) consumed roughly 0.01% of
the time on this dataset, confirming that the search frontier is tiny once guard
rails prune most states.

### `bottleneck-high-fanout-hop-4`

| Component | Time % | Memory % | Notes | Suggested mitigation |
| --- | ---:| ---:| --- | --- |
| `GraphBuilder->build` | 38.7 | 5.1 | In-place graph mutation still dominates, with `createEdge`/`OrderFillEvaluator` showing up underneath this stage even after fixture caching. | **P1.** Mitigation landed – see [issue](./issues/p1-graph-builder.md) for remaining nice-to-haves. |
| `PathFinderService->findBestPaths` | 40.0 | 5.4 | The refactored candidate callback continues to reuse buffers and keeps allocations low, but the dense frontier keeps this slice near 40%. | **P1.** Acceptance criteria satisfied – see [issue](./issues/p1-pathfinder-callback.md). |
| `BottleneckOrderBookFactory::createHighFanOut` | 25.6 | 1.4 | Memoised fixture now front-loads the dense book build once per PhpBench process; subsequent iterations clone the cached instance. | P3. Cold-start cost only – revisit if we need to hide warm-up time. |
| `Money::fromString` (fixture warm-up) | 19.1 | 2.3 | `Money::fromString` and its Brick-backed decimal normalisation dominate the one-off warm-up when the cached book hydrates (`ExchangeRate::fromString` trails at ~9%). | P3. Acceptable warm-up overhead; no action unless start-up time becomes critical. |
| `OrderSpendAnalyzer->filterOrders` | 0.4 | 0.05 | Remains a minor contributor even with higher fan-out. | P3. Monitor after the P1 items land; not currently a bottleneck. |
| `SearchStateQueue` operations | <0.02 | <0.01 | Queue push/pop still register at the noise floor after the refactors. | No action required; re-evaluate after other changes. |

Fixture caching shifts the high-fan-out profile toward genuine search work: the cold-start hydration for `Money::fromString`/`ExchangeRate::fromString` shows up once when the memoised order book is materialised, after which GraphBuilder and the path finder dominate steady-state costs.

## Proposed mitigation backlog

The following mitigations have been triaged:

* **P1 – GraphBuilder copy churn:** tracked in [docs/performance/issues/p1-graph-builder.md](./issues/p1-graph-builder.md).
* **P1 – PathFinder materialisation overhead:** tracked in [docs/performance/issues/p1-pathfinder-callback.md](./issues/p1-pathfinder-callback.md).
* **P2 – Fixture caching (complete):** Bottleneck fixtures and bench-level helpers now memoise their data; only the cold-start hydration captured above remains.
* **P3 – Queue micro-optimisations:** revisit only if future profiling shows the frontier costs growing.

Re-run the PhpBench command above after each optimisation to validate the impact on
both datasets and update this document with refreshed percentages.

## Memory Profile

Memory usage patterns derived from PhpBench profiling complement the timing data above. The library's memory footprint is predictable and scales linearly with order book size for typical workloads.

### Memory Scaling Characteristics

From k-best benchmark scenarios (PHP 8.3, Ubuntu 22.04):

| Scenario | Orders | Peak Memory | Memory/Order | Notes |
|----------|--------|-------------|--------------|-------|
| k-best-n1e2 | 100 | 8.3 MB | ~83 KB | Includes ~6-7 MB base overhead (framework, services) |
| k-best-n1e3 | 1,000 | 12.8 MB | ~12.8 KB | Sub-linear scaling due to object pooling |
| k-best-n1e4 | 10,000 | 59.1 MB | ~5.9 KB | Efficient at scale, cached value objects |

**Key insights:**

1. **Base overhead:** ~6-8 MB for framework, core services, and initial structures
2. **Per-order cost decreases at scale:** Object caching and pooling improve efficiency
3. **Search state overhead:** Typically 1-10 MB for visited state tracking
4. **Result materialization:** Minimal (< 1 MB for typical resultLimit values)

### Memory Hotspots by Component

**Graph construction (`GraphBuilder->build`):**
- **Allocation pattern:** In-place mutation with zero-Money cache
- **Memory share:** ~10-20% of peak (depending on order count)
- **Optimization:** Reuses zero-value `Money` instances, reducing allocations by ~40%

**Search state tracking (`SearchStateRegistry`):**
- **Allocation pattern:** Hash-based registry for visited states
- **Memory share:** ~15-25% of peak (scales with hop depth and graph density)
- **Typical size:** 1,000-20,000 states for moderate graphs
- **Per-state cost:** ~1 KB (includes path history, cost, currency trail)

**Order book and domain objects:**
- **Allocation pattern:** Immutable value objects (Money, ExchangeRate, OrderBounds)
- **Memory share:** ~40-60% of peak
- **Optimization:** Memoized ExchangeRate instances shared across orders

**Result materialization (`LegMaterializer`):**
- **Allocation pattern:** Stack-allocated candidate DTOs, lazy finalization
- **Memory share:** ~5-10% of peak
- **Optimization:** Buffers reused across expansions, allocations deferred until tolerance passes

### Memory Growth Patterns

**Linear growth region (< 10,000 orders):**
- Predictable scaling: ~5-10 KB per additional order
- Dominated by domain object allocations
- Guard limits rarely engaged

**Sub-linear region (10,000-50,000 orders):**
- Improved efficiency from caching
- Search state tracking becomes significant
- Guard limits help cap growth

**Dense graph scenarios:**
- Memory dominated by search state (visited states × ~1 KB)
- Guard limits essential to prevent runaway growth
- Example: `dense-4x4-hop-5` with 20,000 state limit → ~20-40 MB for search state alone

### Memory vs. Guard Limits

The primary memory control mechanism is `maxVisitedStates`:

| Guard Limit | Typical Peak Memory | Use Case |
|-------------|---------------------|----------|
| 10,000 states | +10-15 MB | Conservative, latency-sensitive |
| 50,000 states | +50-75 MB | Moderate, balanced |
| 100,000 states | +100-150 MB | Comprehensive search |
| 250,000 states (default) | +250-375 MB | Maximum coverage |

**Note:** Actual memory overhead includes base memory (~8 MB) plus per-order overhead (~5-10 KB per order).

### Monitoring Memory in Production

Track these metrics to understand memory behavior:

1. **Peak memory per request:** `memory_get_peak_usage(true)`
2. **Visited states:** `$outcome->guardLimits()->metrics()['visited_states']`
3. **Order book size:** `count($orderBook)`
4. **Guard breach rate:** `$outcome->guardLimits()->anyLimitReached()`

**Example monitoring:**

```php
$startMemory = memory_get_usage(true);
$outcome = $service->findBestPaths($request);
$peakMemory = memory_get_peak_usage(true);
$metrics = $outcome->guardLimits()->metrics();

$memoryUsed = ($peakMemory - $startMemory) / 1024 / 1024; // MB
$memoryPerState = $metrics['visited_states'] > 0 
    ? $memoryUsed / $metrics['visited_states'] 
    : 0;

// Log for analysis: memoryUsed, memoryPerState, visited_states, order_count
```

### Recommended Memory Limits

For PHP `memory_limit` configuration:

| Workload | Expected Peak | Recommended memory_limit | Safety Factor |
|----------|---------------|--------------------------|---------------|
| Small (< 1,000 orders) | 10-30 MB | 128M | 4-12× |
| Medium (1,000-10,000) | 30-150 MB | 256M-512M | 3-8× |
| Large (10,000-50,000) | 150-500 MB | 1G-2G | 2-4× |
| Dense graphs | 200-600 MB | 1G-2G | 2-5× |

**Safety factor:** Allow 2-4× expected peak to handle variance and prevent OOM.

### Memory Optimization Checklist

If memory usage exceeds expectations:

1. ✅ **Pre-filter order book** with `AmountRangeFilter` and `ToleranceWindowFilter`
2. ✅ **Reduce `maxVisitedStates`** guard limit
3. ✅ **Lower `maxHops`** to 4-6 instead of 8
4. ✅ **Decrease `resultLimit`** to 1-5 instead of 10+
5. ✅ **Enable `withSearchTimeBudget()`** to halt runaway searches
6. ✅ **Monitor guard metrics** to tune configuration

For comprehensive memory guidance, see [../memory-characteristics.md](../memory-characteristics.md).
