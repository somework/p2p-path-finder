# Hop Limit Enforcement Review

**Date**: 2025-11-22  
**Task**: 0004.4 - Review Hop Limit Enforcement  
**Status**: ✅ Complete

## Executive Summary

Reviewed hop limit enforcement at both the search and callback levels. The implementation is **correct and follows a defense-in-depth strategy** with two enforcement layers:

1. **Search-level enforcement** (PathFinder): Prevents expansion beyond `maxHops` for efficiency
2. **Callback-level enforcement** (PathFinderService): Validates both `minHops` and `maxHops` for correctness

## Enforcement Mechanism

### 1. Search-Level Enforcement (PathFinder)

**Location**: `src/Application/PathFinder/PathFinder.php:254`

```php
if ($state->hops() >= $this->maxHops) {
    continue;
}
```

**Purpose**:  
- **Efficiency**: Prevents exploring paths beyond the configured maximum hop count
- **Early termination**: States are pruned before they reach the target if they've already exceeded hop limits

**Behavior**:
- States with `hops >= maxHops` are **not expanded** further
- This means the maximum path length found is `maxHops` edges
- The check is `>=` not `>`, so a state with exactly `maxHops` hops will not be expanded
- Since expansion happens before reaching the target, paths can have AT MOST `maxHops` hops

### 2. Callback-Level Enforcement (PathFinderService)

**Location**: `src/Application/Service/PathFinderService.php:209`

```php
if ($candidate->hops() < $request->minimumHops() || $candidate->hops() > $request->maximumHops()) {
    return false;
}
```

**Purpose**:  
- **Correctness**: Final validation that candidate paths meet both minimum and maximum hop requirements
- **Defense-in-depth**: Provides a second layer of validation even if search-level enforcement is bypassed

**Behavior**:
- Candidates with `hops < minimumHops` are **rejected**
- Candidates with `hops > maximumHops` are **rejected**
- Only candidates with `minHops <= hops <= maxHops` are **accepted**
- This is the **only place** where `minimumHops` is enforced (cannot be enforced during search)

### Why Two Layers?

**Minimum Hops**:  
- Cannot be enforced during search because we don't know the final hop count until reaching the target
- Must be enforced at callback level when candidate paths are finalized

**Maximum Hops**:  
- Enforced at search level for **efficiency** (don't explore paths that will be rejected anyway)
- Enforced at callback level for **correctness** (defense-in-depth, ensures no bugs in search logic)

## Configuration Flow

1. `PathSearchConfig` holds `minimumHops` and `maximumHops` configuration
2. `PathSearchRequest` exposes these via `minimumHops()` and `maximumHops()` methods
3. `PathFinder` is constructed with `maxHops` from `config->maximumHops()`
4. `PathFinderService` callback checks both `minimumHops` and `maximumHops` from the request

## Edge Cases Tested

### ✅ Maximum Hops Enforcement
- **Test**: `testMaximumHopsEnforcement()`
- **Scenario**: 3-hop path exists, but `maxHops = 2`
- **Result**: No paths found (correctly)

### ✅ Minimum Hops Enforcement
- **Test**: `testMinimumHopsEnforcement()`
- **Scenario**: 1-hop path is optimal, but `minHops = 2`; 2-hop path exists
- **Result**: Only 2-hop path returned (1-hop rejected)

### ✅ Min = Max Hops
- **Test**: `testMinHopsEqualsMaxHops()`
- **Scenario**: 1-hop, 2-hop, and 3-hop paths exist; `minHops = maxHops = 2`
- **Result**: Only 2-hop path returned

### ✅ Defense-in-Depth
- **Test**: `testCallbackRejectsPathRespectingSearchMaxHops()`
- **Scenario**: 2-hop path exists; search allows up to 4 hops, but callback requires `minHops = 3`
- **Result**: No paths found (callback correctly rejects)

### ✅ Search Termination
- **Test**: `testSearchTerminatesAtMaxHops()`
- **Scenario**: 5-hop chain exists; `maxHops = 3`
- **Result**: No paths found, but search did expand some states (proves search happened)

### ✅ Boundary Conditions
- **Test**: `testPathWithExactlyMaxHopsIsAccepted()`
- **Scenario**: 3-hop path exists; `maxHops = 3`
- **Result**: Path accepted

- **Test**: `testPathWithExactlyMinHopsIsAccepted()`
- **Scenario**: 2-hop path exists; `minHops = 2`
- **Result**: Path accepted

### ✅ Multiple Paths
- **Test**: `testMultiplePathsFilteredByHopLimits()`
- **Scenario**: 1-hop, 2-hop, and 3-hop paths exist; `minHops = maxHops = 2`
- **Result**: Only 2-hop paths returned

## Test Coverage

**Test File**: `tests/Application/PathFinder/HopLimitEnforcementTest.php`

- **Tests**: 8
- **Assertions**: 15
- **Status**: ✅ All passing

## Findings

### ✅ No Bugs Found

The hop limit enforcement mechanism is **correct and complete**:

1. ✅ Maximum hops enforced at search level (efficiency)
2. ✅ Maximum hops enforced at callback level (correctness)
3. ✅ Minimum hops enforced at callback level (only possible location)
4. ✅ Defense-in-depth strategy implemented correctly
5. ✅ Boundary conditions handled correctly (`>=`, not `>`)
6. ✅ Edge cases all behave as expected

### Recommendations

✅ **No changes needed** - The implementation is solid and well-tested.

## References

- Task: `tasks/split/0004.4-review-hop-limit-enforcement.md`
- Task: `tasks/split/0004.5-test-hop-limit-edge-cases.md`
- Source: `src/Application/PathFinder/PathFinder.php`
- Source: `src/Application/Service/PathFinderService.php`
- Tests: `tests/Application/PathFinder/HopLimitEnforcementTest.php`

