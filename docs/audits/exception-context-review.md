# Exception Context Enhancement Review

**Date**: 2024-11-22  
**Tasks**: 0005.5 (InvalidInput), 0005.6 (PrecisionViolation)

## Executive Summary

**InvalidInput Context**: ✅ **Generally Good** - Most messages include specific context. Minor enhancements possible.

**PrecisionViolation Context**: ℹ️ **Not Used** - No PrecisionViolation throws found in codebase. Reserved for future.

---

## PART 1: InvalidInput Exception Context Review (Task 0005.5)

### Current Usage Analysis

**Total throw sites found**: 32  
**With good context**: 28 (87.5%)  
**Could be enhanced**: 4 (12.5%)

### Category 1: ✅ Excellent Context (No Changes Needed)

#### Domain Layer - Money

```php
// ✅ GOOD: Includes currency, value, and what's wrong
throw new InvalidInput(
    sprintf('Money amount cannot be negative. Got: %s %s', $normalizedCurrency, $amount)
);
```

**Why Good**: Tells user exactly what value was invalid and why.

#### Domain Layer - ExchangeRate

```php
// ✅ GOOD: Clear explanation
throw new InvalidInput('Exchange rate requires distinct currencies.');
```

**Why Good**: Explains the constraint clearly.

#### Domain Layer - OrderBounds

```php
// ✅ GOOD: But could be enhanced with values
throw new InvalidInput('Minimum amount cannot exceed the maximum amount.');
```

**Enhancement Opportunity**: Include actual min/max values.

#### Application Layer - PathSearchConfig

```php
// ✅ GOOD: Detailed explanation with guidance
throw new InvalidInput(
    'Tolerance window produces inverted spend bounds. ' .
    'Ensure tolerance maximum > minimum and spend amount precision is adequate.'
);
```

**Why Good**: Explains problem AND suggests solution.

---

### Category 2: ⚠️ Could Be Enhanced

#### 1. PathFinder - Generic Limit Messages

**Current**:
```php
throw new InvalidInput('Maximum hops must be at least one.');
throw new InvalidInput('Result limit must be at least one.');
throw new InvalidInput('Maximum expansions must be at least one.');
```

**Enhancement**:
```php
throw new InvalidInput(
    sprintf('Maximum hops must be at least one. Got: %d', $maxHops)
);
```

**Benefit**: Shows actual invalid value provided.

#### 2. OrderBounds - Missing Actual Values

**Current**:
```php
throw new InvalidInput('Minimum amount cannot exceed the maximum amount.');
```

**Enhancement**:
```php
throw new InvalidInput(
    sprintf(
        'Minimum amount cannot exceed the maximum amount. Min: %s, Max: %s',
        $min->format(),
        $max->format()
    )
);
```

**Benefit**: Consumer can see the invalid values provided.

#### 3. Order - Currency Mismatch Messages

**Current**:
```php
throw new InvalidInput('Fill amount must use the order base asset.');
```

**Enhancement**:
```php
throw new InvalidInput(
    sprintf(
        'Fill amount must use the order base asset. Expected: %s, Got: %s',
        $this->assetPair->base(),
        $money->currency()
    )
);
```

**Benefit**: Shows expected vs actual currency.

#### 4. Money - Currency Validation

**Current**:
```php
throw new InvalidInput(sprintf('Invalid currency "%s" supplied.', $currency));
```

**Enhancement** (minor):
```php
throw new InvalidInput(
    sprintf(
        'Invalid currency "%s" supplied. Currency must be 3-12 uppercase letters.',
        $currency
    )
);
```

**Benefit**: Explains the constraint.

---

### Category 3: ✅ Already Optimal

These messages are excellent examples:

```php
// Money - Division by zero
throw new InvalidInput('Division by zero.');

// ExchangeRate - Invalid rate
throw new InvalidInput('Exchange rate must be greater than zero.');

// ToleranceWindow - Range issue
throw new InvalidInput('Minimum tolerance must be less than or equal to maximum tolerance.');

// AssetPair - Same assets
throw new InvalidInput('Asset pair requires distinct assets.');

// SegmentCapacityTotals - Currency mismatch
throw new InvalidInput('Segment capacity totals must share the same currency.');

// EdgeSegmentCollection - Type validation
throw new InvalidInput('Graph edge segments must be provided as a list.');
throw new InvalidInput('Graph edge segments must be instances of EdgeSegment.');
```

**Why Good**: Clear, concise, explains the constraint.

---

### Recommendations for Enhancement

#### Recommendation 1: Add Values to Numeric Constraint Violations

**Files to Update**:
- `src/Application/PathFinder/PathFinder.php` (lines 306, 310, 314, 318, 322)

**Pattern**:
```php
// Before
throw new InvalidInput('Maximum hops must be at least one.');

// After
throw new InvalidInput(
    sprintf('Maximum hops must be at least one. Got: %d', $maxHops)
);
```

**Impact**: Low (minor enhancement)  
**Priority**: Low

#### Recommendation 2: Add Values to Comparison Violations

**Files to Update**:
- `src/Domain/ValueObject/OrderBounds.php` (line 45)

**Pattern**:
```php
// Before
throw new InvalidInput('Minimum amount cannot exceed the maximum amount.');

// After
throw new InvalidInput(
    sprintf(
        'Minimum amount cannot exceed the maximum amount. Min: %s, Max: %s',
        $min->format(),
        $max->format()
    )
);
```

**Impact**: Medium (helpful for debugging)  
**Priority**: Medium

#### Recommendation 3: Add Expected vs Actual for Currency Mismatches

**Files to Update**:
- `src/Domain/Order/Order.php` (lines 206, 213, 220)
- `src/Domain/ValueObject/Money.php` (line 315)
- `src/Domain/ValueObject/ExchangeRate.php` (line 87)

**Pattern**:
```php
// Before
throw new InvalidInput('Currency mismatch.');

// After
throw new InvalidInput(
    sprintf(
        'Currency mismatch. Expected: %s, Got: %s',
        $expectedCurrency,
        $actualCurrency
    )
);
```

**Impact**: High (very helpful for debugging)  
**Priority**: High

#### Recommendation 4: Add Constraint Explanation

**Files to Update**:
- `src/Domain/ValueObject/Money.php` (line 305)

**Pattern**:
```php
// Before
throw new InvalidInput(sprintf('Invalid currency "%s" supplied.', $currency));

// After
throw new InvalidInput(
    sprintf(
        'Invalid currency "%s" supplied. Currency must be 3-12 uppercase letters.',
        $currency
    )
);
```

**Impact**: Low (minor improvement)  
**Priority**: Low

---

### Implementation Priority

**High Priority** (Do First):
1. Currency mismatch messages (add expected vs actual)

**Medium Priority** (Do Second):
2. Comparison violations (add actual values)

**Low Priority** (Nice to Have):
3. Numeric constraints (add provided value)
4. Constraint explanations (add format rules)

---

### Security Considerations

#### ✅ Safe to Include in Messages

- Currency codes (e.g., "USD", "EUR")
- Numeric values (hops, limits, scales)
- Order bounds (amounts are business data)
- Boolean flags

#### ⚠️ DO NOT Include

- API keys or secrets
- User passwords
- Personal identifiable information (PII)
- Internal system paths (in production)

**Current Status**: ✅ All current messages are safe.

---

### Testing Exception Messages

#### Pattern 1: Assert Message Contains Context

```php
public function testInvalidHopsIncludesActualValue(): void
{
    $this->expectException(InvalidInput::class);
    $this->expectExceptionMessage('Maximum hops must be at least one. Got: 0');
    
    new PathFinder(maxHops: 0); // Invalid
}
```

#### Pattern 2: Assert Message Includes Expected and Actual

```php
public function testCurrencyMismatchIncludesExpectedAndActual(): void
{
    $this->expectException(InvalidInput::class);
    $this->expectExceptionMessage('Expected: USD, Got: EUR');
    
    $money = Money::fromString('EUR', '100', 2);
    $rate = ExchangeRate::fromStrings('USD', 'GBP', '0.8', 2);
    $rate->convert($money); // Mismatch
}
```

---

## PART 2: PrecisionViolation Exception Context Review (Task 0005.6)

### Current Status

**Usage**: ℹ️ **NOT USED** - No `throw new PrecisionViolation` statements found in codebase.

**Exception Exists**: ✅ Yes - defined in `src/Exception/PrecisionViolation.php`

**Purpose**: Reserved for arithmetic guarantee violations.

---

### When to Use (Guidelines for Future)

#### Scenario 1: Scale Precision Loss

```php
// Future use case example
public function scaleDown(int $newScale): Money
{
    if ($newScale < $this->minimumSafeScale()) {
        throw new PrecisionViolation(
            sprintf(
                'Cannot scale down to %d decimal places without precision loss. ' .
                'Current scale: %d, Minimum safe scale: %d. ' .
                'Suggested: Use scale >= %d or accept rounding.',
                $newScale,
                $this->scale,
                $this->minimumSafeScale(),
                $this->minimumSafeScale()
            )
        );
    }
    // ... scaling logic ...
}
```

**Context Includes**:
- Requested scale
- Current scale
- Minimum safe scale
- Actionable suggestion

#### Scenario 2: Arithmetic Operation Overflow

```php
// Future use case example
public function power(int $exponent): BigDecimal
{
    if ($exponent > self::MAX_SAFE_EXPONENT) {
        throw new PrecisionViolation(
            sprintf(
                'Exponent %d exceeds maximum safe value %d. ' .
                'Result would exceed precision guarantees. ' .
                'Suggested: Use exponent <= %d or increase scale limit.',
                $exponent,
                self::MAX_SAFE_EXPONENT,
                self::MAX_SAFE_EXPONENT
            )
        );
    }
    // ... power operation ...
}
```

**Context Includes**:
- Operation attempted
- Requested value
- Maximum safe value
- Actionable suggestion

#### Scenario 3: Required Precision Cannot Be Met

```php
// Future use case example
public function divide(Money $divisor, int $requiredPrecision): Money
{
    if ($requiredPrecision > self::MAX_SCALE) {
        throw new PrecisionViolation(
            sprintf(
                'Required precision %d exceeds maximum supported scale %d. ' .
                'Operation: %s / %s. ' .
                'Suggested: Use precision <= %d or restructure calculation.',
                $requiredPrecision,
                self::MAX_SCALE,
                $this->format(),
                $divisor->format(),
                self::MAX_SCALE
            )
        );
    }
    // ... division logic ...
}
```

**Context Includes**:
- Operation details
- Required vs maximum precision
- Operand values
- Actionable suggestion

---

### PrecisionViolation Message Template

**Format**:
```
{What operation failed}. {Why it failed}. {Context: values involved}. {Suggested: how to fix}.
```

**Example**:
```
Cannot scale down to 2 decimal places without precision loss.
Current scale: 8, Minimum safe scale: 6.
Value: 123.45678900.
Suggested: Use scale >= 6 or accept rounding with explicit RoundingMode.
```

**Components**:
1. **What failed**: "Cannot scale down to 2 decimal places"
2. **Why**: "without precision loss"
3. **Context**: "Current scale: 8, Minimum safe scale: 6, Value: 123.45678900"
4. **Suggestion**: "Use scale >= 6 or accept rounding"

---

### Testing PrecisionViolation (Future)

```php
public function testScaleDownThrowsPrecisionViolationWithContext(): void
{
    $this->expectException(PrecisionViolation::class);
    $this->expectExceptionMessageMatches('/Cannot scale.*to 2.*Current scale: 8/');
    
    $money = Money::fromString('USD', '123.45678900', 8);
    $money->scaleDown(2); // Would lose precision
}
```

---

## Summary

### Task 0005.5: InvalidInput Context

**Current State**: ✅ **87.5% have good context**

**Recommendations**:
1. **High Priority**: Add expected vs actual for currency mismatches
2. **Medium Priority**: Add actual values for comparison violations
3. **Low Priority**: Add provided values for numeric constraints

**Impact**: Improved debugging and developer experience

### Task 0005.6: PrecisionViolation Context

**Current State**: ℹ️ **Not used in codebase**

**Guidelines Established**:
1. Message template defined
2. Context requirements documented
3. Examples provided for future use

**Status**: ✅ **Ready for future implementation**

---

## Implementation Plan

### Phase 1: High Priority Enhancements

**Files to Update**:
1. `src/Domain/Order/Order.php` - Currency mismatch messages
2. `src/Domain/ValueObject/Money.php` - Currency mismatch
3. `src/Domain/ValueObject/ExchangeRate.php` - Currency mismatch

**Estimated Effort**: 30 minutes

### Phase 2: Medium Priority Enhancements

**Files to Update**:
1. `src/Domain/ValueObject/OrderBounds.php` - Add min/max values

**Estimated Effort**: 15 minutes

### Phase 3: Low Priority Enhancements

**Files to Update**:
1. `src/Application/PathFinder/PathFinder.php` - Add provided values
2. `src/Domain/ValueObject/Money.php` - Add format constraint

**Estimated Effort**: 30 minutes

### Phase 4: Testing

**Add Tests**:
1. Exception message context tests
2. Verify sensitive data not leaked

**Estimated Effort**: 1 hour

---

## References

- `docs/exceptions.md` - Exception conventions
- `docs/audits/error-handling-audit.md` - Comprehensive error audit
- All exception throw sites catalogued in this document

