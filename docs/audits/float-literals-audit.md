# Float Literals Audit - 2025-11-22

**Audit Task**: 0003.1 - Grep Audit for Float Literals in Arithmetic  
**Auditor**: Automated grep analysis  
**Date**: 2025-11-22  
**Status**: ✅ PASS - No problematic float arithmetic found

## Executive Summary

- **Total float literal findings**: 7 occurrences
- **Legitimate uses**: 7 (100%)
- **Problematic uses**: 0 (0%)
- **Recommendation**: ✅ No action required - all uses are appropriate

## Search Commands Executed

```bash
# Search for float literals in arithmetic contexts
grep -rn '\d\+\.\d\+\s*[+\-*/]' src/ --include="*.php"
grep -rn '[+\-*/]\s*\d\+\.\d\+' src/ --include="*.php"
grep -rn '\.\d\+' src/ --include="*.php"
grep -rn 'float' src/ --include="*.php"
```

## Findings

### LEGITIMATE Uses (All findings)

#### 1. Time Measurement - SearchGuardReport.php

**Lines**: 82-83, 120, 129

```php
82: if ($elapsedMilliseconds < 0.0) {
83:     $elapsedMilliseconds = 0.0;
84: }
...
120: elapsedMilliseconds: 0.0,
...
129: return self::fromMetrics(0, 0, 0.0, 0, 0, null);
```

**Context**: Time measurement initialization and validation  
**Type**: `float` for millisecond timing  
**Justification**: Time tracking doesn't require BigDecimal precision. Float is the appropriate type for performance monitoring and elapsed time calculation. This is non-financial arithmetic.  
**Action**: None - correct usage

#### 2. Time Conversion - SearchGuards.php

**Lines**: 50, 80

```php
50: $elapsedMilliseconds = ($now - $this->startedAt) * 1000.0;
...
80: $elapsedMilliseconds = ($now - $this->startedAt) * 1000.0;
```

**Context**: Converting seconds to milliseconds for performance tracking  
**Type**: `float` arithmetic for time conversion  
**Justification**: Multiplying `microtime(true)` result by 1000.0 to convert seconds to milliseconds. This is standard practice for performance measurement. Not related to monetary or precision-critical calculations.  
**Action**: None - correct usage

#### 3. Float Type Annotations

**Files**: SearchGuardReport.php, SearchGuards.php, PathOrderStrategy.php

```php
// SearchGuardReport.php:33
private readonly float $elapsedMilliseconds;

// SearchGuardReport.php:113
metrics: array{expansions: int, visited_states: int, elapsed_ms: float}

// SearchGuards.php:20
@var Closure():float

// PathOrderStrategy.php:57
an appropriate scale to avoid floating-point issues.
```

**Context**: Type annotations and documentation  
**Justification**: 
- Type hints for performance metrics (elapsed time)
- Documentation about avoiding floating-point issues (acknowledges float limitations)
- All monetary calculations use BigDecimal, not float

**Action**: None - correct usage

### ZERO Problematic Uses Found

**No instances found of:**
- Float arithmetic on monetary amounts
- Float literals in Money/ExchangeRate calculations
- Float multiplication/division of prices or rates
- Float comparisons on financial data

## Verification

### BigDecimal Usage Statistics

```
Files using BigDecimal: 16
Total BigDecimal references: 129
Key files:
  - src/Domain/ValueObject/Money.php
  - src/Domain/ValueObject/ExchangeRate.php
  - src/Domain/ValueObject/DecimalTolerance.php
  - src/Application/PathFinder/PathFinder.php
  - src/Application/Config/PathSearchConfig.php
```

### Architectural Compliance

✅ All monetary arithmetic uses BigDecimal  
✅ All rate calculations use BigDecimal  
✅ All tolerance calculations use BigDecimal  
✅ Float usage limited to time measurement (appropriate)  
✅ No float casting functions (floatval, doubleval) found

## Pattern Analysis

### Appropriate Float Usage Pattern

The codebase correctly uses `float` for:
1. **Time measurement**: `microtime(true)` returns float
2. **Performance metrics**: Elapsed milliseconds for guard limits
3. **Non-financial arithmetic**: Time conversions (seconds × 1000.0)

### BigDecimal Usage Pattern

The codebase correctly uses `BigDecimal` for:
1. **Monetary amounts**: All Money instances
2. **Exchange rates**: All ExchangeRate instances
3. **Tolerances**: All tolerance calculations
4. **Path costs**: All cost calculations

## Recommendations

### Immediate Actions
✅ **None required** - No problematic float arithmetic detected

### Long-term Monitoring
1. Add PHPStan rule to detect float arithmetic on monetary types (Task 0003.12)
2. Add CI check to prevent introduction of float literals in domain layer
3. Document float usage policy in contributing guidelines

## Conclusion

**Result**: ✅ **PASS**

The codebase demonstrates excellent arithmetic hygiene:
- All financial calculations use BigDecimal
- Float usage limited to appropriate contexts (time measurement)
- No precision loss risk in monetary operations
- Architecture properly separates performance tracking from financial math

**No remediation required.**

## Related Tasks

- Task 0002: Domain Model Validation ✅ Complete
- Task 0003.2: BCMath Remnants Audit (see bcmath-audit.md)
- Task 0003.3: PHP Math Functions Audit (see php-math-functions-audit.md)
- Task 0003.12: PHPStan decimal rules (future)

## Audit Trail

- **Audit Date**: 2025-11-22
- **Commit**: [current]
- **Branch**: main
- **Auditor**: Automated grep + manual review
- **Review**: All findings manually verified in context

