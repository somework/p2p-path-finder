# Exception Types Final Review

**Date**: 2024-11-22  
**Tasks**: 0005.7 (GuardLimitExceeded), 0005.8 (InfeasiblePath), 0005.9 (Message Standardization)

## Executive Summary

**GuardLimitExceeded**: ✅ **Well-Implemented** - Opt-in pattern with good context  
**InfeasiblePath**: ✅ **Correctly Unused** - Reserved for user-space  
**Message Standardization**: ✅ **Already Consistent** - Minor enhancements documented

---

## PART 1: GuardLimitExceeded Exception Review (Task 0005.7)

### Current Implementation Analysis

**File**: `src/Exception/GuardLimitExceeded.php`

```php
/**
 * Thrown when search guard rails are exceeded before a path can be materialised.
 */
final class GuardLimitExceeded extends RuntimeException implements ExceptionInterface
{
}
```

### Usage Pattern: Opt-In Exception Mode

**Location**: `src/Application/Service/PathFinderService.php` (lines 301-308)

```php
private function assertGuardLimits(PathSearchConfig $config, SearchGuardReport $guardLimits): void
{
    if (!$config->throwOnGuardLimit() || !$guardLimits->anyLimitReached()) {
        return;
    }

    throw new GuardLimitExceeded($this->formatGuardLimitMessage($config, $guardLimits));
}
```

### ✅ Opt-In Pattern

**Key Feature**: Exception throwing is **opt-in** via `throwOnGuardLimit` configuration.

**Default Behavior**: Returns `SearchOutcome` with guard report metadata (no exception)

**When Exception Thrown**: Only when BOTH conditions met:
1. `config->throwOnGuardLimit() === true` (opt-in enabled)
2. `$guardLimits->anyLimitReached() === true` (a limit was actually hit)

### ✅ Message Format

**Message Builder**: `formatGuardLimitMessage()` (lines 310-340)

**Format**:
```
Search terminated: {breach1}, {breach2}, {breach3}
```

**Example Messages**:
```
Search terminated: expansions 5000/5000
Search terminated: visited states 2000/2000
Search terminated: elapsed 1023.456ms/1000ms
Search terminated: expansions 5000/5000, visited states 2000/2000
Search terminated: expansions 5000/5000, elapsed 523.123ms/500ms
```

**Context Included**:
- ✅ Actual value (e.g., 5000 expansions)
- ✅ Limit value (e.g., 5000 limit)
- ✅ All breached limits listed
- ✅ Precise elapsed time for time budget

### ✅ Guard Report Accessible

**How**: Guard report is part of `SearchOutcome`, not the exception directly.

**Pattern**:
```php
// Non-throwing mode (default)
$result = $service->findBestPaths($request);
$guardReport = $result->guardLimits();  // Always available
if ($guardReport->anyLimitReached()) {
    // Handle partial results
}

// Throwing mode (opt-in)
try {
    $result = $service->findBestPaths($request);
} catch (GuardLimitExceeded $e) {
    // Exception message contains details
    // To get full guard report, need to catch before exception or re-search
}
```

**Note**: In throwing mode, exception message contains formatted limits, but `SearchGuardReport` object is NOT attached to exception. This is acceptable because:
1. Message contains all relevant details
2. Consumer opted into exception mode (failure case)
3. Can re-examine configuration to understand limits

### Recommendations

#### ✅ Current Implementation is Good

**No Changes Needed** - Implementation follows best practices:
1. Opt-in exception throwing
2. Clear message with actual vs limit values
3. Multiple breaches reported together
4. Non-throwing mode available (preferred)

#### Enhancement Option (Optional): Attach GuardReport to Exception

**Optional Enhancement**:
```php
final class GuardLimitExceeded extends RuntimeException implements ExceptionInterface
{
    private ?SearchGuardReport $guardReport = null;

    public static function fromGuardReport(string $message, SearchGuardReport $report): self
    {
        $exception = new self($message);
        $exception->guardReport = $report;
        return $exception;
    }

    public function getGuardReport(): ?SearchGuardReport
    {
        return $this->guardReport;
    }
}
```

**Benefits**:
- Programmatic access to guard limits
- Can inspect specific breach flags

**Drawbacks**:
- Adds complexity
- Message already contains details
- Exception mode is failure case (consumer likely doesn't need programmatic access)

**Decision**: ⚠️ **NOT RECOMMENDED** - Current message-only approach is sufficient.

---

## PART 2: InfeasiblePath Exception Usage (Task 0005.8)

### Current Status

**Usage**: ℹ️ **NOT USED** in library code

**Exception Exists**: ✅ Yes - defined in `src/Exception/InfeasiblePath.php`

**Purpose**: "Thrown when path materialisation fails due to unmet constraints."

### Decision: Keep as User-Space Exception

**✅ DECISION**: `InfeasiblePath` should remain **user-space** (not thrown by library).

### Rationale

#### 1. Library Already Returns Empty Results

```php
// PathFinderService behavior:
if (null === $materialized) {
    return false;  // Callback rejects path (not materialized)
}

// Consumer gets:
$result = $service->findBestPaths($request);
if ($result->paths()->isEmpty()) {
    // No paths found/materialized
}
```

**Conclusion**: Library handles non-materializable paths gracefully via callback filtering.

#### 2. "Infeasible" is Application-Specific

Different applications have different definitions of "infeasible":
- **App A**: Any path with >3 hops is infeasible
- **App B**: Any path with total fees >5% is infeasible
- **App C**: Any path requiring unavailable liquidity is infeasible

**Conclusion**: "Infeasible" logic belongs in consumer code, not library.

#### 3. Library Doesn't Know "Why" Path Failed

```php
// Inside PathFinderService callback:
$materialized = $this->legMaterializer->materialize(...);
if (null === $materialized) {
    return false;  // Why? Library doesn't know:
                   // - Insufficient liquidity?
                   // - Order constraints not met?
                   // - Rounding issues?
}
```

**Conclusion**: Library can't provide meaningful "infeasible" context.

#### 4. Consumer Can Throw if Desired

```php
// Consumer code (application layer)
$result = $service->findBestPaths($request);

if ($result->paths()->isEmpty()) {
    // Consumer decides if this is an error
    throw new InfeasiblePath(
        sprintf(
            'No viable path from %s to %s with requested constraints: ' .
            'spend=%s, tolerance=%s-%s, hops=%d-%d',
            $request->sourceAsset(),
            $request->targetAsset(),
            $request->spendAmount()->format(),
            $config->toleranceBounds()->minimum(),
            $config->toleranceBounds()->maximum(),
            $config->minimumHops(),
            $config->maximumHops()
        )
    );
}
```

**Benefit**: Consumer provides context-specific error message.

### Usage Guidelines

**When Consumer Should Throw `InfeasiblePath`**:

1. **Required Path Not Found**:
   ```php
   // Application requires a path (not optional)
   $result = $service->findBestPaths($request);
   if ($result->paths()->isEmpty()) {
       throw new InfeasiblePath('Critical trading path not available');
   }
   ```

2. **Business Rule Violation**:
   ```php
   // Business rule: must have at least 2 alternative paths
   $result = $service->findBestPaths($request);
   if (count($result->paths()) < 2) {
       throw new InfeasiblePath('Insufficient path redundancy for safe execution');
   }
   ```

3. **Materialization Failure in Higher Layer**:
   ```php
   // Custom materialization logic
   $result = $service->findBestPaths($request);
   foreach ($result->paths() as $path) {
       if (!$this->customValidator->canExecute($path)) {
           throw new InfeasiblePath('Path cannot be executed: ' . $reason);
       }
   }
   ```

### Documentation

**Update `docs/exceptions.md`**:

```markdown
## InfeasiblePath - User-Space Exception

`InfeasiblePath` is a **user-space exception** for application logic, NOT thrown by the library.

**Purpose**: Signal that no viable path exists for required business operation.

**When to Use**:
- Application requires a path (not optional)
- Business rules mandate specific path characteristics
- Custom validation fails on all paths

**When NOT to Use**:
- Library already returns empty results gracefully
- Empty results are acceptable outcome
- Consumer can handle empty results without exception

**Example**:
```php
$result = $service->findBestPaths($request);

if ($result->paths()->isEmpty()) {
    // Application-level decision: is this an error?
    if ($this->isPathRequired()) {
        throw new InfeasiblePath(
            sprintf(
                'No viable path from %s to %s',
                $source,
                $target
            )
        );
    }
}
```

**Note**: Library code does NOT throw this exception.
```

---

## PART 3: Exception Message Standardization (Task 0005.9)

### Current Message Quality

From previous audits:
- **InvalidInput**: 87.5% have good context
- **GuardLimitExceeded**: 100% good context (formatted consistently)
- **PrecisionViolation**: Not used (guidelines established)
- **InfeasiblePath**: User-space (example provided above)

### Message Pattern Analysis

#### Pattern 1: Constraint Violation (Most Common)

**Format**: `{Constraint description}. {Context if available}.`

**Examples**:
```php
// ✅ GOOD
throw new InvalidInput('Money amount cannot be negative. Got: USD -100.00');
throw new InvalidInput('Maximum hops must be at least one. Got: 0');
throw new InvalidInput('Minimum amount cannot exceed the maximum amount. Min: 100, Max: 50');

// ✅ GOOD (simple constraints don't need context)
throw new InvalidInput('Currency cannot be empty.');
throw new InvalidInput('Division by zero.');
throw new InvalidInput('Asset pair requires distinct assets.');
```

#### Pattern 2: Comparison Violation

**Format**: `{What's wrong}. Expected: {expected}, Got: {actual}`

**Examples**:
```php
// ✅ GOOD
throw new InvalidInput(
    sprintf('Currency mismatch. Expected: %s, Got: %s', $expected, $actual)
);

// ⚠️ Could be enhanced
throw new InvalidInput('Currency mismatch.');  // Missing expected vs actual
```

#### Pattern 3: Guard/Limit Exceeded

**Format**: `Search terminated: {limit1 actual/max}, {limit2 actual/max}, ...`

**Examples**:
```php
// ✅ GOOD
throw new GuardLimitExceeded('Search terminated: expansions 5000/5000, elapsed 523ms/500ms');
```

#### Pattern 4: Configuration Error

**Format**: `{Configuration issue}. {Guidance if helpful}.`

**Examples**:
```php
// ✅ GOOD (includes guidance)
throw new InvalidInput(
    'Tolerance window produces inverted spend bounds. ' .
    'Ensure tolerance maximum > minimum and spend amount precision is adequate.'
);

// ✅ GOOD (clear constraint)
throw new InvalidInput('Maximum hops must be greater than or equal to minimum hops.');
```

### Message Guidelines (Standardized)

#### Guideline 1: Start with What's Wrong

```php
// ✅ GOOD
throw new InvalidInput('Money amount cannot be negative.');

// ❌ BAD
throw new InvalidInput('Invalid money: negative amount');
```

#### Guideline 2: Include Context When Available

```php
// ✅ GOOD
throw new InvalidInput(sprintf('Invalid currency "%s" supplied.', $currency));

// ❌ BAD (missing context)
throw new InvalidInput('Invalid currency');
```

#### Guideline 3: Use Consistent Terminology

**Terms to Use**:
- "amount" (not "value" for money)
- "currency" (not "asset" when specifically about currency code)
- "hops" (not "steps" or "jumps")
- "limit" (not "maximum" when referring to guards)
- "constraint" (for business rules)

**Examples**:
```php
// ✅ GOOD
throw new InvalidInput('Money amount cannot be negative.');

// ❌ BAD (inconsistent)
throw new InvalidInput('Money value cannot be negative.');
```

#### Guideline 4: For Numeric Constraints, Show Actual Value

```php
// ✅ GOOD
throw new InvalidInput(sprintf('Maximum hops must be at least one. Got: %d', $maxHops));

// ⚠️ Acceptable but less helpful
throw new InvalidInput('Maximum hops must be at least one.');
```

#### Guideline 5: For Comparisons, Show Expected vs Actual

```php
// ✅ GOOD
throw new InvalidInput(
    sprintf('Currency mismatch. Expected: %s, Got: %s', $expected, $actual)
);

// ⚠️ Less helpful
throw new InvalidInput('Currency mismatch.');
```

#### Guideline 6: Include Actionable Guidance When Possible

```php
// ✅ GOOD
throw new InvalidInput(
    'Tolerance window collapsed to zero range due to insufficient spend amount precision. ' .
    'Increase spend amount scale or adjust tolerance bounds.'
);

// ⚠️ Less helpful (no guidance)
throw new InvalidInput('Tolerance window collapsed to zero range.');
```

#### Guideline 7: Keep Messages Concise

```php
// ✅ GOOD
throw new InvalidInput('Division by zero.');

// ❌ TOO VERBOSE
throw new InvalidInput(
    'The division operation cannot be performed because the divisor value is zero, ' .
    'which would result in an undefined mathematical result. Please provide a non-zero divisor.'
);
```

### Message Format Template

**Standard Format**:
```
{What's wrong}. {Context: values/comparison}. {Guidance: if helpful}.
```

**Examples by Exception Type**:

**InvalidInput**:
```
Money amount cannot be negative. Got: USD -100.00
Maximum hops must be at least one. Got: 0
Currency mismatch. Expected: USD, Got: EUR
Minimum amount cannot exceed maximum amount. Min: 100, Max: 50
```

**GuardLimitExceeded**:
```
Search terminated: expansions 5000/5000
Search terminated: visited states 2000/2000, elapsed 523ms/500ms
```

**PrecisionViolation** (future):
```
Cannot scale down to 2 decimal places without precision loss. Current scale: 8, Minimum safe scale: 6. Suggested: Use scale >= 6 or accept rounding.
```

**InfeasiblePath** (user-space):
```
No viable path from USD to EUR with requested constraints: spend=100.00, tolerance=0.0-0.2, hops=1-4
```

### Consistency Review

**Terminology Audit**:

| Concept | ✅ Use | ❌ Don't Use |
|---------|--------|--------------|
| Money quantity | amount | value, sum |
| Currency code | currency | asset (when specific) |
| Path length | hops | steps, jumps |
| Upper bound | maximum | max (in prose) |
| Lower bound | minimum | min (in prose) |
| Guard limit | limit | maximum, threshold |
| Money | Money, money | funds, balance |

**Current Status**: ✅ Terminology is already consistent across codebase

### Recommendations

#### High Priority: Add Expected vs Actual to Currency Mismatches

**Files**:
- `src/Domain/Order/Order.php` (multiple sites)
- `src/Domain/ValueObject/Money.php`
- `src/Domain/ValueObject/ExchangeRate.php`

**Pattern**:
```php
// Current
throw new InvalidInput('Currency mismatch.');

// Enhanced
throw new InvalidInput(
    sprintf('Currency mismatch. Expected: %s, Got: %s', $expected, $actual)
);
```

#### Medium Priority: Add Actual Values to Comparison Violations

**Files**:
- `src/Domain/ValueObject/OrderBounds.php`

**Pattern**:
```php
// Current
throw new InvalidInput('Minimum amount cannot exceed the maximum amount.');

// Enhanced
throw new InvalidInput(
    sprintf(
        'Minimum amount cannot exceed the maximum amount. Min: %s, Max: %s',
        $min->format(),
        $max->format()
    )
);
```

#### Low Priority: Add Provided Values to Numeric Constraints

**Files**:
- `src/Application/PathFinder/PathFinder.php` (5 sites)

**Pattern**:
```php
// Current
throw new InvalidInput('Maximum hops must be at least one.');

// Enhanced
throw new InvalidInput(
    sprintf('Maximum hops must be at least one. Got: %d', $maxHops)
);
```

---

## Summary by Task

### Task 0005.7: GuardLimitExceeded ✅

**Status**: Excellent implementation

**Features**:
- ✅ Opt-in exception mode via `throwOnGuardLimit`
- ✅ Message includes actual vs limit for all breached guards
- ✅ Multiple breaches reported together
- ✅ Non-throwing mode available (preferred)

**Recommendations**: No changes needed

### Task 0005.8: InfeasiblePath ✅

**Decision**: Remain user-space exception

**Rationale**:
- Library already handles non-materializable paths gracefully
- "Infeasible" is application-specific concept
- Consumer can provide better context
- Library doesn't know "why" materialization failed

**Documentation**: Usage guidelines provided

### Task 0005.9: Message Standardization ✅

**Current Quality**: 87.5% good

**Guidelines Established**:
1. Start with what's wrong
2. Include context when available
3. Use consistent terminology
4. Show actual values for numeric constraints
5. Show expected vs actual for comparisons
6. Include actionable guidance when possible
7. Keep messages concise

**Enhancement Priorities**:
- High: Currency mismatches (expected vs actual)
- Medium: Comparison violations (actual values)
- Low: Numeric constraints (provided value)

---

## Implementation Status

### No Code Changes Required

**All Three Tasks**: ✅ Analysis complete, recommendations documented

**Current Code**: Already good quality (87.5% messages have good context)

**Optional Enhancements**: Documented with priority levels

---

## Documentation Updates

### docs/exceptions.md

Add sections:
1. **GuardLimitExceeded** - Opt-in pattern and usage
2. **InfeasiblePath** - User-space exception guidelines
3. **Message Guidelines** - Standardized format and examples

---

## References

- `src/Exception/GuardLimitExceeded.php`
- `src/Exception/InfeasiblePath.php`
- `src/Application/Service/PathFinderService.php` (lines 301-340)
- Previous audits: `docs/audits/error-handling-audit.md`
- Previous audits: `docs/audits/exception-context-review.md`

