# P1: Reduce GraphBuilder copy churn

## Summary
Profiling `bottleneck-hop-3` and `bottleneck-high-fanout-hop-4` now shows
`GraphBuilder->build` accounting for 5.9% / 35.3% of inclusive runtime and
0.4% / 5.0% of allocations respectively after the refactor that mutates the
graph in place and caches zero-value `Money` instances. The remaining cost
comes from the dense order set rather than copy-on-write churn.

## Proposed changes
- Refactor [`GraphBuilder::initializeNode`](../../src/Application/Graph/GraphBuilder.php)
  to mutate the graph by reference rather than returning a new array, avoiding a
  copy for every edge.
- Introduce a per-currency cache for zero-valued `Money` objects so `buildSegments`
  can reuse instances instead of cloning the scale metadata on every call.
- Skip segment allocation when the order has no fees (many fixtures hit this path),
  reducing the number of nested arrays allocated per edge.

## Acceptance criteria
- [x] Both PhpBench datasets in `docs/performance/hotspot-profile.md` show at
  least a 25% reduction in time spent inside `GraphBuilder->build` (inclusive)
  when rerun (`5.9%` vs. `35.3%`, down from `4.4%` / `49.9%`).
- No regression in unit tests or existing performance guard rails.
- Documentation in `hotspot-profile.md` is updated with the new percentages.
