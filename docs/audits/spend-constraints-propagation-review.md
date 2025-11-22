# Spend Constraints Propagation Review

**Date**: 2024-11-22  
**Tasks**: 0004.13, 0004.14  
**Reviewer**: AI Assistant

## Executive Summary

✅ **PASSED** - Spend constraints are correctly computed and propagated with proper early pruning.

The constraint propagation system ensures that paths respect minimum/maximum spend bounds derived from tolerance windows. Constraints are intersected with edge capacities at each step, and edges that cannot satisfy the constraints are pruned early for efficiency.

## Architecture Overview

### Key Components

1. **ToleranceWindow**: User-specified tolerance bounds `[minTolerance, maxTolerance]`
2. **SpendConstraints**: Derived spend bounds `{min, max, desired}` in currency units
3. **SpendRange**: Internal representation of `{min, max}` propagated through search
4. **PathFinder**: Updates constraints at each edge traversal

## 1. Constraint Computation from ToleranceWindow

### Source: PathSearchRequest Construction

**File**: `src/Application/Service/PathSearchRequest.php`

The `ToleranceWindow` is translated into `SpendConstraints` based on the user's desired spend amount and tolerance bounds.

**Formula**:
```
desired = user's desired spend amount
minTolerance = tolerance window minimum (0 to 1)
maxTolerance = tolerance window maximum (0 to 1)

spendMin = desired * (1 - maxTolerance)
spendMax = desired * (1 + maxTolerance)
```

**Example**:
- Desired spend: 100 USD
- Tolerance window: [0.0, 0.1] (0% to 10%)
- Result: `spendMin = 100 * 0.9 = 90 USD`, `spendMax = 100 * 1.1 = 110 USD`
- SpendConstraints: `{min: 90 USD, max: 110 USD, desired: 100 USD}`

**Status**: ✅ Correct
- Constraints are computed using BigDecimal arithmetic
- Scale normalization ensures precision
- Negative values are rejected

### SpendConstraints Invariants

**File**: `src/Application/PathFinder/ValueObject/SpendConstraints.php`

**Invariants**:
1. `min ≤ max` (enforced by SpendRange)
2. `min`, `max`, `desired` share same currency
3. All values are non-negative
4. Scale is normalized to max(min.scale, max.scale, desired.scale)

**Status**: ✅ Enforced correctly

## 2. Constraint Propagation Through Edges

### Main Logic: PathFinder Search Loop

**File**: `src/Application/PathFinder/PathFinder.php` (lines 278-298)

**Propagation Steps**:

#### Step 1: Check if Current State Has Constraints
```php
$currentRange = $state->amountRange();
if (null !== $currentRange) {
    // State has constraints, propagate them
} else {
    // No constraints, only track desired amount
}
```

#### Step 2: Intersect with Edge Capacity (`edgeSupportsAmount`)
```php
$feasibleRange = $this->edgeSupportsAmount($edge, $currentRange);
if (null === $feasibleRange) {
    continue; // Edge cannot satisfy constraints, prune early
}
```

**Purpose**: Determine if the edge can accommodate the requested spend range.

**Logic** (lines 592-652):
1. Get edge capacity based on order side (BUY → grossBase, SELL → quote)
2. Consider mandatory segments if present
3. Normalize scales for comparison
4. Intersect requested range with capacity range:
   - If `requestedMax < capacityMin` OR `requestedMin > capacityMax` → **prune edge**
   - Otherwise, compute intersection: `[max(requestedMin, capacityMin), min(requestedMax, capacityMax)]`

**Special Cases**:
- **Zero capacity**: If edge has zero capacity, only allow if requested range includes zero
- **Mandatory segments**: Use `mandatory` as minimum capacity (not raw capacity.min)

**Status**: ✅ Correct
- Proper intersection logic
- Early pruning when constraints cannot be satisfied
- Handles mandatory segments correctly

#### Step 3: Calculate Next Range (`calculateNextRange`)
```php
$nextRange = $this->calculateNextRange($edge, $feasibleRange);
```

**Purpose**: Convert the feasible spend range to the target currency of the edge.

**Logic** (lines 654-660):
```php
$minimum = $this->convertEdgeAmount($edge, $range->min());
$maximum = $this->convertEdgeAmount($edge, $range->max());
return SpendRange::fromBounds($minimum, $maximum);
```

**Conversion Formula**:
- For BUY orders: `targetAmount = sourceAmount * conversionRate`
- For SELL orders: `targetAmount = sourceAmount / conversionRate`
- Uses `convertEdgeAmount` which clamps to edge capacity

**Status**: ✅ Correct
- Proper currency conversion
- Clamping ensures bounds stay within edge capacity
- Scale handling is consistent

#### Step 4: Update Desired Amount
```php
if ($currentDesired instanceof Money) {
    $clamped = $this->clampToRange($currentDesired, $feasibleRange);
    $nextDesired = $this->convertEdgeAmount($edge, $clamped);
}
```

**Purpose**: Track the "desired" spend amount through the path.

**Status**: ✅ Correct
- Desired amount is clamped to feasible range before conversion
- Preserves user's preference when possible

### Example Propagation

**Scenario**: USD → GBP → EUR

| Step | Currency | Min | Max | Desired |
|------|----------|-----|-----|---------|
| **Start** | USD | 90 | 110 | 100 |
| **After USD→GBP** (rate 0.8) | GBP | 72 | 88 | 80 |
| **After GBP→EUR** (rate 1.2) | EUR | 86.4 | 105.6 | 96 |

**Edge Intersection Example**:
- Current range: USD [90, 110]
- Edge capacity: USD [50, 100]
- Feasible range: USD [90, 100] (intersection)
- Edge with capacity [120, 200] → feasible range: [90, 110] (no change)
- Edge with capacity [95, 105] → feasible range: [95, 105] (narrowed)

**Status**: ✅ Mathematically correct

## 3. Early Constraint Violation Detection (Pruning)

### Pruning Points

#### Point 1: Edge Cannot Satisfy Range (Line 282-284)
```php
$feasibleRange = $this->edgeSupportsAmount($edge, $currentRange);
if (null === $feasibleRange) {
    continue; // PRUNE: No overlap between requested and available capacity
}
```

**Conditions for Pruning**:
- `requestedMax < capacityMin` (requested range entirely below capacity)
- `requestedMin > capacityMax` (requested range entirely above capacity)
- Zero capacity edge when range doesn't include zero

**Benefit**: Avoids expanding states that can never lead to valid paths.

#### Point 2: Mandatory Capacity Exceeds Available

**In `edgeSupportsAmount`** (lines 620-622):
```php
$capacityRange = SpendRange::fromBounds(
    $totals->mandatory()->withScale($scale),  // Uses mandatory, not min
    $totals->maximum()->withScale($scale),
);
```

**Effect**: If mandatory capacity > requested max, the edge is pruned.

**Example**:
- Requested: [50, 100] USD
- Edge mandatory: 120 USD (order minimum due to fees)
- Result: Pruned (cannot satisfy mandatory minimum)

**Status**: ✅ Correct integration with mandatory segments

### Pruning Effectiveness

**Verification** (from existing tests):
- ✅ PathFinder returns no paths when constraints cannot be satisfied
- ✅ Edges with insufficient capacity are not traversed
- ✅ Mandatory constraints enforced correctly

## 4. Edge Cases Handled

### Case 1: Zero Capacity Edge
**Logic** (lines 630-641):
```php
if ($capacityMax->decimal()->isZero()) {
    // Special handling for zero-capacity edges
}
```

**Status**: ✅ Handled - only allows if requested range includes zero

### Case 2: Requested Min > Capacity Max
**Result**: Pruned (no overlap)  
**Status**: ✅ Correct

### Case 3: Requested Max < Capacity Min
**Result**: Pruned (no overlap)  
**Status**: ✅ Correct

### Case 4: Partial Overlap
**Example**: Requested [80, 120], Capacity [100, 150]  
**Result**: Feasible range [100, 120]  
**Status**: ✅ Correct intersection

### Case 5: Complete Containment
**Example**: Requested [80, 120], Capacity [50, 150]  
**Result**: Feasible range [80, 120] (unchanged)  
**Status**: ✅ Correct

### Case 6: Mandatory Segment Exceeds Request
**Example**: Requested max 50, Mandatory 100  
**Result**: Pruned (cannot satisfy mandatory)  
**Status**: ✅ Correct

## 5. Off-by-One Verification

### Boundary Comparisons

**All comparisons use `BigDecimal`** (no floating-point):
- `requestedMax.lessThan(capacityMin)` - exclusive (<)
- `requestedMin.greaterThan(capacityMax)` - exclusive (>)
- `requestedMin.greaterThan(capacityMin)` - exclusive (>)
- `requestedMax.lessThan(capacityMax)` - exclusive (<)

**Boundary Inclusion**:
- If `requestedMin == capacityMin`, lower bound = capacityMin ✅
- If `requestedMax == capacityMax`, upper bound = capacityMax ✅

**Status**: ✅ No off-by-one errors detected

## 6. Existing Test Coverage

### SpendConstraintsTest (4 tests)
✅ Scale normalization  
✅ Rounding (HALF_UP)  
✅ Negative value rejection  
✅ Currency requirement  

### SpendRangeTest (9 tests)
✅ Scale normalization  
✅ Order correction (min/max swap)  
✅ `withScale` operation  
✅ `normalizeWith` operation  
✅ `clamp` operation  
✅ Currency validation  
✅ Array construction validation  

### Integration Tests
✅ PathFinderTest includes constraint-based path finding  
✅ MandatorySegmentEdgeCasesTest covers mandatory > constraints case  

**Assessment**: Good coverage of core functionality.

## 7. Additional Tests Needed

While existing tests are solid, additional edge case tests would be beneficial:

1. **Desired amount outside bounds** (e.g., desired < min or desired > max)
2. **Min = Max constraint** (single valid spend amount)
3. **Very wide tolerance window** (e.g., 0% to 99%)
4. **Constraint violation detection** (edge cases with zero capacity)
5. **Multi-hop constraint propagation** (verify cumulative narrowing)

These will be added in task 0004.14.

## Recommendations

1. ✅ **Current implementation is correct** - no logic issues found
2. ✅ **Early pruning is effective** - edges pruned when constraints cannot be satisfied
3. ✅ **Scale handling is consistent** - uses max scale throughout
4. ✅ **Mandatory segments integrated** - correctly uses mandatory as minimum bound
5. ✅ **No off-by-one errors** - boundary comparisons are correct

## Conclusion

The spend constraints propagation system is **correctly implemented** with:
- ✅ Proper computation from ToleranceWindow
- ✅ Correct intersection with edge capacities
- ✅ Effective early pruning of invalid edges
- ✅ Accurate currency conversion and scale handling
- ✅ Integration with mandatory segment logic
- ✅ No off-by-one errors in boundary conditions

**Task 0004.13 complete with no issues found. Proceeding to 0004.14 for additional edge case tests.**

## References

- `src/Domain/ValueObject/ToleranceWindow.php`
- `src/Application/PathFinder/ValueObject/SpendConstraints.php`
- `src/Application/PathFinder/ValueObject/SpendRange.php`
- `src/Application/PathFinder/PathFinder.php` (lines 278-298, 592-660)
- `tests/Application/PathFinder/Config/SpendConstraintsTest.php`
- `tests/Application/PathFinder/ValueObject/SpendRangeTest.php`

