# P1: Reduce GraphBuilder copy churn

## Summary
Profiling [`bottleneck-hop-3`](../hotspot-profile.md#bottleneck-hop-3) and
[`bottleneck-high-fanout-hop-4`](../hotspot-profile.md#bottleneck-high-fanout-hop-4)
now shows `GraphBuilder->build` accounting for 5.9% / 38.7% of inclusive runtime
and 0.4% / 5.1% of allocations respectively after the refactor that mutates the
graph in place and caches zero-value `Money` instances. The remaining cost comes
from the dense order set rather than copy-on-write churn.

## Proposed changes
- Refactor [`GraphBuilder::initializeNode`](../../src/Application/Graph/GraphBuilder.php)
  to mutate the graph by reference rather than returning a new array, keeping the
  `GraphBuilder->build` row in the
  [bottleneck-hop-3](../hotspot-profile.md#bottleneck-hop-3) and
  [bottleneck-high-fanout-hop-4](../hotspot-profile.md#bottleneck-high-fanout-hop-4)
  tables at the improved `5.9%` / `38.7%` inclusive time and `0.4%` / `5.1%`
  memory instead of regressing to the old baseline.
- Introduce a per-currency cache for zero-valued `Money` objects so `buildSegments`
  can reuse instances instead of cloning the scale metadata on every call, matching
  the improved allocation share documented in those hotspot rows.
- Skip segment allocation when the order has no fees (many fixtures hit this path),
  reducing the number of nested arrays allocated per edge and preserving the
  memory reductions captured in the hotspot profile.

## Acceptance criteria
- [x] Both PhpBench datasets in `docs/performance/hotspot-profile.md` show at
  least a 25% reduction in time spent inside `GraphBuilder->build` (inclusive)
  when rerun. The latest hotspot table lists it at `5.9%` / `38.7%` inclusive
  time with `0.4%` / `5.1%` memory, compared to the baseline `4.4%` / `49.9%`
  time and `4.9%` / `13.5%` memory. See the `GraphBuilder->build` rows in the
  [bottleneck-hop-3](../hotspot-profile.md#bottleneck-hop-3) and
  [bottleneck-high-fanout-hop-4](../hotspot-profile.md#bottleneck-high-fanout-hop-4)
  tables for details.
- No regression in unit tests or existing performance guard rails.
- Documentation in `hotspot-profile.md` is updated with the new percentages.
