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

### Example

```php
// PathFinderService
if ($config->throwOnGuardLimit() && $guardLimits->anyLimitReached()) {
    throw new GuardLimitExceeded(
        sprintf(
            'Search terminated: %s',
            $this->formatGuardLimitMessage($config, $guardLimits)
        )
    );
}
```

### Guidelines

✅ **DO**:
- Include which limit was exceeded
- Provide context (current vs limit)
- Make throwing configurable when appropriate

❌ **DON'T**:
- Use for invalid configuration (use `InvalidInput`)
- Throw when consumer wants results despite limit

**Note**: `PathFinderService` supports both throwing and non-throwing modes via `throwOnGuardLimit` configuration.

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

## Convention 5: Reserved - `InfeasiblePath` for Materialization Failures

### When to Use (Future)

**Throw `InfeasiblePath` when**:
- Path found but cannot be materialized
- Orders insufficient for path execution
- Liquidity constraints prevent execution

### Example (Proposed)

```php
// PathFinderService (future use)
$materialized = $this->legMaterializer->materialize($edges, $spend, $initialSeed, $targetCurrency);

if (null === $materialized) {
    throw new InfeasiblePath(
        'Found path cannot be materialized due to insufficient order liquidity'
    );
}
```

### Current Status

⚠️ **Not currently used** - Reserved for future path materialization error handling

---

## Error Message Guidelines

### Good Error Messages

✅ **Be Specific**:
```php
// Good
throw new InvalidInput('Money amount cannot be negative. Got: USD -100.00');

// Bad
throw new InvalidInput('Invalid money');
```

✅ **Include Context**:
```php
// Good
throw new InvalidInput('Maximum hops (3) must be greater than or equal to minimum hops (5).');

// Bad
throw new InvalidInput('Invalid hop configuration');
```

✅ **Suggest Fix (when possible)**:
```php
// Good
throw new InvalidInput(
    'Tolerance window collapsed to zero range due to insufficient spend amount precision. ' .
    'Increase spend amount scale or adjust tolerance bounds.'
);
```

### Error Message Format

**Pattern**: `{What's wrong}. {Specific details}`

**Examples**:
- `"Currency cannot be empty."`
- `"Exchange rate must be greater than zero. Got: -0.5"`
- `"Minimum amount cannot exceed the maximum amount. Min: 100, Max: 50"`

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

## For Contributors

### Before Adding Code

1. **Identify error scenarios** in your feature
2. **Choose appropriate handling** based on this guide
3. **Write error tests** for all scenarios
4. **Document null returns** in PHPDoc

### Code Review Checklist

- [ ] All invariants validated in constructor
- [ ] All error scenarios have tests
- [ ] Exception messages are clear and specific
- [ ] Null returns are documented
- [ ] No silent failures
- [ ] Consistent with existing patterns

---

## References

- Exception classes: `src/Exception/`
- Error handling audit: `docs/audits/error-handling-audit.md`
- Domain layer validation: `src/Domain/`
- Application layer validation: `src/Application/`

---

## Revision History

- **v1.0** (2024-11-22): Initial conventions established based on codebase audit

