# Exception Handling Conventions

**Version**: 1.0  
**Last Updated**: 2024-11-22

## Overview

This document establishes clear conventions for error handling in the P2P Path Finder library. Following these conventions ensures consistent, predictable error behavior across the codebase.

## Core Principle

**Fail Fast, Fail Clearly**: Invalid states should be detected immediately and reported with clear, actionable error messages.

## Exception Hierarchy

```
Throwable
  └─ ExceptionInterface (marker interface for all library exceptions)
      ├─ InvalidInput extends InvalidArgumentException
      │   Use: Malformed or unsupported input from consumers
      │
      ├─ GuardLimitExceeded extends RuntimeException
      │   Use: Search guard rails exceeded before completion
      │
      ├─ PrecisionViolation extends RuntimeException
      │   Use: Arithmetic guarantees cannot be upheld
      │
      └─ InfeasiblePath extends RuntimeException
          Use: Path cannot be materialized (reserved for future use)
```

**All library exceptions** implement `ExceptionInterface` for easy catch-all handling:
```php
try {
    $result = $pathFinder->findBestPaths(...);
} catch (ExceptionInterface $e) {
    // Handle all library exceptions
}
```

---

## Convention 1: Throw Exceptions for Invariant Violations

### When to Use

**Throw exceptions when**:
- Input violates documented constraints
- Invariants would be broken
- Configuration is invalid
- State is inconsistent

### Exception Type: `InvalidInput`

**Use `InvalidInput` for**:
- Invalid constructor parameters
- Constraint violations
- Type/format errors
- Boundary violations

### Examples

#### Domain Layer

```php
// Money: negative amount (invariant violation)
if ($decimal->isNegative()) {
    throw new InvalidInput(
        sprintf('Money amount cannot be negative. Got: %s %s', $currency, $amount)
    );
}

// ExchangeRate: same base and quote (invariant violation)
if (0 === strcasecmp($baseCurrency, $quoteCurrency)) {
    throw new InvalidInput('Exchange rate requires distinct currencies.');
}

// OrderBounds: min > max (invariant violation)
if ($min->greaterThan($max)) {
    throw new InvalidInput('Minimum amount cannot exceed the maximum amount.');
}
```

#### Application Layer

```php
// PathSearchConfig: invalid hop configuration
if ($maximumHops < $minimumHops) {
    throw new InvalidInput('Maximum hops must be greater than or equal to minimum hops.');
}

// PathFinder: invalid result limit
if ($topK < 1) {
    throw new InvalidInput('Result limit must be at least one.');
}
```

### Guidelines

✅ **DO**:
- Throw immediately when constraint violated
- Include specific values in message
- Use sprintf for formatted messages
- Be descriptive about what's wrong

❌ **DON'T**:
- Defer validation to later
- Use generic messages like "Invalid input"
- Catch and suppress without logging
- Return null for invariant violations

---

## Convention 2: Return Null for Optional/Not-Found Scenarios

### When to Use

**Return null when**:
- Value is genuinely optional
- Item not found is a valid outcome
- Operation not applicable (vs invalid)

### Examples

```php
// PathFinder: no pruning needed (no best cost known yet)
private function maxAllowedCost(?BigDecimal $bestTargetCost): ?BigDecimal
{
    if (null === $bestTargetCost) {
        return null;  // Not an error - pruning not applicable
    }
    return $bestTargetCost->multipliedBy($this->toleranceAmplifier);
}

// PathFinder: edge doesn't support amount range
private function edgeSupportsAmount(GraphEdge $edge, SpendRange $range): ?SpendRange
{
    // ... intersection logic ...
    
    if ($requestedMax->lessThan($capacityMin) || $requestedMin->greaterThan($capacityMax)) {
        return null;  // No intersection - not an error, just incompatible
    }
    
    return new SpendRange($actualMin, $actualMax);
}
```

### Guidelines

✅ **DO**:
- Document null return in PHPDoc (`@return Type|null`)
- Explain what null means
- Use nullable types (`?Type`)
- Check for null before use

```php
/**
 * Returns the maximum allowed cost for tolerance pruning.
 *
 * @return BigDecimal|null Null if no best cost known (no pruning needed)
 */
private function maxAllowedCost(?BigDecimal $bestTargetCost): ?BigDecimal
```

❌ **DON'T**:
- Return null for errors
- Mix null and exception semantics
- Use null when false is clearer
- Forget to document null meaning

---

## Convention 3: Throw `GuardLimitExceeded` for Resource Exhaustion

### When to Use

**Throw `GuardLimitExceeded` when**:
- Search guard rails are exceeded
- Resource limits reached (expansions, states, time)
- Configuration specifies throwing on limit breach

### Opt-In Exception Pattern

**Key Feature**: `GuardLimitExceeded` is **opt-in** via configuration.

**Default Behavior**: Return `SearchOutcome` with guard report metadata (no exception)

**Exception Mode**: Enable via `throwOnGuardLimit` configuration

```php
// Default mode (recommended) - No exception, check metadata
$config = PathSearchConfigBuilder::create(...)
    ->build();

$result = $service->findBestPaths($request);
if ($result->guardLimits()->anyLimitReached()) {
    // Handle partial results gracefully
}

// Exception mode (opt-in)
$config = PathSearchConfigBuilder::create(...)
    ->withGuardLimitException(true)  // Opt-in to throwing
    ->build();

try {
    $result = $service->findBestPaths($request);
} catch (GuardLimitExceeded $e) {
    // Guard limit hit - exception thrown
    // Message contains: "Search terminated: expansions 5000/5000"
}
```

### Exception Message Format

**Format**: `Search terminated: {limit1 actual/max}, {limit2 actual/max}, ...`

**Examples**:
```
Search terminated: expansions 5000/5000
Search terminated: visited states 2000/2000
Search terminated: elapsed 523.456ms/500ms
Search terminated: expansions 5000/5000, visited states 2000/2000
```

**Context Included**:
- ✅ Actual value reached
- ✅ Configured limit
- ✅ Multiple breaches listed together
- ✅ Precise timing for time budget

### Guidelines

✅ **DO**:
- Use default mode (return `SearchOutcome`) for most cases
- Include which limit was exceeded in message
- Provide context (current vs limit)
- List all breached guards together

❌ **DON'T**:
- Use for invalid configuration (use `InvalidInput`)
- Always throw - make it opt-in
- Lose guard report information

### Choosing Between Modes

**Use Default Mode (No Exception)** when:
- Partial results are acceptable
- Consumer wants to inspect guard report
- Graceful degradation preferred

**Use Exception Mode** when:
- Complete results required (partial not acceptable)
- Consumer prefers exception-based flow control
- Integration with exception-based error handling

---

## Convention 4: Throw `PrecisionViolation` for Arithmetic Guarantees

### When to Use

**Throw `PrecisionViolation` when**:
- Decimal precision cannot be maintained
- Scale requirements cannot be met
- Rounding would lose critical precision

### Example

```php
// Hypothetical: scaling down would lose critical precision
if ($requiredScale < $minimumSafeScale) {
    throw new PrecisionViolation(
        sprintf(
            'Cannot scale to %d decimal places without precision loss. Minimum: %d',
            $requiredScale,
            $minimumSafeScale
        )
    );
}
```

### Guidelines

✅ **DO**:
- Explain what precision guarantee failed
- Suggest corrective action if possible

❌ **DON'T**:
- Use for general math errors (use `InvalidInput`)
- Use for expected rounding (that's normal)

---

## Convention 5: `InfeasiblePath` - User-Space Exception

### Important: Library Does NOT Throw This Exception

**`InfeasiblePath` is a user-space exception** for application logic, NOT thrown by the P2P Path Finder library.

**Purpose**: Signal that no viable path exists for required business operation in consumer application.

### Why Library Doesn't Throw It

1. **Library returns empty results gracefully**: `SearchOutcome` with empty paths collection
2. **"Infeasible" is application-specific**: Different apps have different viability criteria
3. **Library doesn't know "why" paths failed**: Insufficient context for meaningful error message
4. **Consumer can provide better context**: Application knows business requirements

### When Consumers Should Use It

**Throw `InfeasiblePath` in your application when**:
- Your application requires a path (not optional)
- Business rules mandate specific path characteristics
- Custom validation fails on all paths
- You need to signal path unavailability as an error

### Consumer Usage Examples

#### Example 1: Required Path

```php
// Application code
$result = $service->findBestPaths($request);

if ($result->paths()->isEmpty()) {
    // Application decision: path is required
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

#### Example 2: Business Rule Violation

```php
// Application code - business rule: need 2+ paths for redundancy
$result = $service->findBestPaths($request);

if (count($result->paths()) < 2) {
    throw new InfeasiblePath(
        sprintf(
            'Insufficient path redundancy for safe execution. ' .
            'Required: 2 paths, Found: %d',
            count($result->paths())
        )
    );
}
```

#### Example 3: Custom Validation Failure

```php
// Application code - custom validation
$result = $service->findBestPaths($request);

foreach ($result->paths() as $path) {
    if ($this->customValidator->canExecute($path)) {
        return $path;  // Found viable path
    }
}

// No path passed custom validation
throw new InfeasiblePath(
    'No path meets application-specific execution requirements'
);
```

### When NOT to Use It

❌ **DON'T throw** when:
- Empty results are acceptable outcome
- Consumer can handle empty results without exception
- No business requirement for path availability

**Instead**: Check for empty results and handle gracefully:

```php
// ✅ GOOD: Handle empty results gracefully
$result = $service->findBestPaths($request);

if ($result->paths()->isEmpty()) {
    return $this->handleNoPathsAvailable($request);
}
```

### Guidelines

✅ **DO**:
- Use in application layer (not library layer)
- Provide specific context about why path is required
- Include constraint details in message
- Explain what business rule was violated

❌ **DON'T**:
- Throw from library code
- Use for optional paths
- Use when empty results are valid outcome
- Expect library to throw this exception

---

## Error Message Guidelines

### Standard Message Format

**Template**: `{What's wrong}. {Context: values/comparison}. {Guidance: if helpful}.`

**Components**:
1. **What's wrong**: Clear statement of constraint or error
2. **Context**: Specific values, expected vs actual
3. **Guidance**: Actionable suggestion (when applicable)

### Guideline 1: Start with What's Wrong

✅ **Be Specific and Direct**:
```php
// ✅ GOOD: Clear statement
throw new InvalidInput('Money amount cannot be negative.');

// ❌ BAD: Vague or indirect
throw new InvalidInput('Invalid money: negative amount');
```

### Guideline 2: Include Context When Available

✅ **Show Actual Values**:
```php
// ✅ GOOD: Includes invalid value
throw new InvalidInput(
    sprintf('Money amount cannot be negative. Got: %s %s', $currency, $amount)
);

// ⚠️ ACCEPTABLE but less helpful
throw new InvalidInput('Money amount cannot be negative.');
```

✅ **Show Expected vs Actual for Mismatches**:
```php
// ✅ GOOD: Shows both sides
throw new InvalidInput(
    sprintf('Currency mismatch. Expected: %s, Got: %s', $expected, $actual)
);

// ❌ BAD: Missing context
throw new InvalidInput('Currency mismatch.');
```

### Guideline 3: Use Consistent Terminology

**Standard Terms**:

| Concept | ✅ Use | ❌ Avoid |
|---------|--------|----------|
| Money quantity | amount | value, sum |
| Currency code | currency | asset (when specific to code) |
| Path length | hops | steps, jumps |
| Upper bound | maximum | max (in prose) |
| Lower bound | minimum | min (in prose) |
| Guard limit | limit | maximum, threshold |

**Examples**:
```php
// ✅ GOOD: Consistent terminology
throw new InvalidInput('Money amount cannot be negative.');
throw new InvalidInput('Maximum hops must be at least one.');

// ❌ BAD: Inconsistent
throw new InvalidInput('Money value cannot be negative.');
throw new InvalidInput('Max steps must be at least one.');
```

### Guideline 4: For Numeric Constraints, Show Provided Value

```php
// ✅ GOOD: Shows what was provided
throw new InvalidInput(
    sprintf('Maximum hops must be at least one. Got: %d', $maxHops)
);

// ⚠️ ACCEPTABLE but less helpful
throw new InvalidInput('Maximum hops must be at least one.');
```

### Guideline 5: For Comparisons, Show Both Values

```php
// ✅ GOOD: Shows both min and max
throw new InvalidInput(
    sprintf(
        'Minimum amount cannot exceed the maximum amount. Min: %s, Max: %s',
        $min->format(),
        $max->format()
    )
);

// ⚠️ Less helpful
throw new InvalidInput('Minimum amount cannot exceed the maximum amount.');
```

### Guideline 6: Include Actionable Guidance When Possible

✅ **Suggest How to Fix**:
```php
// ✅ GOOD: Explains problem and solution
throw new InvalidInput(
    'Tolerance window collapsed to zero range due to insufficient spend amount precision. ' .
    'Increase spend amount scale or adjust tolerance bounds.'
);

// ⚠️ Less helpful: No guidance
throw new InvalidInput('Tolerance window collapsed to zero range.');
```

### Guideline 7: Keep Messages Concise

✅ **Be Clear and Concise**:
```php
// ✅ GOOD: Clear and brief
throw new InvalidInput('Division by zero.');

// ❌ TOO VERBOSE
throw new InvalidInput(
    'The division operation cannot be performed because the divisor value is zero, ' .
    'which would result in an undefined mathematical result. Please provide a non-zero divisor.'
);
```

### Message Examples by Exception Type

**InvalidInput** - Constraint violations:
```
Money amount cannot be negative. Got: USD -100.00
Maximum hops must be at least one. Got: 0
Currency mismatch. Expected: USD, Got: EUR
Minimum amount cannot exceed maximum amount. Min: 100, Max: 50
Currency cannot be empty.
Division by zero.
```

**GuardLimitExceeded** - Resource exhaustion:
```
Search terminated: expansions 5000/5000
Search terminated: visited states 2000/2000
Search terminated: elapsed 523.456ms/500ms
Search terminated: expansions 5000/5000, visited states 2000/2000
```

**PrecisionViolation** - Arithmetic precision (future):
```
Cannot scale down to 2 decimal places without precision loss. Current scale: 8, Minimum safe scale: 6. Suggested: Use scale >= 6 or accept rounding.
```

**InfeasiblePath** - User-space path unavailability:
```
No viable path from USD to EUR with requested constraints: spend=100.00, tolerance=0.0-0.2, hops=1-4
Insufficient path redundancy for safe execution. Required: 2 paths, Found: 1
No path meets application-specific execution requirements
```

---

## Testing Error Scenarios

### Test All Error Paths

Every exception path should have a test:

```php
public function testNegativeMoneyAmountThrowsException(): void
{
    $this->expectException(InvalidInput::class);
    $this->expectExceptionMessage('Money amount cannot be negative');
    
    Money::fromString('USD', '-100.00', 2);
}
```

### Test Null Returns

Null returns should be tested:

```php
public function testMaxAllowedCostReturnsNullWhenNoBestCostKnown(): void
{
    $pathFinder = new PathFinder();
    $result = $pathFinder->maxAllowedCost(null);
    
    self::assertNull($result, 'Should return null when no best cost known');
}
```

---

## Common Patterns

### Pattern 1: Validate in Constructor

```php
public function __construct(Money $min, Money $max)
{
    self::assertCurrencyConsistency($min, $max);
    
    if ($min->greaterThan($max)) {
        throw new InvalidInput('Minimum amount cannot exceed the maximum amount.');
    }
    
    $this->min = $min;
    $this->max = $max;
}
```

**Rationale**: Ensures object is always in valid state (fail-fast)

### Pattern 2: Validate Before Operation

```php
public function convert(Money $money, ?int $scale = null): Money
{
    if ($money->currency() !== $this->baseCurrency) {
        throw new InvalidInput('Money currency must match exchange rate base currency.');
    }
    
    // ... conversion logic ...
}
```

**Rationale**: Prevents invalid operations

### Pattern 3: Return Null for Not-Found

```php
public function findOrder(string $id): ?Order
{
    return $this->orders[$id] ?? null;  // Not found is valid outcome
}
```

**Rationale**: Caller expects possible absence

### Pattern 4: Configurable Throwing

```php
public function search(Config $config): SearchOutcome
{
    // ... search logic ...
    
    if ($guardLimits->anyLimitReached()) {
        if ($config->throwOnGuardLimit()) {
            throw new GuardLimitExceeded('...');
        }
        // Return partial results with guard report
    }
}
```

**Rationale**: Flexibility for different use cases

---

## Anti-Patterns to Avoid

### ❌ Anti-Pattern 1: Silent Failures

```php
// BAD: Silent failure
public function process($input): void
{
    if ($input === null) {
        return;  // What happened? Why did we skip?
    }
    // ... processing ...
}

// GOOD: Explicit error
public function process($input): void
{
    if ($input === null) {
        throw new InvalidInput('Input cannot be null');
    }
    // ... processing ...
}
```

### ❌ Anti-Pattern 2: Generic Exceptions

```php
// BAD: Generic exception
throw new Exception('Error');
throw new RuntimeException('Something went wrong');

// GOOD: Specific exception
throw new InvalidInput('Currency cannot be empty');
```

### ❌ Anti-Pattern 3: Mixing Null and Exception

```php
// BAD: Inconsistent
public function getValue1($input): ?int
{
    if ($input < 0) return null;  // Error as null
    return $input * 2;
}

public function getValue2($input): int
{
    if ($input < 0) throw new InvalidInput('...'); // Error as exception
    return $input * 2;
}

// GOOD: Consistent
public function getValue($input): int
{
    if ($input < 0) {
        throw new InvalidInput('Input cannot be negative');
    }
    return $input * 2;
}
```

### ❌ Anti-Pattern 4: Catching and Ignoring

```php
// BAD: Swallow exception
try {
    $result = $service->process($input);
} catch (InvalidInput $e) {
    // Ignored - silent failure
}

// GOOD: Handle or propagate
try {
    $result = $service->process($input);
} catch (InvalidInput $e) {
    $this->logger->error('Failed to process input', ['exception' => $e]);
    throw $e;  // Re-throw or handle appropriately
}
```

---

## Decision Tree

**When to throw vs return null**:

```
Is this an invalid input or invariant violation?
  YES → Throw InvalidInput
  NO  ↓

Is this a resource/guard limit breach?
  YES → Throw GuardLimitExceeded (if configured)
  NO  ↓

Is this an arithmetic precision issue?
  YES → Throw PrecisionViolation
  NO  ↓

Is this an optional value or "not found" scenario?
  YES → Return null (document in PHPDoc)
  NO  ↓

Is this a boolean result (accept/reject)?
  YES → Return true/false
  NO  ↓

Consider if you need a new exception type
```

---

## Summary

| Scenario | Action | Exception Type |
|----------|--------|----------------|
| Invalid input parameter | **Throw** | `InvalidInput` |
| Invariant violation | **Throw** | `InvalidInput` |
| Configuration error | **Throw** | `InvalidInput` |
| Currency mismatch | **Throw** | `InvalidInput` |
| Boundary violation (min > max) | **Throw** | `InvalidInput` |
| Guard limit exceeded | **Throw** (if configured) | `GuardLimitExceeded` |
| Precision loss | **Throw** | `PrecisionViolation` |
| Optional value | **Return null** | N/A |
| Not found | **Return null** | N/A |
| Not applicable | **Return null** | N/A |
| Boolean result | **Return bool** | N/A |

---

## Empty Results Handling

### Empty Results are NOT Errors

**Empty results are valid business outcomes**, not error conditions.

**Return empty `SearchOutcome`** when:
- No orders match filter criteria
- Source or target currency not in order book
- No paths exist between source and target
- All paths exceed tolerance bounds
- All paths rejected by acceptance callback
- Search guards limit exploration before finding viable paths

**Throw exceptions** when:
- Input parameters are invalid (e.g., negative hops)
- Configuration is malformed (e.g., min > max)
- System errors occur (e.g., out of memory)

### Example: Handling Empty Results

```php
$result = $service->findBestPaths($request);

if ($result->paths()->isEmpty()) {
    // Valid scenario - no paths available
    // Check guard report to understand why
    
    if ($result->guardLimits()->anyLimitReached()) {
        $this->logger->warning('Search limited by guards', [
            'guardReport' => $result->guardLimits(),
        ]);
        
        // Decide: accept partial results or retry with higher limits
    } else {
        $this->logger->info('No paths found', [
            'source' => $request->sourceAsset(),
            'target' => $request->targetAsset(),
        ]);
        
        // Truly no paths available - this is not an error
    }
}
```

### Rationale

1. **Valid Business Outcome**: No paths is an expected scenario in trading
2. **Guard Report Provides Context**: Consumer can distinguish "no paths" from "search limited"
3. **Consistent with Query Pattern**: Similar to database/search APIs that return empty collections
4. **Better Ergonomics**: No forced exception handling for common case
5. **Exception Reserved for Errors**: Maintains clear separation of concerns

### Comparison

```php
// ✅ GOOD: Return empty collection
$result = $service->findBestPaths($request);
if ($result->paths()->isEmpty()) {
    // Handle no-results case
}

// ❌ BAD: Throw exception for no results
try {
    $result = $service->findBestPaths($request);
} catch (InfeasiblePath $e) {
    // Forced to catch for common "no results" case
}
```

---

## Catch Strategies

### Strategy 1: Catch All Library Exceptions

**Use Case**: Generic error handling for any library error

```php
use SomeWork\P2PPathFinder\Exception\ExceptionInterface;

try {
    $result = $service->findBestPaths($request);
} catch (ExceptionInterface $e) {
    // Handle all library exceptions
    $this->logger->error('Path finding failed', [
        'exception' => $e->getMessage(),
        'type' => get_class($e),
    ]);
    
    return $this->handlePathFindingError($e);
}
```

**Benefits**:
- ✅ Catches all library exceptions
- ✅ Simple, catch-all strategy
- ✅ Good for logging/monitoring

**Drawbacks**:
- ⚠️ Can't distinguish error types
- ⚠️ Same handling for all errors

---

### Strategy 2: Catch Specific Exception Types

**Use Case**: Different handling for different error types

```php
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\GuardLimitExceeded;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

try {
    $result = $service->findBestPaths($request);
} catch (InvalidInput $e) {
    // Bad input - show error to user
    return $this->showValidationError($e->getMessage());
    
} catch (GuardLimitExceeded $e) {
    // Resource limit hit - retry with higher limits or return partial
    $this->logger->warning('Guard limit exceeded', ['exception' => $e]);
    return $this->retryWithHigherLimits($request);
    
} catch (PrecisionViolation $e) {
    // Arithmetic issue - increase precision or notify admin
    $this->logger->error('Precision violation', ['exception' => $e]);
    return $this->handlePrecisionError($e);
}
```

**Benefits**:
- ✅ Tailored handling per error type
- ✅ Better user experience
- ✅ Specific recovery strategies

**Drawbacks**:
- ⚠️ More verbose
- ⚠️ Need to know exception types

---

### Strategy 3: Check Before Throw (Guard Limits)

**Use Case**: Prefer metadata over exceptions for guard limits

```php
// Configure to NOT throw on guard limits (default)
$config = PathSearchConfigBuilder::create(...)
    ->build();  // throwOnGuardLimit defaults to false

$result = $service->findBestPaths($request);

// Check guard report
if ($result->guardLimits()->anyLimitReached()) {
    $this->logger->info('Search limited by guards', [
        'guardReport' => [
            'expansions' => $result->guardLimits()->expansions(),
            'expansionLimit' => $result->guardLimits()->expansionLimit(),
            'visitedStates' => $result->guardLimits()->visitedStates(),
            'visitedStateLimit' => $result->guardLimits()->visitedStateLimit(),
        ],
    ]);
    
    // Decide: accept partial results or retry
    if ($result->paths()->isEmpty()) {
        return $this->retryWithHigherLimits($request);
    }
}

// Use results (complete or partial)
return $result->paths();
```

**Benefits**:
- ✅ No exception handling needed
- ✅ Graceful degradation
- ✅ Full guard context available
- ✅ Can distinguish partial vs complete

**Recommended**: This is the preferred approach for guard limits.

---

### Strategy 4: Validate Early

**Use Case**: Catch validation errors before expensive operations

```php
// Validate configuration before search
try {
    $config = PathSearchConfigBuilder::create($orderBook, $targetAsset, $tolerance)
        ->withHopLimits($minHops, $maxHops)
        ->withResultLimit($topK)
        ->build();
} catch (InvalidInput $e) {
    // Config validation failed - don't proceed
    return $this->handleConfigError($e);
}

// Validate orders before processing
try {
    $orders = [];
    foreach ($rawOrders as $rawOrder) {
        $orders[] = $this->createOrder($rawOrder);  // Throws InvalidInput
    }
} catch (InvalidInput $e) {
    // Order validation failed
    return $this->handleOrderError($e);
}

// Now perform search with validated input
$result = $service->findBestPaths(new PathSearchRequest($orderBook, $config, $targetAsset));
```

**Benefits**:
- ✅ Fail fast
- ✅ Clear error location
- ✅ Avoid expensive operations with bad input

---

### Strategy 5: Combine Strategies

**Use Case**: Comprehensive error handling

```php
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;
use SomeWork\P2PPathFinder\Exception\ExceptionInterface;

// Step 1: Validate early
try {
    $config = $this->buildConfig($request);
} catch (InvalidInput $e) {
    return $this->handleValidationError($e);
}

// Step 2: Perform search with guard metadata (not exceptions)
try {
    $result = $service->findBestPaths(new PathSearchRequest($orderBook, $config, $targetAsset));
} catch (InvalidInput $e) {
    // Unexpected validation error during search
    return $this->handleValidationError($e);
} catch (PrecisionViolation $e) {
    // Arithmetic precision issue
    return $this->handlePrecisionError($e);
} catch (ExceptionInterface $e) {
    // Any other library exception
    $this->logger->error('Unexpected error', ['exception' => $e]);
    throw $e;  // Re-throw unexpected errors
}

// Step 3: Check guard limits
if ($result->guardLimits()->anyLimitReached()) {
    if ($result->paths()->isEmpty()) {
        // No results due to guards - retry or fail
        return $this->handleGuardLimitWithNoResults($result);
    } else {
        // Partial results - log and continue
        $this->logger->info('Partial results due to guard limits');
    }
}

// Step 4: Check for empty results
if ($result->paths()->isEmpty()) {
    // No paths found (search completed, no limits hit)
    return $this->handleNoPathsFound($request);
}

// Step 5: Use results
return $this->processResults($result->paths());
```

**Benefits**:
- ✅ Comprehensive coverage
- ✅ Clear separation of concerns
- ✅ Graceful degradation
- ✅ Good logging

---

### Strategy Comparison

| Strategy | Use Case | Complexity | Recommended |
|----------|----------|------------|-------------|
| Catch All | Generic error handling | Low | ⚠️ Basic apps only |
| Catch Specific | Tailored error handling | Medium | ✅ Most apps |
| Check Metadata | Guard limits | Low | ✅ Preferred for guards |
| Validate Early | Fail fast | Medium | ✅ Performance-critical |
| Combined | Production applications | High | ✅ Production use |

---

## Exception Hierarchy Reference

### Complete Hierarchy

```
Throwable
  └─ ExceptionInterface (marker interface)
      │
      ├─ InvalidInput extends InvalidArgumentException
      │   Use: Malformed or unsupported input from consumer
      │   Thrown by: Domain layer, Application layer
      │   Consumer action: Validate input, show error to user
      │
      ├─ GuardLimitExceeded extends RuntimeException
      │   Use: Search guard rails exceeded (opt-in)
      │   Thrown by: PathFinderService (when throwOnGuardLimit=true)
      │   Consumer action: Retry with higher limits or accept partial
      │
      ├─ PrecisionViolation extends RuntimeException
      │   Use: Arithmetic guarantees cannot be upheld
      │   Thrown by: PathFinder, calculation helpers
      │   Consumer action: Increase precision, adjust scale
      │
      └─ InfeasiblePath extends RuntimeException
          Use: Path unavailability (user-space)
          Thrown by: Consumer application (NOT library)
          Consumer action: Application-specific handling
```

### Exception Quick Reference

| Exception | When Thrown | Who Throws | Recovery Strategy |
|-----------|-------------|------------|-------------------|
| `InvalidInput` | Invalid parameters, constraints violated | Library | Validate input, fix parameters |
| `GuardLimitExceeded` | Resource limits exceeded (opt-in) | Library | Retry with higher limits |
| `PrecisionViolation` | Precision guarantees failed | Library | Increase scale/precision |
| `InfeasiblePath` | Required path not available | **Consumer** | Application-specific |

---

## For Contributors

### Before Adding Code

1. **Identify error scenarios** in your feature
2. **Choose appropriate exception type** based on this guide
3. **Write error tests** for all scenarios
4. **Document null returns** in PHPDoc
5. **Follow message guidelines** (what's wrong, context, guidance)

### Code Review Checklist

- [ ] All invariants validated in constructor
- [ ] All error scenarios have tests
- [ ] Exception messages are clear and specific
- [ ] Correct exception type used (InvalidInput, GuardLimitExceeded, etc.)
- [ ] Null returns documented in PHPDoc
- [ ] No silent failures
- [ ] Consistent with existing patterns
- [ ] Exception messages include context (values, expected vs actual)
- [ ] Consistent terminology used

### Adding New Exception Types

**Before adding a new exception type, ask**:

1. ✅ Is this a distinct error category?
2. ✅ Do consumers need to handle it differently?
3. ✅ Does the exception type convey information the message can't?
4. ✅ Is it NOT a refinement of existing exceptions?

**If all YES**: Consider adding new exception type

**If any NO**: Use existing exception type (`InvalidInput`, `GuardLimitExceeded`, `PrecisionViolation`)

---

## FAQ

### Q: Should I throw an exception for empty results?

**A**: No. Empty results are a valid business outcome. Return empty `SearchOutcome` instead.

**See**: "Empty Results Handling" section above

---

### Q: Should I use `GuardLimitExceeded` or check guard metadata?

**A**: Prefer checking guard metadata (default behavior). Use `GuardLimitExceeded` only when you need exception-based flow control.

**See**: "Convention 3: Throw GuardLimitExceeded" section above

---

### Q: When should I use `InfeasiblePath`?

**A**: In your application code when a required path is not available. The library never throws this exception.

**See**: "Convention 5: InfeasiblePath - User-Space Exception" section above

---

### Q: Can I catch `InvalidInput` to handle all validation errors?

**A**: Yes. All input validation errors (domain and application layer) throw `InvalidInput`.

---

### Q: How do I distinguish between order validation and config validation errors?

**A**: Check the exception message. Both throw `InvalidInput`, but the message explains what failed.

```php
catch (InvalidInput $e) {
    if (str_contains($e->getMessage(), 'hops')) {
        // Config error
    } elseif (str_contains($e->getMessage(), 'order')) {
        // Order error
    }
    // Or just handle all as "invalid input"
}
```

**Note**: In most cases, you don't need to distinguish - just show the error message to the user.

---

### Q: Should I create domain-specific exceptions (e.g., `MoneyException`, `OrderException`)?

**A**: No. Use `InvalidInput` for all domain validation. Exception messages provide specific context.

---

## References

- Exception classes: `src/Exception/`
- Domain layer validation: `src/Domain/`
- Application layer validation: `src/Application/`

---

## Revision History

- **v1.0** (2024-11-22): Initial conventions established based on codebase audit
- **v1.1** (2024-11-22): Added catch strategies, FAQ, and complete hierarchy reference

