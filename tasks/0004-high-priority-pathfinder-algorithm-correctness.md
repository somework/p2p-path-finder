# Task: PathFinder Algorithm Correctness and Guard Semantics Review

## Context

The `PathFinder` class (marked `@internal`) implements the core tolerance-aware best-path search algorithm. Key aspects:
- Dijkstra-like exploration with tolerance-based pruning
- Priority queue ordering: cost → hops → route signature → discovery order
- Guards: max expansions, max visited states, time budget
- Mandatory segment pruning: edges have mandatory vs optional capacity
- Spend constraints: min/max amounts derived from tolerance window

The algorithm must be correct for:
- Finding optimal paths (lowest cost)
- Handling hop limits correctly
- Respecting tolerance bounds
- Enforcing guard limits deterministically
- Maintaining stable ordering of equal-cost paths
- Correctly handling mandatory segment capacity

Current test coverage includes:
- Basic path finding tests
- Guard limit tests
- Dense graph tests
- Property-based tests for heuristics and ordering
- Metamorphic tests

## Problem

**Correctness risks:**
1. **Tolerance handling**:
   - Is tolerance correctly applied to pruning decisions?
   - Are amplifier calculations (from tolerance) correct?
   - Edge case: tolerance = 0 (no flexibility) - is this handled?
   - Edge case: tolerance near 1.0 - does this break?
2. **Hop limit enforcement**:
   - Are minimum and maximum hops both enforced?
   - Can paths violate hop limits through the acceptance callback?
3. **Guard semantics**:
   - Are guards checked in the correct order?
   - Is time budget checked on every iteration or periodically?
   - What happens when multiple guards are reached simultaneously?
   - Is guard metadata accurate (counts, elapsed time)?
4. **Ordering determinism**:
   - Tie-breaking documented as: cost → hops → signature → discovery
   - Is this enforced everywhere (queue, final results)?
   - Are there edge cases where ordering might be non-deterministic?
5. **Mandatory segment handling**:
   - Is mandatory capacity correctly aggregated across segments?
   - Can a path bypass mandatory minimums?
   - What if mandatory minimum exceeds spend constraints?
6. **Spend constraints**:
   - Are min/max amounts correctly propagated through the search?
   - What if desired amount is outside min/max bounds?
   - How are constraints updated during edge traversal?
7. **Visited state tracking**:
   - Can cycles occur despite visited state tracking?
   - Is the state signature unique and comprehensive?
   - What if two paths reach the same node with different costs?
8. **Acceptance callback**:
   - Can the callback break invariants?
   - What if callback is slow - does it affect guard timing?
   - Is callback called for all target candidates or only valid ones?

## Proposed Changes

### 1. Review and document tolerance handling

- **Audit** tolerance amplifier calculation in PathFinder constructor
- **Verify** tolerance pruning logic in search loop
- **Test** edge cases:
  - Tolerance = 0 (zero tolerance, must spend exactly)
  - Tolerance close to upper bound (0.999999...)
  - Tolerance window where min = max
- **Document** tolerance semantics in PathFinder class docblock

### 2. Review and test hop limit enforcement

- **Verify** minimum hops filter in PathFinderService callback
- **Verify** maximum hops check in PathFinder search loop
- **Add test**: Path that would be optimal but violates minimum hops
- **Add test**: Path that respects max hops at search level but callback rejects
- **Document** hop enforcement sequence (search loop vs callback)

### 3. Audit guard semantics and metadata

- **Review** SearchGuards implementation:
  - Is expansion count incremented correctly?
  - Is visited state count accurate?
  - Is time budget checked frequently enough?
- **Verify** guard report accuracy:
  - Do counts match actual search activity?
  - Is elapsed time measured consistently?
  - Are breach flags set correctly?
- **Test** guard combinations:
  - Multiple guards reached simultaneously
  - Guards at boundary values (1 expansion, 1ms budget)
  - Guards with very large limits (no practical limit)
- **Test** exception vs metadata modes for guard breaches

### 4. Review ordering determinism

- **Verify** SearchStatePriorityQueue implements correct tie-breaking
- **Verify** CandidatePriorityQueue implements correct tie-breaking
- **Verify** PathOrderStrategy usage in results
- **Add test**: Large batch of equal-cost paths (verify stable ordering)
- **Add test**: Paths with identical cost and hops but different signatures
- **Document** ordering guarantees in PathFinder and PathFinderService

### 5. Review mandatory segment logic

- **Audit** SegmentPruner implementation
- **Verify** mandatory capacity aggregation in search loop
- **Add test**: Path with mandatory segments that exceed spend constraints
- **Add test**: Edge with all-mandatory vs mixed mandatory/optional segments
- **Add test**: Zero mandatory capacity (all optional)
- **Document** mandatory segment semantics in GraphBuilder/PathFinder

### 6. Review spend constraints propagation

- **Verify** SpendConstraints are correctly computed from ToleranceWindow
- **Verify** constraints are updated when traversing edges
- **Test** edge cases:
  - Desired amount outside min/max bounds
  - Min = max (single valid amount)
  - Very wide tolerance window
- **Verify** constraint violations are caught early (before expanding)

### 7. Audit visited state tracking

- **Review** SearchStateRegistry implementation
- **Verify** SearchStateSignature uniqueness
- **Test** adversarial cases:
  - Graph with many paths to same node
  - Cycles (should be prevented)
  - Same node reached via different costs
- **Verify** visited states count matches actual unique states

### 8. Review acceptance callback semantics

- **Document** callback contract (when called, what guarantees)
- **Verify** callback is called only after basic validation
- **Test** slow callback with time budget (ensure timeout works)
- **Test** callback that always returns false (ensure graceful empty result)
- **Consider** adding callback error handling (exceptions in callback)

### 9. Add missing algorithm tests

Based on review, add any missing test scenarios:
- **Adversarial graphs**: Dense graphs designed to trigger worst-case behavior
- **Boundary conditions**: Single-order paths, zero-tolerance, etc.
- **Guard stress tests**: Very tight guards on complex graphs
- **Metamorphic properties**: Transform input and verify output transforms predictably

## Dependencies

- May interact with task 0003 (decimal arithmetic) if cost calculation issues are found
- May inform task 0001 (public API) if callback contract needs clarification

## Effort Estimate

**L** (1-3 days)
- Algorithm review: 4-6 hours
- Test implementation: 4-6 hours  
- Documentation: 2-3 hours
- Edge case debugging: 2-4 hours (if issues found)

## Risks / Considerations

- **Algorithm bugs**: If correctness issues are found, fixes might require careful validation to avoid breaking existing behavior
- **Performance vs correctness**: Some checks might be expensive; need to balance
- **Test complexity**: Adversarial graph tests can be complex to construct and debug
- **Breaking changes**: If guard semantics change, consumers might be affected

## Definition of Done

- [ ] Tolerance handling audited and documented
- [ ] Hop limit enforcement verified and tested
- [ ] Guard semantics verified with comprehensive tests
- [ ] Guard metadata accuracy verified
- [ ] Ordering determinism verified with stress tests
- [ ] Mandatory segment logic verified and tested
- [ ] Spend constraints propagation verified
- [ ] Visited state tracking verified against cycles
- [ ] Acceptance callback contract documented and tested
- [ ] All missing test scenarios added
- [ ] Algorithm behavior documented in PathFinder docblock
- [ ] All tests pass
- [ ] Property tests pass with high iteration counts
- [ ] Infection mutation score maintained or improved
- [ ] Benchmarks pass without regression

**Priority:** P1 – Release-blocking

