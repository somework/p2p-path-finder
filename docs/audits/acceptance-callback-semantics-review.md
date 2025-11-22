# Acceptance Callback Semantics Review

**Date**: 2024-11-22  
**Tasks**: 0004.17, 0004.18  
**Reviewer**: AI Assistant

## Executive Summary

✅ **PASSED** - Acceptance callback contract is correctly implemented with clear semantics.

The PathFinder acceptance callback provides a flexible mechanism for filtering candidate paths based on custom criteria. The callback is invoked when a search state reaches the target node, allowing consumers to accept or reject paths before they're added to results.

## Callback Contract

### Signature

```php
callable(CandidatePath):bool|null $acceptCandidate
```

**Parameters**:
- `$candidate`: A `CandidatePath` instance representing a discovered path to the target

**Return Values**:
- `true`: Accept the candidate (add to results)
- `false`: Reject the candidate (continue search for better paths)
- `null` callback: Accept all candidates (default behavior)

### When Called

**Location**: `PathFinder.php` line 243

**Timing**: The callback is invoked:
1. **AFTER** a search state reaches the target node
2. **AFTER** basic path construction (cost, product, edges, range)
3. **BEFORE** the path is added to the result heap
4. **BEFORE** tolerance pruning is updated with the new path

**Sequence**:
```
Search loop extracts state from queue
  ↓
Check if state.node() === target
  ↓
Construct CandidatePath from state
  ↓
Invoke callback: acceptCandidate(candidate) ← HERE
  ↓
If true (or null callback): Record result, update best cost
If false: Continue to next state
```

### Code Location

**File**: `src/Application/PathFinder/PathFinder.php`

```php
if ($state->node() === $target) {
    // ... construct candidate ...
    
    $candidate = CandidatePath::from(
        $candidateCostDecimal,
        $candidateProductDecimal,
        $state->path()->count(),
        $state->path(),
        $candidateRange,
    );
    
    if (null === $acceptCandidate || $acceptCandidate($candidate)) {
        // Accept: update best cost and record result
        if (null === $bestTargetCost || $candidateCostDecimal->isLessThan($bestTargetCost)) {
            $bestTargetCost = $candidateCostDecimal;
        }
        
        $this->recordResult($results, $candidate, $resultInsertionOrder->next());
    }
    
    continue; // Always continue search after reaching target
}
```

## Guarantees and Properties

### 1. Candidate Path Properties

When the callback is invoked, the `CandidatePath` has these **guaranteed properties**:

✅ **Valid path structure**:
- `cost`: BigDecimal representing cumulative cost (lower is better)
- `product`: BigDecimal representing cumulative product (conversion rate)
- `hops`: Integer count of edges (1 to maxHops)
- `edges`: Non-empty PathEdgeSequence with valid edges
- `range`: SpendConstraints if spend constraints were configured (nullable)

✅ **Path reaches target**:
- The path ends at the requested target node
- All edges are valid and connected

✅ **Within configured limits**:
- `hops ≤ maxHops` (search-level enforcement)
- Cost satisfies tolerance bounds (if applicable)

⚠️ **NOT guaranteed at PathFinder level**:
- Minimum hops check (enforced at PathFinderService level)
- Materialization success (enforced at PathFinderService level)
- Tolerance window constraints (enforced at PathFinderService level)

### 2. Callback Invocation Count

**Multiple invocations**: The callback may be called multiple times as the search explores different paths to the target.

**Example**:
- Direct path: USD → EUR (1 hop) → callback invoked
- Indirect path: USD → GBP → EUR (2 hops) → callback invoked again

**Ordering**: Callbacks are invoked in the order paths are discovered, which depends on:
- Priority queue ordering (cost, hops, route signature)
- Search expansion order
- Tolerance amplifier settings

### 3. Search Continuation

**Search always continues** after callback returns:
- `true`: Path accepted, search continues for more paths
- `false`: Path rejected, search continues looking for alternatives

**Termination** occurs when:
- Queue is exhausted (no more states to explore)
- Guard limits reached (expansions, visited states, time budget)
- No more paths can improve upon tolerance-pruned best cost

### 4. Side Effects

**Callback may have side effects**:
- ✅ Collecting candidates for analysis (as in existing test)
- ✅ Logging or metrics
- ✅ Complex validation logic

**Callback must NOT**:
- ❌ Modify the graph
- ❌ Modify the candidate (immutable)
- ❌ Block for extended periods (respects search time budget)

### 5. Error Handling

**No exception handling** in PathFinder:
- Callback exceptions **propagate** to caller
- No try-catch wrapper around callback invocation
- Responsibility for exception handling lies with callback author

**Implication**: Callbacks should be defensive and handle their own errors if needed.

## PathFinderService Integration

### Enhanced Callback

PathFinderService wraps the acceptance callback with additional logic:

**File**: `src/Application/Service/PathFinderService.php` (lines 208-274)

**Additional Checks**:
1. **Hop limits** (minimum and maximum)
2. **Edge validation** (non-empty, correct source)
3. **Initial seed determination**
4. **Path materialization** (fill amounts, fees)
5. **Tolerance evaluation** (residual calculation)

**Callback structure**:
```php
function (CandidatePath $candidate) use (...) {
    // 1. Check hop limits
    if ($candidate->hops() < $request->minimumHops() 
        || $candidate->hops() > $request->maximumHops()) {
        return false;
    }
    
    // 2. Validate edges
    if ($edges->isEmpty() || $firstEdge->from() !== $sourceCurrency) {
        return false;
    }
    
    // 3. Determine initial seed
    $initialSeed = $this->orderSpendAnalyzer->determineInitialSpendAmount(...);
    if (null === $initialSeed) {
        return false;
    }
    
    // 4. Materialize path
    $materialized = $this->legMaterializer->materialize(...);
    if (null === $materialized) {
        return false;
    }
    
    // 5. Evaluate tolerance
    $residual = $this->toleranceEvaluator->evaluate(...);
    if (null === $residual) {
        return false;
    }
    
    // 6. Accept and store result
    $materializedResults[] = ...;
    return true;
}
```

**Key insight**: PathFinderService's callback does extensive validation, ensuring only fully viable paths are accepted.

## Edge Cases

### Case 1: Null Callback

**Behavior**: All paths that reach the target are accepted.

**Use case**: Simple searches where all valid paths are desired.

**Example**:
```php
$result = $pathFinder->findBestPaths($graph, 'USD', 'EUR', null, null);
// All paths USD → EUR within topK are returned
```

### Case 2: Always Reject Callback

**Behavior**: No paths are accepted, but search continues until queue exhausted or guards triggered.

**Use case**: Collecting candidates for analysis without accepting any.

**Example**:
```php
$candidates = [];
$result = $pathFinder->findBestPaths(
    $graph, 'USD', 'EUR', null,
    function($c) use (&$candidates) {
        $candidates[] = $c;
        return false; // Reject all
    }
);
// $result->paths() is empty, but $candidates has all discovered paths
```

### Case 3: Conditional Acceptance

**Behavior**: Accept only paths meeting specific criteria.

**Use case**: Custom filtering (e.g., hop count, cost threshold, route constraints).

**Example** (from existing test):
```php
$result = $pathFinder->findBestPaths(
    $graph, 'EUR', 'USD', $constraints,
    fn($c) => $c->hops() >= 2 // Only accept paths with 2+ hops
);
```

### Case 4: Slow Callback

**Behavior**: Callback execution time contributes to search time budget.

**Consideration**: If `timeBudgetMs` is configured, slow callbacks may cause time budget breach.

**Example**:
```php
$result = $pathFinder->findBestPaths(
    $graph, 'USD', 'EUR', null,
    function($c) {
        sleep(1); // Slow callback
        return true;
    }
);
// May trigger time budget guard if configured
```

### Case 5: Callback Throws Exception

**Behavior**: Exception propagates to caller, search aborts.

**Consideration**: Callbacks should handle their own errors if recovery is desired.

**Example**:
```php
try {
    $result = $pathFinder->findBestPaths(
        $graph, 'USD', 'EUR', null,
        fn($c) => throw new \RuntimeException('Oops')
    );
} catch (\RuntimeException $e) {
    // Exception propagates from callback
}
```

## Interaction with Other Features

### 1. Tolerance Pruning

**Callback invoked BEFORE** tolerance pruning update:
- If callback accepts path, `bestTargetCost` is updated
- Future states with cost > `bestTargetCost * toleranceAmplifier` are pruned
- Rejected paths do NOT affect tolerance pruning

**Example**:
- Path A: cost=1.0, callback rejects
- Path B: cost=1.5, callback accepts
- `bestTargetCost` becomes 1.5 (not 1.0)
- Future paths pruned based on 1.5 threshold

### 2. Top-K Results

**Callback acceptance affects result heap**:
- Only accepted paths enter the result heap
- Result heap maintains top-K best paths (by cost, hops, signature, order)
- Rejected paths don't occupy result heap slots

**Example** with `topK=3`:
- 10 paths discovered
- Callback accepts 5 paths
- Result heap keeps best 3 of the 5 accepted

### 3. Search Guards

**Guards operate independently of callback**:
- Expansion count: Incremented for each state expansion (callback-independent)
- Visited states: Tracks unique (node, signature) pairs (callback-independent)
- Time budget: Includes callback execution time

**Example**:
- Slow callback causes time budget breach
- Search terminates
- `SearchGuardReport::timeBudgetReached()` returns true

### 4. Hop Limits

**Two-layer enforcement**:
1. **Search-level** (`PathFinder`): `hops >= maxHops` stops expansion
2. **Callback-level** (`PathFinderService`): Checks `minimumHops` and `maximumHops`

**Rationale**: Search-level is efficiency (don't explore beyond max), callback-level is correctness (enforce min/max range).

## Documentation Status

### Current PHPDoc

**Location**: `PathFinder.php` line 164

**Current**:
```php
/**
 * @param callable(CandidatePath):bool|null $acceptCandidate
 * ...
 */
```

**Status**: ⚠️ Minimal - lacks contract details

### Recommended Enhancement

```php
/**
 * Searches for the best paths from source to target with optional acceptance filtering.
 *
 * @param Graph                               $graph             The trading graph
 * @param string                              $source            Source currency code
 * @param string                              $target            Target currency code
 * @param SpendConstraints|null               $spendConstraints  Optional spend constraints
 * @param callable(CandidatePath):bool|null   $acceptCandidate   Optional callback to filter paths
 *
 * ## Acceptance Callback Contract
 *
 * The callback is invoked when a path reaches the target node, BEFORE it's added to results.
 *
 * **Signature**: `callable(CandidatePath):bool`
 *
 * **Return values**:
 * - `true`: Accept path (add to results)
 * - `false`: Reject path (continue search)
 * - `null` callback: Accept all paths (default)
 *
 * **Guarantees**:
 * - Candidate has valid structure (cost, product, hops, edges)
 * - Path reaches target node
 * - Hops ≤ maxHops
 *
 * **Timing**: Called AFTER path construction, BEFORE result recording
 *
 * **Error handling**: Callback exceptions propagate to caller
 *
 * **Side effects**: Callback may have side effects but must NOT modify graph or candidate
 *
 * @throws GuardLimitExceeded              when a configured guard limit is exceeded during search
 * @throws InvalidInput|PrecisionViolation when path construction or arithmetic operations fail
 *
 * @return SearchOutcome<CandidatePath>
 */
```

## Existing Test Coverage

### Tests Found

1. **PathFinderTest.php** (line 1190):
   - `test_it_continues_search_when_callback_rejects_initial_target_candidate()`
   - ✅ Tests callback rejection behavior
   - ✅ Verifies multiple callback invocations
   - ✅ Confirms search continuation after rejection

### Coverage Gaps

Need tests for:
1. ❌ Slow callback with time budget
2. ❌ Callback that always returns false (no results)
3. ❌ Callback exceptions
4. ❌ Callback with various acceptance criteria
5. ❌ Interaction with tolerance pruning
6. ❌ Interaction with top-K limits

These will be addressed in task 0004.18.

## Recommendations

1. ✅ **Callback contract is correct** - clear semantics, predictable behavior
2. ⚠️ **Enhanced PHPDoc needed** - add detailed contract documentation
3. ✅ **Error handling is acceptable** - exception propagation is appropriate
4. ⚠️ **More edge case tests needed** - see task 0004.18
5. ✅ **Integration is sound** - PathFinderService wrapper adds proper validation

## Conclusion

The acceptance callback mechanism is **correctly implemented** with:
- ✅ Clear invocation timing (after target reached, before result recording)
- ✅ Well-defined return value semantics (true/false/null)
- ✅ Predictable behavior (search continues, no hidden side effects)
- ✅ Flexible integration (PathFinderService adds validation layer)
- ⚠️ Documentation gaps (PHPDoc needs enhancement)
- ⚠️ Test coverage gaps (edge cases need tests)

**Task 0004.17 complete with recommendations for improvements. Proceeding to 0004.18 for comprehensive testing.**

## References

- `src/Application/PathFinder/PathFinder.php` (lines 163-180, 243-251)
- `src/Application/Service/PathFinderService.php` (lines 208-274)
- `tests/Application/PathFinder/PathFinderTest.php` (lines 1190-1238)

