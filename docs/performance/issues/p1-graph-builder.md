# P1: Reduce GraphBuilder copy churn

## Summary
Profiling `bottleneck-hop-3` and `bottleneck-high-fanout-hop-4` shows
`GraphBuilder->build` accounting for 4–50% of inclusive runtime and up to 13% of
allocations because each order copy-on-writes the `$graph` array and synthesises
fresh `Money::zero()` instances for every fee segment.

## Proposed changes
- Refactor [`GraphBuilder::initializeNode`](../../src/Application/Graph/GraphBuilder.php)
  to mutate the graph by reference rather than returning a new array, avoiding a
  copy for every edge.
- Introduce a per-currency cache for zero-valued `Money` objects so `buildSegments`
  can reuse instances instead of cloning the scale metadata on every call.
- Skip segment allocation when the order has no fees (many fixtures hit this path),
  reducing the number of nested arrays allocated per edge.

## Acceptance criteria
- Both PhpBench datasets in `docs/performance/hotspot-profile.md` show at least a
  25% reduction in time spent inside `GraphBuilder->build` (inclusive) when rerun.
- No regression in unit tests or existing performance guard rails.
- Documentation in `hotspot-profile.md` is updated with the new percentages.
