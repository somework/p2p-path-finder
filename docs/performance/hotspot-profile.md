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

```
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
| `PathFinderBench->benchFindBottleneckMandatoryMinima` | 20.0 | 7.9 | Harness cost for invoking the service across both datasets. | N/A (benchmark scaffolding). |
| `GraphBuilder->build` | 4.4 | 4.9 | Repeatedly reinitialises the graph array and produces `Money::zero()` clones while wiring each order. | **P1.** Mutate the graph in-place (pass by reference) and cache zero-valued `Money` instances per currency/scale to cut copy churn. See [tracking issue](./issues/p1-graph-builder.md). |
| `PathFinderService->findBestPaths` | 9.7 | 1.1 | Candidate callback allocates `MaterializedResultEntry` arrays, `PathOrderKey` objects, and performs tolerance checks even when results are discarded. | **P1.** Streamline materialisation by reusing buffers and only instantiating `PathOrderKey` once the candidate passes tolerance. See [tracking issue](./issues/p1-pathfinder-callback.md). |
| `ExchangeRate` value-object creation in `setUp()` | 8.0 | 5.6 | Dataset bootstrap repeatedly hydrates immutable exchange rates for fixtures. | P2. Consider caching fixture `ExchangeRate` instances across runs or sharing the base order set between benchmarks. |
| `FeeBreakdown` helpers (via `GraphBuilder->build`) | 8.2 | 0.9 | Populates fee breakdown arrays for every edge even when orders do not charge fees. | P2. Defer fee-segment construction until fees are present to shrink allocations. |

Queue operations (`SearchStateQueue->insert` / `extract`) consumed <0.01% of the time
on this dataset, confirming that the search frontier is tiny once guard rails prune
most states.

### `bottleneck-high-fanout-hop-4`

| Component | Time % | Memory % | Notes | Suggested mitigation |
| --- | ---:| ---:| --- | --- |
| `GraphBuilder->build` | 49.9 | 13.5 | Dominant cost. The combination of array copy-on-write and Money clones amplifies with the dense order book. | **P1.** Same optimisation as legacy dataset – mutate the graph structure by reference and reuse zero-valued `Money` instances. [Issue](./issues/p1-graph-builder.md). |
| `PathFinderService->findBestPaths` | 51.2 | 12.4 | Candidate callback performs heavy array/object churn for every feasible path, and retains large `MaterializedResultEntry` payloads until the final sort. | **P1.** Apply the materialisation refactor captured in [issue](./issues/p1-pathfinder-callback.md) to reuse buffers, drop unneeded candidate payloads, and avoid storing entire `candidate` arrays in the `PathOrderKey`. |
| `BottleneckOrderBookFactory::createHighFanOut` | 20.1 | 1.6 | Fixture factory eagerly allocates the dense order book for each iteration. | P2. Provide a shared, memoised fixture in the benchmark to avoid rebuilding identical order books. |
| `ExchangeRate` hydration | 2.9 | 5.1 | Same pressure as legacy dataset, amplified by the larger fixture set. | P2. Cache fixture exchange rates alongside the shared order book. |
| `OrderSpendAnalyzer->filterOrders` | 0.3 | 0.0 | Minor cost compared to graph and materialisation but still scales with order count. | P3. Monitor after the P1 items land; not currently a bottleneck. |
| `SearchStateQueue` operations | <0.01 | <0.01 | Queue push/pop remain negligible even under the fan-out stress test. | No action required; re-evaluate after other changes. |

## Proposed mitigation backlog

The following mitigations have been triaged:

* **P1 – GraphBuilder copy churn:** tracked in [docs/performance/issues/p1-graph-builder.md](./issues/p1-graph-builder.md).
* **P1 – PathFinder materialisation overhead:** tracked in [docs/performance/issues/p1-pathfinder-callback.md](./issues/p1-pathfinder-callback.md).
* **P2 – Fixture caching:** fold exchange-rate and order-book caching into the benchmarks to stabilise harness noise.
* **P3 – Queue micro-optimisations:** revisit only if future profiling shows the frontier costs growing.

Re-run the PhpBench command above after each optimisation to validate the impact on
both datasets and update this document with refreshed percentages.
