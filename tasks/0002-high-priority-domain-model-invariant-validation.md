# Task: Domain Model Invariant Validation and Edge Case Coverage

## Context

The domain layer contains immutable value objects and entities:
- Value Objects: `Money`, `ExchangeRate`, `OrderBounds`, `DecimalTolerance`, `ToleranceWindow`, `AssetPair`
- Entities: `Order`
- Policies: `FeePolicy`, `FeeBreakdown`

These types form the foundation of the library's correctness guarantees. They must:
- Validate invariants at construction time
- Handle edge cases gracefully (zero amounts, negative values, boundary conditions)
- Use BigDecimal consistently for all arithmetic
- Maintain immutability

Current validation includes basic checks, but edge case coverage needs comprehensive review.

## Problem

**Potential risks:**
1. **Scale boundary handling**: `Money` and `ExchangeRate` have `MAX_SCALE = 50`. What happens at scale = 50? Are there precision loss concerns at extreme scales?
2. **Zero and negative amounts**: 
   - `Money` can be zero - this is tested
   - Can `Money` be negative? If so, is this intentional? (e.g., for fee calculations)
   - `ExchangeRate` requires `> 0` - this is correct
   - `OrderBounds` can have min = max (single valid amount) - is this handled?
3. **Currency validation**: Asset codes are validated as 3-12 alphabetic characters. Are edge cases tested (empty, 2 chars, 13 chars, numbers, special chars)?
4. **Precision mismatches**: What happens when:
   - Adding `Money` with different scales?
   - Converting with `ExchangeRate` of different scale?
   - Operating on amounts where scale difference is extreme (2 vs 50)?
5. **BigDecimal edge cases**:
   - Very large numbers (approaching limits)
   - Very small numbers (approaching underflow)
   - Rounding at scale boundaries
6. **Fee policy edge cases**:
   - Fees larger than amounts
   - Zero fees
   - Negative fees (rebates?)
7. **Tolerance window validation**:
   - min = max tolerance (zero window) - currently validated
   - min > max (inverted) - need to verify rejection
   - Tolerance at boundaries (0, 1)
8. **Order consistency**:
   - Base/quote currency matching between AssetPair, ExchangeRate, OrderBounds
   - Bounds with inverted min/max
   - Bounds currencies not matching asset pair

## Proposed Changes

### 1. Audit and enhance Money validation

- **Review** negative amount handling:
  - Explicitly decide if negative amounts are allowed
  - Document the decision
  - Add validation or tests accordingly
- **Test** scale boundary cases:
  - Scale = 0 (integer amounts)
  - Scale = 50 (maximum)
  - Scale = -1, 51 (should reject)
- **Test** extreme value handling:
  - Very large amounts (e.g., "999999999999999999999999999.99")
  - Very small amounts (e.g., "0.00000000000000000001")
- **Test** arithmetic with scale mismatches:
  - Add Money(scale=2) + Money(scale=8)
  - Multiply by rates with different scales
  - Verify scale is preserved or appropriately derived

### 2. Audit and enhance ExchangeRate validation

- **Test** extreme rates:
  - Very small rates (e.g., "0.00000001" for low-value conversions)
  - Very large rates (e.g., "100000000.0")
- **Test** inversion edge cases:
  - Rate close to zero (should maintain precision)
  - Rate = 1.0 (identity, inversion should give 1.0)
- **Test** conversion with extreme amounts
- **Add** explicit test for same currency rejection

### 3. Audit and enhance OrderBounds validation

- **Test** boundary conditions:
  - min = max (single valid amount)
  - min > max (should reject)
  - min or max = zero
  - min or max negative (if not allowed)
- **Test** scale consistency with contained Money objects
- **Test** `contains()` method with:
  - Amount exactly at min/max
  - Amount just below/above bounds
  - Amount with different scale

### 4. Audit and enhance ToleranceWindow validation

- **Test** boundary tolerances:
  - min = 0, max = 0 (zero window)
  - min = 0, max close to 1 (e.g., 0.9999999999999999)
  - min = max (should be allowed or rejected explicitly)
  - min > max (should reject)
- **Verify** PathSearchConfig properly computes spend bounds from tolerance window
- **Test** tolerance window collapse detection (currently has validation)

### 5. Audit and enhance Order consistency validation

- **Verify** `assertConsistency()` catches all mismatches:
  - Bounds currency != base currency
  - Rate base currency != asset pair base
  - Rate quote currency != asset pair quote
- **Test** fee policy consistency:
  - Fee currencies match order currencies
  - Fee amounts within reasonable bounds
- **Add** test for orders with zero/inverted bounds

### 6. Document invariants explicitly

- **Add** @invariant PHPDoc annotations to all value objects describing their guarantees
- **Create** docs/domain-invariants.md listing all domain constraints
- **Document** valid ranges for all numeric fields

### 7. Add property-based tests for domain model

- **Expand** existing property tests or add new ones:
  - Money arithmetic properties (commutativity, associativity)
  - ExchangeRate conversion roundtrip (convert forth and back)
  - OrderBounds contains() consistency
  - Scale preservation laws

## Dependencies

- Independent task, but findings may inform task 0001 (Public API finalization)

## Effort Estimate

**M** (0.5-1 day)
- Value object audit: 2 hours
- Test implementation: 3-4 hours
- Documentation: 1-2 hours

## Risks / Considerations

- **Breaking changes**: If negative Money or other currently-allowed edge cases need to be rejected, this could break existing consumers. Consider deprecation path.
- **Performance**: Adding extensive validation at construction time might impact performance in hot paths. Measure if needed.
- **Over-validation**: Don't add validation that prevents legitimate use cases. Focus on actual invariant violations.

## Definition of Done

- [ ] All Money edge cases tested (zero, negative if allowed, extreme scales, extreme values)
- [ ] All ExchangeRate edge cases tested (extreme rates, inversion, conversions)
- [ ] All OrderBounds edge cases tested (min=max, inverted, zero, negative)
- [ ] All ToleranceWindow edge cases tested (zero, boundaries, inverted)
- [ ] Order consistency validation covers all mismatches
- [ ] Fee policy edge cases tested
- [ ] Domain invariants documented in docs/domain-invariants.md
- [ ] @invariant annotations added to all value objects
- [ ] Property-based tests added or expanded
- [ ] PHPStan/Psalm pass
- [ ] All tests pass including new edge case tests
- [ ] Infection mutation score not decreased

**Priority:** P1 – Release-blocking

