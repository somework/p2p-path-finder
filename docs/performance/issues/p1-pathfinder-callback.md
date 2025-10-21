# P1: Trim PathFinderService materialisation overhead

## Summary
`PathFinderService->findBestPaths` dominates the high-fan-out profile (≈51% time,
≈12% memory) because the candidate callback allocates large
`MaterializedResult` DTOs, captures full `candidate` payloads inside
`PathOrderKey`, and instantiates `PathResult` objects before tolerance checks have
passed.

## Proposed changes
- In [`PathFinderService::findBestPaths`](../../src/Application/Service/PathFinderService.php)
  move tolerance evaluation ahead of `PathResult`/`PathOrderKey` creation so we
  only allocate when the route is accepted.
- Replace the per-result array with a lightweight `MaterializedResult` DTO or
  reuse a preallocated buffer to avoid per-result churn during `usort`.
- Drop the raw `candidate` array from the `PathOrderKey` metadata or replace it
  with the minimal ordering payload so we stop retaining the entire search state
  for each candidate.

## Acceptance criteria
- Profiling the two benchmark datasets shows at least a 20% inclusive time
  reduction inside `PathFinderService->findBestPaths`.
- Result ordering semantics remain unchanged (existing integration tests still
  pass and sorted outputs match).
- `docs/performance/hotspot-profile.md` is refreshed with the new measurements.
