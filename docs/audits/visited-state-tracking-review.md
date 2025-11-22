# Visited State Tracking Review

**Date**: 2024-11-22  
**Tasks**: 0004.15, 0004.16  
**Reviewer**: AI Assistant

## Executive Summary

✅ **PASSED** - Visited state tracking is correctly implemented with proper cycle prevention and state counting.

The PathFinder uses a two-tier system for tracking visited states:
1. **Per-path cycle prevention**: Each search state tracks which nodes it has visited
2. **Global state registry**: Tracks best states per node across all paths

Both mechanisms work together to ensure efficiency and correctness.

## Architecture Overview

### Components

1. **SearchState**: Individual search state with per-path visited tracking
2. **SearchStateRegistry**: Global registry of best states per node
3. **SearchStateSignature**: Unique identifier for state properties
4. **SearchStateRecord**: Immutable record of (cost, hops, signature)
5. **SearchStateRecordCollection**: Manages records for a single node

## 1. Per-Path Cycle Prevention

### SearchState Visited Tracking

**File**: `src/Application/PathFinder/Search/SearchState.php`

**Mechanism** (lines 31, 227-234):
```php
/**
 * @var non-empty-array<string, bool>
 */
private readonly array $visited;

public function hasVisited(string $node): bool
{
    return isset($this->visited[$node]);
}
```

**Initialization** (line 72):
```php
// Bootstrap state marks starting node as visited
[$node => true]
```

**Transition** (lines 119-121):
```php
$visited = $this->visited;
$visited[$nextNode] = true;  // Mark next node as visited
```

**Purpose**: Prevents cycles within a single path.

**Example**:
- Path: USD → GBP → EUR
- `visited` array: `['USD' => true, 'GBP' => true, 'EUR' => true]`
- If search tries to go EUR → GBP, it's rejected because GBP is already in `visited`

**Status**: ✅ Correct
- Immutable copy-on-write semantics
- Each state has independent `visited` array
- Prevents cycles within individual paths

### Usage in PathFinder

**File**: `src/Application/PathFinder/PathFinder.php` (line 269)

```php
if ($state->hasVisited($nextNode)) {
    continue;  // Skip edge to prevent cycle
}
```

**Status**: ✅ Correct - cycles are prevented early

## 2. Global State Registry

### SearchStateRegistry

**File**: `src/Application/PathFinder/Search/SearchStateRegistry.php`

**Purpose**: Track the best states seen at each node across all search paths.

**Structure**:
```
SearchStateRegistry
  ├─ Node "USD" → SearchStateRecordCollection
  │    ├─ Signature "range:100-200|desired:150" → Record(cost:1.0, hops:0)
  │    └─ Signature "range:50-100|desired:75" → Record(cost:1.5, hops:1)
  ├─ Node "GBP" → SearchStateRecordCollection
  │    └─ Signature "range:80-160|desired:120" → Record(cost:0.8, hops:1)
  └─ ...
```

**Key Methods**:

#### `register(node, record, scale)` (lines 57-66)
```php
public function register(string $node, SearchStateRecord $record, int $scale): array
{
    $collection = $this->records[$node] ?? SearchStateRecordCollection::empty();
    [$newCollection, $delta] = $collection->register($record, $scale);
    
    $records = $this->records;
    $records[$node] = $newCollection;
    
    return [new self($records), $delta];  // Returns (new registry, delta)
}
```

**Delta Values**:
- `1`: New signature registered (increases visited state count)
- `0`: Existing signature updated or skipped (no count change)

**Status**: ✅ Correct - immutable, returns new instance + delta

#### `isDominated(node, record, scale)` (lines 68-77)
```php
public function isDominated(string $node, SearchStateRecord $record, int $scale): bool
{
    $collection = $this->records[$node] ?? null;
    
    if (null === $collection) {
        return false;  // No records yet, not dominated
    }
    
    return $collection->isDominated($record, $scale);
}
```

**Purpose**: Check if a candidate state is dominated by an existing state.

**Status**: ✅ Correct

#### `hasSignature(node, signature)` (lines 79-88)
```php
public function hasSignature(string $node, SearchStateSignature $signature): bool
{
    $collection = $this->records[$node] ?? null;
    
    if (null === $collection) {
        return false;
    }
    
    return $collection->hasSignature($signature);
}
```

**Purpose**: Check if a specific signature exists at a node (used for visited state limit).

**Status**: ✅ Correct

### SearchStateRecordCollection

**File**: `src/Application/PathFinder/Search/SearchStateRecordCollection.php`

**Structure**:
```php
/**
 * @var array<string, SearchStateRecord>
 */
private array $records;  // Indexed by signature value
```

**Registration Logic** (lines 40-61):
```php
public function register(SearchStateRecord $record, int $scale): array
{
    $signature = $record->signature();
    $key = $signature->value();
    $existing = $this->records[$key] ?? null;
    
    if (null === $existing) {
        // New signature
        $records = $this->records;
        $records[$key] = $record;
        return [new self($records), 1];  // Delta = 1 (new)
    }
    
    if ($record->dominates($existing, $scale)) {
        // Update with better record
        $records = $this->records;
        $records[$key] = $record;
        return [new self($records), 0];  // Delta = 0 (update)
    }
    
    return [$this, 0];  // Delta = 0 (skip, existing is better)
}
```

**Status**: ✅ Correct - properly tracks new vs. update

### SearchStateRecord Dominance

**File**: `src/Application/PathFinder/Search/SearchStateRecord.php` (lines 54-60)

```php
public function dominates(self $other, int $scale): bool
{
    $comparison = $this->scaleDecimal($this->cost, $scale)
        ->compareTo($this->scaleDecimal($other->cost, $scale));
    
    return $comparison <= 0 && $this->hops <= $other->hops();
}
```

**Dominance Rule**: Record A dominates Record B if:
- `A.cost ≤ B.cost` AND
- `A.hops ≤ B.hops`

**Example**:
- Record A: cost=1.0, hops=2 **dominates** Record B: cost=1.5, hops=3
- Record A: cost=1.0, hops=2 **does NOT dominate** Record B: cost=0.9, hops=3
- Record A: cost=1.0, hops=2 **does NOT dominate** Record B: cost=1.0, hops=1

**Status**: ✅ Correct - both cost and hops must be ≤

## 3. SearchStateSignature Uniqueness

### Purpose

**SearchStateSignature** uniquely identifies the "context" of a state at a node:
- Amount range (min/max spend)
- Desired amount
- Route signature (path taken)

**File**: `src/Application/PathFinder/Search/SearchStateSignature.php`

### Format

**Structure**: `label1:value1|label2:value2|...`

**Example**:
```
range:USD:100.000:200.000:3|desired:USD:150.000:3
```

### Construction

**Method 1: From String** (lines 67-70):
```php
SearchStateSignature::fromString('range:null|desired:null');
```

**Method 2: Compose from Segments** (lines 75-107):
```php
SearchStateSignature::compose([
    'range' => 'USD:100:200:3',
    'desired' => 'USD:150:3',
]);
```

### Validation (lines 30-62)

**Enforced Rules**:
1. ✅ Non-empty signature
2. ✅ No double delimiters (`||`)
3. ✅ No leading/trailing delimiters
4. ✅ Each segment has format `label:value`
5. ✅ Labels must be non-empty
6. ✅ Values must be non-empty

**Status**: ✅ Comprehensive validation prevents malformed signatures

### Equality and Comparison

**Equality** (lines 114-117):
```php
public function equals(self $other): bool
{
    return $this->value === $other->value;  // String comparison
}
```

**Ordering** (lines 119-122):
```php
public function compare(self $other): int
{
    return $this->value <=> $other->value;  // Lexicographic
}
```

**Status**: ✅ Deterministic - signatures with same value are equal

### Uniqueness Guarantee

**Uniqueness depends on**:
1. **Canonical string representation**: Same values → same string
2. **Deterministic composition**: Same segments → same signature
3. **No hash collisions**: Direct string comparison

**Status**: ✅ Signatures are unique per state context

## 4. Cycle Prevention Verification

### Two-Layer Prevention

#### Layer 1: Per-Path Cycles
**Location**: PathFinder line 269  
**Check**: `if ($state->hasVisited($nextNode)) { continue; }`  
**Purpose**: Prevent revisiting nodes within a single path

**Example Prevented**:
- Path: A → B → C → B (❌ rejected at C → B)

**Status**: ✅ Prevents all cycles within individual paths

#### Layer 2: Global Dominance Pruning
**Location**: PathFinder line 307  
**Check**: `if ($bestPerNode->isDominated($nextNode, $candidateRecord, self::SCALE)) { continue; }`  
**Purpose**: Prune states dominated by better states already seen

**Example Pruned**:
- State at B: cost=2.0, hops=3 (via A→C→B)
- Existing at B: cost=1.5, hops=2 (via A→B)
- New state is dominated, pruned

**Status**: ✅ Efficiently prunes dominated states

### Interaction Between Layers

**Scenario**: A → B → C, then A → C → B

1. **Path 1**: A → B → C
   - No issues, path completes
   - Registers state at B (from A) and C (from B)

2. **Path 2**: A → C → B
   - Tries to expand C → B
   - **Layer 1**: B not in this path's `visited` ✅ (different path)
   - **Layer 2**: Check if new state at B is dominated by existing
     - If cost/hops are worse → pruned
     - If cost/hops are better → registered (updates best state)

**Status**: ✅ Both layers work correctly together

## 5. State Count Accuracy

### Counting Logic

**File**: PathFinder lines 312-315, 319

```php
if (
    $visitedStates >= $this->maxVisitedStates
    && !$bestPerNode->hasSignature($nextNode, $signature)
) {
    $visitedGuardReached = true;
    continue;  // Don't expand if we're at limit and this is a new signature
}

[$bestPerNode, $delta] = $bestPerNode->register($nextNode, $candidateRecord, self::SCALE);
$visitedStates = max(0, $visitedStates + $delta);
```

**Delta Semantics**:
- `+1`: New signature at node (increases unique states)
- `+0`: Update existing signature (no new state)

**Initial Count** (PathFinder line 541):
```php
$bestPerNode = SearchStateRegistry::withInitial($source, $initialRecord);
// Initial visited states = 1 (source node)
```

**Status**: ✅ Count accurately tracks unique (node, signature) pairs

### Visited State Limit Enforcement

**Guard Check** (lines 312-316):
```php
if (
    $visitedStates >= $this->maxVisitedStates
    && !$bestPerNode->hasSignature($nextNode, $signature)
) {
    $visitedGuardReached = true;
    continue;
}
```

**Logic**:
- If at limit AND signature is new → skip expansion (don't add new state)
- If at limit AND signature exists → allow (update existing)

**Rationale**: Prevents runaway state explosion while allowing updates to existing states.

**Status**: ✅ Correctly enforces limit without preventing updates

## 6. Edge Cases Handled

### Case 1: Self-Loops
**Graph**: Node A has edge to itself (A → A)  
**Prevention**: `$state->hasVisited('A')` returns true  
**Result**: ✅ Self-loops rejected

### Case 2: Multiple Paths to Same Node
**Scenario**: Two paths reach node B  
- Path 1: A → B (cost=1.0, hops=1)
- Path 2: A → C → B (cost=1.5, hops=2)  

**Handling**:
- Path 1 registers state at B
- Path 2 checks dominance at B
- Path 1's state dominates → Path 2 pruned  
**Result**: ✅ Only better state retained

### Case 3: Same Node Different Costs
**Scenario**: Two signatures at same node  
- Signature 1: `range:100-200`, cost=1.0
- Signature 2: `range:150-250`, cost=1.2  

**Handling**:
- Both have different signatures
- Both registered in collection
- Visited count = 2 (one per unique signature)  
**Result**: ✅ Different contexts tracked separately

### Case 4: Update Better State
**Scenario**: Better path found to existing signature  
- Initial: cost=2.0, hops=3
- Update: cost=1.5, hops=2  

**Handling**:
- New record dominates existing
- Collection updates record
- Delta = 0 (update, not new)  
**Result**: ✅ State updated without increasing count

### Case 5: Visited State Limit Reached
**Scenario**: At limit with 1000 states  
**Handling**:
- New signature → rejected
- Existing signature → allowed (update)  
**Result**: ✅ Limit enforced correctly

## 7. Existing Test Coverage

### Core Unit Tests
✅ `SearchStateSignatureTest` (18 tests)  
✅ `SearchStateRegistryPropertyTest` (property-based, 48 seeds)  
✅ `SearchStateRegistryCloneTest`  

### Integration Tests
✅ `PathFinderTest` includes various graph structures  
✅ `PathFinderPropertyTest` with randomized scenarios  
✅ `PathFinderMetamorphicTest` with transformation invariants  

**Assessment**: Good coverage of core functionality.

## 8. Additional Tests Needed

While existing tests are comprehensive, additional edge case tests would be beneficial:

1. **Graph with many paths to same node** (stress test registry)
2. **Explicit cycle prevention verification** (A→B→C→A rejection)
3. **Same node reached via different costs** (multiple signatures)
4. **Visited state count accuracy** (verify count matches unique states)

These will be added in task 0004.16.

## Recommendations

1. ✅ **Current implementation is correct** - no logic issues found
2. ✅ **Cycle prevention is robust** - two-layer system works well
3. ✅ **State counting is accurate** - delta tracking is correct
4. ✅ **Signature uniqueness is guaranteed** - string-based equality
5. ✅ **Dominance pruning is efficient** - only keeps best states

## Conclusion

The visited state tracking system is **correctly implemented** with:
- ✅ Robust per-path cycle prevention
- ✅ Efficient global state registry with dominance pruning
- ✅ Unique state signatures with comprehensive validation
- ✅ Accurate state counting with delta tracking
- ✅ Proper enforcement of visited state limits
- ✅ Correct handling of all edge cases

**Task 0004.15 complete with no issues found. Proceeding to 0004.16 for additional integration tests.**

## References

- `src/Application/PathFinder/Search/SearchState.php`
- `src/Application/PathFinder/Search/SearchStateRegistry.php`
- `src/Application/PathFinder/Search/SearchStateSignature.php`
- `src/Application/PathFinder/Search/SearchStateRecord.php`
- `src/Application/PathFinder/Search/SearchStateRecordCollection.php`
- `src/Application/PathFinder/PathFinder.php` (lines 269, 307-319)
- `tests/Application/PathFinder/SearchStateSignatureTest.php`
- `tests/Application/PathFinder/Search/SearchStateRegistryPropertyTest.php`

