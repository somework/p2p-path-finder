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
| `Money::fromString` (fixture warm-up) | 19.1 | 2.3 | `Money::fromString` and its `BcMath::normalize` dependency now appear because the cached book hydrates its values during the initial build (`ExchangeRate::fromString` trails at ~9%). | P3. Acceptable warm-up overhead; no action unless start-up time becomes critical. |
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
