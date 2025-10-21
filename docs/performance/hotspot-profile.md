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
critical queueing paths in [`src/Application/PathFinder/SearchStateQueue.php`](../../src/Application/PathFinder/SearchStateQueue.php) are
included alongside the materialisation and graph construction code paths.

Percentages below are inclusive – a parent function's time can exceed 100% when child
costs are counted multiple times – so the tables should be interpreted as “share of
work attributable to this component” rather than a disjoint partition.

## Results summary

### `bottleneck-hop-3`

| Component | Time % | Memory % | Notes | Suggested mitigation |
| --- | ---:| ---:| --- | --- |
| `PathFinderBench->benchFindBottleneckMandatoryMinima` | 19.6 | 8.0 | Harness cost for invoking the service across both datasets. | N/A (benchmark scaffolding). |
| `GraphBuilder->build` | 5.9 | 0.4 | Mutating the graph by reference and reusing zero `Money` instances removes the copy-on-write churn from the old implementation. | **P1.** Confirmed fixed – see [issue](./issues/p1-graph-builder.md) for follow-up tweaks. |
| `PathFinderService->findBestPaths` | 7.7 | 0.7 | Reworked materialisation keeps candidate DTOs on stack and defers allocations until tolerance checks pass. | **P1.** Acceptance criteria met – see [issue](./issues/p1-pathfinder-callback.md). |
| `ExchangeRate` value-object creation in `setUp()` | 2.0 | <0.1 | Fixture bootstrap still hydrates immutable exchange rates for every run, though the absolute cost dropped after the service optimisations. | P2. Consider caching fixture `ExchangeRate` instances across runs or sharing the base order set between benchmarks. |
| `FeeBreakdown` helpers (via `GraphBuilder->build`) | 0.5 | 0.2 | Fee resolution now short-circuits the zero-fee path, so the remaining cost comes from the handful of orders that still charge fees. | P3. Keep an eye on this if fee-heavy datasets are introduced. |

Queue operations (`SearchStateQueue->insert` / `extract`) consumed roughly 0.01% of
the time on this dataset, confirming that the search frontier is tiny once guard
rails prune most states.

### `bottleneck-high-fanout-hop-4`

| Component | Time % | Memory % | Notes | Suggested mitigation |
| --- | ---:| ---:| --- | --- |
| `GraphBuilder->build` | 35.3 | 5.0 | In-place graph mutation and cached zero `Money` values cut runtime by ~30% and trimmed allocations substantially, but the dense order book still stresses this stage. | **P1.** Mitigation landed – see [issue](./issues/p1-graph-builder.md) for remaining nice-to-haves. |
| `PathFinderService->findBestPaths` | 36.9 | 5.3 | The rewritten candidate callback now reuses buffers and skips premature DTO creation, halving the previous memory pressure. | **P1.** Acceptance criteria satisfied – see [issue](./issues/p1-pathfinder-callback.md). |
| `BottleneckOrderBookFactory::createHighFanOut` | 26.0 | 1.7 | Fixture factory still rebuilds the dense order book every run; relative share grew now that search/materialisation are cheaper. | P2. Provide a shared, memoised fixture in the benchmark to avoid rebuilding identical order books. |
| `ExchangeRate` hydration | 9.0 | 0.3 | Exchange-rate hydration now shows up because the dominant hotspots were reduced. | P2. Cache fixture exchange rates alongside the shared order book. |
| `OrderSpendAnalyzer->filterOrders` | 0.4 | 0.0 | Remains a minor contributor even with higher fan-out. | P3. Monitor after the P1 items land; not currently a bottleneck. |
| `SearchStateQueue` operations | <0.02 | <0.01 | Queue push/pop still register at the noise floor after the refactors. | No action required; re-evaluate after other changes. |

## Proposed mitigation backlog

The following mitigations have been triaged:

* **P1 – GraphBuilder copy churn:** tracked in [docs/performance/issues/p1-graph-builder.md](./issues/p1-graph-builder.md).
* **P1 – PathFinder materialisation overhead:** tracked in [docs/performance/issues/p1-pathfinder-callback.md](./issues/p1-pathfinder-callback.md).
* **P2 – Fixture caching:** fold exchange-rate and order-book caching into the benchmarks to stabilise harness noise.
* **P3 – Queue micro-optimisations:** revisit only if future profiling shows the frontier costs growing.

Re-run the PhpBench command above after each optimisation to validate the impact on
both datasets and update this document with refreshed percentages.
