# Task: Decimal Arithmetic Consistency and Determinism Audit

## Context

The library has completed migration from BCMath to `Brick\Math\BigDecimal` for all arithmetic operations. The determinism guarantees are central to the library's value proposition:
- Canonical scale: 18 decimal places for tolerances, costs, and ratios (`PathFinder::SCALE`)
- Rounding mode: `RoundingMode::HALF_UP` everywhere
- Working precision: Extra scale for ratios (RATIO_EXTRA_SCALE = 4) and sums (SUM_EXTRA_SCALE = 2)
- Normalization: All BigDecimal values normalized to their target scale before serialization

Current status (per docs/decimal-strategy.md):
- All domain value objects use BigDecimal internally
- Search core uses BigDecimal for costs, products, ratios
- Serialization boundaries convert BigDecimal to numeric-string
- Test helper `DecimalMath` provides canonical test fixtures

This task audits the entire codebase to ensure no arithmetic bypasses BigDecimal.

## Problem

**Potential consistency risks:**
1. **Accidental float usage**: While BigDecimal is pervasive, are there any places where float arithmetic sneaks in?
2. **Inconsistent rounding**: All code should use `RoundingMode::HALF_UP`. Are there any places using other modes or PHP's default float rounding?
3. **Scale inconsistencies**: 
   - Do all tolerance calculations use SCALE (18)?
   - Do all cost calculations normalize properly?
   - Are working precision constants (RATIO_EXTRA_SCALE, SUM_EXTRA_SCALE) applied consistently?
4. **String arithmetic remnants**: Are there any remaining uses of `bcmath` functions, `bc*` calls, or manual string manipulation for numbers?
5. **Comparison operations**: Do all comparisons use BigDecimal::compareTo() or normalized values? Any direct string/float comparisons?
6. **Serialization determinism**: Do all numeric-string outputs use the same formatting (no trailing zeros inconsistency, locale issues)?
7. **Test fixture consistency**: Do all test fixtures use DecimalMath helper or create BigDecimal values directly?

## Proposed Changes

### 1. Grep audit for arithmetic operations

- **Search** for float literals in arithmetic: `/\d+\.\d+\s*[\+\-\*\/]/`
- **Search** for bcmath remnants: `bc(add|sub|mul|div|pow|comp|scale|sqrt|mod)`
- **Search** for PHP math functions that might bypass BigDecimal: `round()`, `ceil()`, `floor()`, `pow()`, `sqrt()`
- **Search** for string concatenation in numeric contexts: patterns like `$x . $y` where x/y are numbers
- **Search** for direct numeric string manipulation: `sprintf('%d')`, `number_format()`, `substr()` on amounts

### 2. Audit all RoundingMode usage

- **Verify** all `->toScale()` calls use `RoundingMode::HALF_UP` or explicitly document why another mode is needed
- **Search** for `RoundingMode::` to find all uses
- **Ensure** no PHP `round()` function calls (which has different tie-breaking than HALF_UP)

### 3. Audit scale usage across components

- **PathFinder**: Verify SCALE (18) is used for all tolerance/cost calculations
- **SearchState**: Verify cost normalization uses SCALE
- **CandidatePath**: Verify cost normalization uses SCALE
- **DecimalTolerance**: Verify uses SCALE (18) internally
- **Money**: Uses per-instance scale (correct) but verify arithmetic preserves scale correctly
- **ExchangeRate**: Uses per-instance scale (correct) but verify conversion scale is max(money.scale, rate.scale)
- **Working precision**: Audit RATIO_EXTRA_SCALE and SUM_EXTRA_SCALE usage in PathFinder

### 4. Audit comparison operations

- **Search** for `===`, `==`, `<`, `>`, `<=`, `>=` comparisons involving BigDecimal values
- **Ensure** all use `->compareTo()`, `->isEqualTo()`, `->isLessThan()`, etc.
- **Verify** no string comparisons of amounts (e.g., `$amount1 > $amount2` where both are strings)

### 5. Audit serialization boundaries

- **Verify** all `__toString()` or explicit string conversion of BigDecimal uses `->toScale(scale, HALF_UP)->__toString()`
- **Check** `jsonSerialize()` implementations for consistent formatting
- **Review** SerializesMoney trait usage
- **Ensure** no locale-dependent formatting (verify decimal separator is always '.')

### 6. Audit test fixtures

- **Search** tests for hardcoded numeric strings that might not match canonical precision
- **Ensure** DecimalMath helper is used consistently in tests
- **Verify** property test generators produce canonical numeric-strings

### 7. Cross-reference decimal-strategy.md

- **Verify** documented scale values match code constants:
  - PathFinder::SCALE == 18
  - PathFinder::RATIO_EXTRA_SCALE == 4
  - PathFinder::SUM_EXTRA_SCALE == 2
- **Ensure** documented rounding policy matches all code
- **Check** value object normalization rules match documentation

### 8. Add static analysis rules

- **Consider** custom PHPStan rules to detect:
  - Float literals in arithmetic contexts
  - String comparisons that might be numeric
  - Missing RoundingMode in toScale() calls
  - bcmath function calls

## Dependencies

- Independent task, but findings might affect domain model (task 0002)

## Effort Estimate

**M** (0.5-1 day)
- Grep/search audit: 1-2 hours
- Manual code review: 2-3 hours
- Test fixture review: 1 hour
- Documentation verification: 30 minutes
- Static analysis rules (if added): 1-2 hours

## Risks / Considerations

- **False positives**: Some legitimate uses of float (e.g., in test mocks, non-arithmetic contexts) might show up in searches
- **Legacy code**: If any remnants of BCMath are found, need migration plan that maintains determinism
- **Test brittleness**: Enforcing canonical formatting in all tests might make them more brittle if format changes
- **Performance**: Extra normalization steps add minimal overhead but should be measured in benchmarks

## Definition of Done

- [ ] Grep audit completed for float literals, bcmath, PHP math functions, string manipulation
- [ ] All RoundingMode usage verified to be HALF_UP or explicitly documented
- [ ] Scale usage verified across PathFinder, SearchState, CandidatePath, value objects
- [ ] All numeric comparisons verified to use BigDecimal methods, not operators
- [ ] All serialization boundaries verified to use consistent formatting
- [ ] All test fixtures verified to use DecimalMath or direct BigDecimal with canonical scale
- [ ] decimal-strategy.md verified to match implementation
- [ ] Optional: Custom PHPStan rules added to prevent future violations
- [ ] Documentation updated if any discrepancies found
- [ ] All existing tests pass
- [ ] Benchmarks rerun to verify no performance regression
- [ ] Infection mutation score maintained or improved

**Priority:** P1 – Release-blocking

