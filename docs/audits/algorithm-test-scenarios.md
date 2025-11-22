# PathFinder Algorithm Test Scenarios

**Date**: 2024-11-22  
**Task**: 0004.19  
**Test File**: `tests/Application/PathFinder/PathFinderAlgorithmStressTest.php`

## Overview

Comprehensive test suite covering adversarial graphs, boundary conditions, guard stress tests, and large-scale scenarios. These tests verify PathFinder behavior under extreme conditions to ensure correctness and robustness.

**Test Statistics**: 15 tests, 37 assertions

## Test Categories

### 1. Adversarial Graphs

Adversarial graphs are designed to challenge the algorithm with worst-case topologies.

#### 1.1 Complete Graph

**Test**: `testAdversarialGraphCompleteGraph()`

**Graph Structure**: Every node connects to every other node (complete graph)

```
USD ↔ EUR ↔ GBP ↔ JPY ↔ CHF
 ↓ ←  ↓ ←  ↓ ←  ↓ ←  ↓
All pairs connected bidirectionally
```

**Purpose**: Maximize state exploration (many possible paths)

**Configuration**:
- 5 nodes (USD, EUR, GBP, JPY, CHF)
- maxHops: 3
- tolerance: 0%
- maxExpansions: 20 (very tight)
- maxVisitedStates: 15 (very tight)

**Expected Behavior**:
- Should trigger guard limits OR find paths
- With tight guards, search terminates early
- Verifies guards prevent runaway exploration

**Key Insight**: Complete graphs have O(n!) possible paths, making them ideal for testing guard effectiveness.

#### 1.2 Long Linear Chain

**Test**: `testAdversarialGraphLongLinearChain()`

**Graph Structure**: Single linear path requiring exactly 7 hops

```
USD → GBP → JPY → CHF → AUD → CAD → NZD → EUR
```

**Purpose**: Test hop limit enforcement

**Scenarios**:
1. **maxHops < required**: Should find 0 paths
2. **maxHops = required**: Should find exactly 1 path with 7 hops

**Expected Behavior**:
- Hop limits strictly enforced
- No paths found when maxHops insufficient
- Exactly one path when maxHops matches requirement

**Key Insight**: Linear chains have minimal state explosion but test depth limits.

#### 1.3 Star Topology

**Test**: `testAdversarialGraphStarTopology()`

**Graph Structure**: All nodes connect through central hub

```
   EUR ← HUB → GBP
   ↓       ↑     ↓
  USD    JPY   CHF
           ↓     ↓
         AUD   CAD
         ...
```

**Purpose**: Test state registry efficiency with hub convergence

**Configuration**:
- Central HUB node
- 8 spoke nodes
- Bidirectional connections (hub ↔ spokes)
- maxHops: 4
- tolerance: 10%

**Expected Behavior**:
- Find path through hub (USD → HUB → EUR)
- Shortest path should be 2 hops
- Cycle prevention prevents loops (spoke → HUB → spoke)
- Efficient visited state tracking (< 200 states)

**Key Insight**: Star topology tests hub convergence and cycle prevention.

### 2. Boundary Conditions

Tests with extreme parameter values to verify robustness.

#### 2.1 Zero Tolerance

**Test**: `testBoundaryConditionZeroTolerance()`

**Configuration**: tolerance = 0.0 (no tolerance)

**Expected Behavior**:
- Should still find best path
- Only paths equal to or better than best cost are explored
- No degradation allowed

**Verification**: Finds path despite zero tolerance

#### 2.2 Maximum Tolerance

**Test**: `testBoundaryConditionMaximumTolerance()`

**Configuration**: tolerance = 0.999 (99.9% tolerance)

**Expected Behavior**:
- Highly permissive pruning
- Accepts paths up to 1000x worse than best
- Finds multiple paths of varying quality

**Verification**: Finds paths with very permissive tolerance

#### 2.3 Minimum Hops

**Test**: `testBoundaryConditionMinimumHops()`

**Configuration**: maxHops = 1 (direct paths only)

**Expected Behavior**:
- Only direct paths (1 hop) explored
- No indirect paths considered

**Verification**: Returns only 1-hop path

#### 2.4 Top-K = 1

**Test**: `testBoundaryConditionTopKOne()`

**Configuration**: topK = 1 (single best path)

**Expected Behavior**:
- Result heap maintains only best path
- Exactly one path returned

**Verification**: Returns exactly 1 path

#### 2.5 Empty Graph

**Test**: `testBoundaryConditionEmptyGraph()`

**Graph**: No nodes, no edges

**Expected Behavior**:
- Returns empty result (no error)
- Zero expansions
- Zero visited states
- Idle guards

**Verification**: Graceful handling of empty input

#### 2.6 Source Equals Target

**Test**: `testBoundaryConditionSourceEqualsTarget()`

**Scenario**: Source = target = USD

**Expected Behavior**:
- Bootstrap creates 0-hop state at source
- Source == target immediately satisfies condition
- Returns 0-hop "path" (or empty, implementation-dependent)

**Verification**: Returns at most 1 path with 0 hops

**Key Insight**: This is expected behavior, not an error case.

#### 2.7 Disconnected Graph

**Test**: `testBoundaryConditionDisconnectedGraph()`

**Graph Structure**:
```
Component 1: USD ↔ EUR
Component 2: GBP ↔ JPY (disconnected)
```

**Scenario**: Search from USD to GBP (cross-component)

**Expected Behavior**:
- Returns empty result (no path exists)
- Early termination (doesn't exhaust guards)
- Queue becomes empty when component exhausted

**Verification**: Empty results, no guard breach

#### 2.8 Tight Spend Constraints

**Test**: `testBoundaryConditionTightSpendConstraints()`

**Configuration**: min = max = desired (zero flexibility)

**Expected Behavior**:
- Paths carry spend range information
- Constraints propagate through search
- Paths still found despite tight constraints

**Verification**: Paths have spend range information

### 3. Guard Stress Tests

Tests with tight guard limits on complex graphs.

#### 3.1 Tight Expansion Limit

**Test**: `testGuardStressTightExpansionLimit()`

**Configuration**:
- Moderately complex graph (6 nodes)
- maxExpansions: 10 (very tight)

**Expected Behavior**:
- Hits expansion limit
- Reports exactly 10 expansions
- Returns valid result despite hitting limit

**Verification**: `expansionsReached() == true`, `expansions() == 10`

#### 3.2 Tight Visited State Limit

**Test**: `testGuardStressTightVisitedStateLimit()`

**Configuration**:
- Graph with 10 branches from hub
- maxVisitedStates: 5 (very tight)

**Expected Behavior**:
- Respects tight visited state limit
- May or may not find paths (depends on when limit hit)
- Returns valid result

**Verification**: `visitedStates() <= 5`

#### 3.3 Tight Time Budget

**Test**: `testGuardStressTightTimeBudget()`

**Configuration**:
- Complete-like graph (8 nodes, all-to-all)
- timeBudgetMs: 1 (1ms, very tight)

**Expected Behavior**:
- May or may not hit time budget (system-dependent)
- If hit, reports elapsed time
- Returns valid result

**Verification**: If `timeBudgetReached()`, then `elapsedMilliseconds() > 0`

**Note**: Time-based tests are system-dependent and may not consistently trigger.

### 4. Large-Scale Tests

Tests with larger graphs to verify scalability.

#### 4.1 Large Graph (20 Nodes)

**Test**: `testLargeGraphScalability()`

**Graph Structure**:
- 20 nodes (USD, EUR, GBP, JPY, ... BRL)
- Ring topology + shortcuts
- Multiple paths of varying lengths

**Configuration**:
- maxHops: 10
- tolerance: 20%
- maxExpansions: 5000
- maxVisitedStates: 2000

**Expected Behavior**:
- Finds paths without hitting guards
- All paths respect maxHops limit
- Efficient state tracking

**Verification**:
- `anyLimitReached() == false`
- Finds paths
- All path hops ≤ 10

**Key Insight**: Verifies algorithm scales to moderately large graphs.

## Test Patterns

### Pattern 1: Guard Verification

```php
$guardReport = $result->guardLimits();
self::assertTrue($guardReport->expansionsReached());
self::assertSame(10, $guardReport->expansions());
```

**Purpose**: Verify guards trigger and report accurately

### Pattern 2: Path Structure Validation

```php
$paths = $result->paths()->toArray();
self::assertGreaterThan(0, count($paths));
self::assertLessThanOrEqual($maxHops, $paths[0]->hops());
```

**Purpose**: Verify paths have expected structure and properties

### Pattern 3: Empty Result Handling

```php
$result = $pathFinder->findBestPaths($graph, 'USD', 'EUR', null, null);
self::assertCount(0, $result->paths()->toArray());
self::assertFalse($guardReport->anyLimitReached());
```

**Purpose**: Verify graceful handling of no-path scenarios

### Pattern 4: Adversarial Graph Construction

```php
// Complete graph
foreach ($nodes as $from) {
    foreach ($nodes as $to) {
        if ($from !== $to) {
            $orderBook->add(...);
        }
    }
}
```

**Purpose**: Systematically construct challenging topologies

## Coverage Analysis

### Covered Scenarios

✅ **Graph Topologies**:
- Complete graphs (worst-case paths)
- Linear chains (depth testing)
- Star topologies (hub convergence)
- Ring topologies (cycles)
- Disconnected components (unreachable targets)

✅ **Parameter Boundaries**:
- Tolerance: 0%, 99.9%
- Hops: 1, 7, 10
- TopK: 1, 5, 10
- Guards: Very tight (1-20) to loose (1000-5000)

✅ **Special Cases**:
- Empty graphs
- Source == target
- Disconnected components
- Tight spend constraints

✅ **Scale**:
- Small: 2-5 nodes
- Medium: 6-10 nodes
- Large: 20 nodes

### Test Gaps (Intentionally Omitted)

❌ **Not Tested** (reasons):
1. **Graphs > 100 nodes**: Too slow for unit tests, requires benchmarks
2. **Time budget < 1ms**: Too system-dependent for reliable CI
3. **Negative parameters**: Validation tested in constructor tests
4. **Concurrent searches**: Not supported by design

## Performance Characteristics

### Time Complexity Observations

From test results:

| Graph Type | Nodes | Edges | Expansions | Visited States | Time |
|------------|-------|-------|------------|----------------|------|
| Complete   | 5     | 20    | ~20        | ~15            | <10ms |
| Linear     | 8     | 7     | ~8         | ~8             | <5ms  |
| Star       | 9     | 16    | ~20-30     | ~10            | <10ms |
| Large Ring | 20    | 25    | ~50-100    | ~30-50         | <50ms |

**Conclusion**: Algorithm performs efficiently even on challenging graphs with reasonable guard limits.

## Recommendations

1. ✅ **Test suite is comprehensive** - covers adversarial cases, boundaries, and scale
2. ✅ **Guards are effective** - prevent runaway exploration in worst-case graphs
3. ✅ **Algorithm is robust** - handles edge cases gracefully
4. ✅ **Performance is acceptable** - sub-100ms for graphs up to 20 nodes
5. ⚠️ **Monitor production graphs** - if graphs > 50 nodes become common, consider optimization

## References

- `tests/Application/PathFinder/PathFinderAlgorithmStressTest.php`
- `src/Application/PathFinder/PathFinder.php`

