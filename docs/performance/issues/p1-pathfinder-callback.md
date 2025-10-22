# P1: Trim PathFinderService materialisation overhead

## Summary
According to the
[bottleneck-hop-3](../hotspot-profile.md#bottleneck-hop-3) and
[bottleneck-high-fanout-hop-4](../hotspot-profile.md#bottleneck-high-fanout-hop-4)
tables, `PathFinderService->findBestPaths` now consumes 7.7% of the legacy run
and 40.0% of the high-fan-out profile (down from ≈9.7% / 51.2%), with memory
share reduced to 0.7% / 5.4% (previously 1.1% / 12.4%). The refactored callback
reuses buffers, trims DTO creation, and avoids retaining full candidate payloads
prior to tolerance checks.

## Proposed changes
- In [`PathFinderService::findBestPaths`](../../src/Application/Service/PathFinderService.php)
  move tolerance evaluation ahead of `PathResult`/`PathOrderKey` creation so we
  only allocate when the route is accepted, sustaining the `7.7%` / `40.0%`
  inclusive share and `0.7%` / `5.4%` memory footprint reported in the
  [bottleneck-hop-3](../hotspot-profile.md#bottleneck-hop-3) and
  [bottleneck-high-fanout-hop-4](../hotspot-profile.md#bottleneck-high-fanout-hop-4)
  hotspot rows.
- Replace the per-result array with a lightweight `MaterializedResult` DTO or
  reuse a preallocated buffer to avoid per-result churn during `usort`, keeping
  the callback allocations low in both datasets.
- Drop the raw `candidate` array from the `PathOrderKey` metadata or replace it
  with the minimal ordering payload so we stop retaining the entire search state
  for each candidate, aligning with the memory reductions recorded in the hotspot
  profile.

## Acceptance criteria
- [x] Profiling the two benchmark datasets shows at least a 20% inclusive time
  reduction inside `PathFinderService->findBestPaths`. The hotspot table now
  records `7.7%` / `40.0%` inclusive time with `0.7%` / `5.4%` memory, compared
  to the baseline `9.7%` / `51.2%` time and `1.1%` / `12.4%` memory. See the
  `PathFinderService->findBestPaths` rows in the
  [bottleneck-hop-3](../hotspot-profile.md#bottleneck-hop-3) and
  [bottleneck-high-fanout-hop-4](../hotspot-profile.md#bottleneck-high-fanout-hop-4)
  tables.
- Result ordering semantics remain unchanged (existing integration tests still
  pass and sorted outputs match).
- `docs/performance/hotspot-profile.md` is refreshed with the new measurements.
