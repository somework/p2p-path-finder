# Additional Exception Types Evaluation

**Date**: 2024-11-22  
**Task**: 0005.10  
**Decision**: Do NOT add additional exception types

## Executive Summary

**Evaluation Result**: ❌ **No additional exception types needed**

**Rationale**: Current `InvalidInput` exception adequately covers all error scenarios. Adding specialized exceptions would increase complexity without providing meaningful value.

---

## Candidates Evaluated

### 1. GraphConstructionException

**Proposed Use**: Graph building errors (e.g., invalid orders, construction failures)

**Current Handling**: `GraphBuilder::build()` throws `InvalidInput` or `PrecisionViolation`

**Decision**: ❌ **NOT NEEDED**

#### Rationale

1. **Graph building errors are input validation errors**:
   ```php
   // GraphBuilder.php
   public function build(iterable $orders): Graph
   {
       // Throws InvalidInput if orders invalid
       // Throws PrecisionViolation if arithmetic fails
   }
   ```
   
   These are already `InvalidInput` (bad data) or `PrecisionViolation` (arithmetic issue).

2. **No graph-specific error handling needed**:
   - Consumer doesn't need to catch graph errors separately
   - All graph errors are construction-time validation
   - No recovery strategy specific to "graph construction"

3. **Current exception types are sufficient**:
   ```php
   try {
       $graph = $builder->build($orders);
   } catch (InvalidInput $e) {
       // Handle invalid orders
   } catch (PrecisionViolation $e) {
       // Handle arithmetic issues
   }
   ```
   
   Consumer can already distinguish error types.

4. **Adding GraphConstructionException would require wrapping**:
   ```php
   // Would need to do this (unnecessary complexity):
   try {
       // ... build graph ...
   } catch (InvalidInput $e) {
       throw new GraphConstructionException('Graph build failed', 0, $e);
   }
   ```
   
   This adds no value - just wraps existing exception.

#### Examples Where NOT Needed

**Scenario**: Invalid order in collection
```php
// Current (good)
try {
    $graph = $builder->build($orders);
} catch (InvalidInput $e) {
    // Clear: input validation failed
}

// With GraphConstructionException (unnecessary)
try {
    $graph = $builder->build($orders);
} catch (GraphConstructionException $e) {
    // What does this add? Still need to check $e->getPrevious()
}
```

**Scenario**: Precision issue during edge creation
```php
// Current (good)
try {
    $graph = $builder->build($orders);
} catch (PrecisionViolation $e) {
    // Clear: arithmetic precision issue
}

// With GraphConstructionException (masks root cause)
try {
    $graph = $builder->build($orders);
} catch (GraphConstructionException $e) {
    // Lost precision context - was it InvalidInput or PrecisionViolation?
}
```

---

### 2. OrderValidationException

**Proposed Use**: Order validation errors (e.g., invalid amounts, currency mismatches)

**Current Handling**: `Order` constructor throws `InvalidInput`

**Decision**: ❌ **NOT NEEDED**

#### Rationale

1. **Order validation IS input validation**:
   ```php
   // Order constructor
   public function __construct(...)
   {
       if (!$this->bounds->contains($baseAmount)) {
           throw new InvalidInput('Fill amount must be within order bounds.');
       }
   }
   ```
   
   Order validation errors are by definition `InvalidInput`.

2. **No order-specific error handling needed**:
   - Consumer creates order from user input
   - Validation failure = bad input
   - No recovery strategy specific to "order validation" vs "input validation"

3. **Would require every domain object to have its own exception**:
   ```php
   // Slippery slope - would need:
   throw new OrderValidationException(...);
   throw new MoneyValidationException(...);
   throw new ExchangeRateValidationException(...);
   throw new ToleranceWindowValidationException(...);
   // etc...
   ```
   
   This is excessive and provides no benefit.

4. **Consumer doesn't care if it's an "Order" or "Money" error**:
   ```php
   // Consumer code
   try {
       $order = new Order(...);
   } catch (InvalidInput $e) {
       // Handle bad input - that's all consumer needs to know
   }
   ```
   
   Knowing it's specifically an "order validation" error adds no value.

#### Examples Where NOT Needed

**Scenario**: Fill amount out of bounds
```php
// Current (good)
try {
    $order->fill($amount);
} catch (InvalidInput $e) {
    // Clear: invalid input (amount out of bounds)
}

// With OrderValidationException (unnecessary)
try {
    $order->fill($amount);
} catch (OrderValidationException $e) {
    // What additional handling can we do? Still just bad input.
}
```

**Scenario**: Currency mismatch
```php
// Current (good)
try {
    $rate->convert($money);
} catch (InvalidInput $e) {
    // Clear: invalid input (currency mismatch)
}

// With OrderValidationException or MoneyValidationException (confusing)
// Which one would be thrown? Both are involved.
```

---

### 3. ConfigurationException

**Proposed Use**: Configuration errors (e.g., invalid hop limits, tolerance bounds)

**Current Handling**: Config classes throw `InvalidInput`

**Decision**: ❌ **NOT NEEDED**

#### Rationale

1. **Configuration errors ARE input validation errors**:
   ```php
   // PathSearchConfig constructor
   public function __construct(...)
   {
       if ($minimumHops < 1) {
           throw new InvalidInput('Minimum hops must be at least one.');
       }
   }
   ```
   
   Invalid configuration = invalid input from consumer.

2. **No config-specific error handling needed**:
   ```php
   // Consumer doesn't need different handling for config vs other input
   try {
       $config = new PathSearchConfig(...);
   } catch (InvalidInput $e) {
       // Handle bad configuration
       // Same recovery as any other bad input: fix and retry
   }
   ```

3. **Configuration is just another form of input**:
   - User provides hop limits → input
   - User provides tolerance → input
   - User provides spend amount → input
   
   All are validated the same way.

4. **Would blur the line between configuration and input**:
   ```php
   // When to use ConfigurationException vs InvalidInput?
   throw new ConfigurationException('Invalid hops');        // Config
   throw new InvalidInput('Invalid spend amount');          // Input
   // ^ These are both just parameters to the same constructor
   ```

#### Examples Where NOT Needed

**Scenario**: Invalid hop configuration
```php
// Current (good)
try {
    $config = PathSearchConfigBuilder::create(...)
        ->withHopLimits($min, $max)
        ->build();
} catch (InvalidInput $e) {
    // Clear: invalid input (bad hop limits)
}

// With ConfigurationException (unnecessary distinction)
try {
    $config = PathSearchConfigBuilder::create(...)
        ->withHopLimits($min, $max)
        ->build();
} catch (ConfigurationException $e) {
    // Same handling as InvalidInput - just rename
}
```

**Scenario**: Tolerance window validation
```php
// Current (good)
try {
    $window = new ToleranceWindow($min, $max);
} catch (InvalidInput $e) {
    // Clear: invalid input
}

// With ConfigurationException (confusing)
// Is ToleranceWindow "configuration" or "input"? It's both!
```

---

## Alternative: More Specific Exception Types (Rejected)

### Why Not Domain-Specific Exceptions?

**Rejected Approach**:
```php
GraphConstructionException
OrderValidationException
MoneyValidationException
ExchangeRateValidationException
ToleranceWindowValidationException
PathSearchConfigException
SearchGuardConfigException
// ... etc
```

**Problems**:

1. **Explosion of exception types**:
   - Need one per domain class
   - 20+ domain classes = 20+ exception types
   - Maintenance nightmare

2. **No additional value**:
   - All are input validation
   - All handled the same way
   - Exception type doesn't change consumer behavior

3. **Exception message already provides context**:
   ```php
   throw new InvalidInput('Fill amount must be within order bounds.');
   // ^ Message tells you it's an Order validation issue
   
   throw new InvalidInput('Minimum hops must be at least one.');
   // ^ Message tells you it's a config validation issue
   ```
   
   Exception class doesn't need to duplicate this information.

4. **Catch-all becomes harder**:
   ```php
   // Current (easy)
   try {
       $service->findBestPaths($request);
   } catch (InvalidInput $e) {
       // Handle all input validation errors
   }
   
   // With many types (tedious)
   try {
       $service->findBestPaths($request);
   } catch (OrderValidationException $e) {
       // ...
   } catch (ConfigurationException $e) {
       // ...
   } catch (MoneyValidationException $e) {
       // ...
   } // etc...
   ```

---

## Current Exception Hierarchy is Sufficient

### What We Have

```
ExceptionInterface
├─ InvalidInput (invalid input/configuration)
├─ GuardLimitExceeded (resource exhaustion, opt-in)
├─ PrecisionViolation (arithmetic precision)
└─ InfeasiblePath (user-space, path unavailability)
```

### Why It Works

1. **Clear Semantic Categories**:
   - `InvalidInput`: Constraint violations, validation failures
   - `GuardLimitExceeded`: Resource limits (opt-in)
   - `PrecisionViolation`: Arithmetic guarantees
   - `InfeasiblePath`: Business logic (user-space)

2. **Appropriate Granularity**:
   - Not too broad (single Exception class)
   - Not too fine-grained (per-class exceptions)
   - Just right for consumer needs

3. **Consumer-Friendly Catch Strategies**:
   ```php
   try {
       $result = $service->findBestPaths($request);
   } catch (InvalidInput $e) {
       // Bad input - show error to user
   } catch (GuardLimitExceeded $e) {
       // Resource limit - retry with higher limits
   } catch (PrecisionViolation $e) {
       // Arithmetic issue - increase precision
   }
   ```

4. **Exception Messages Provide Context**:
   ```php
   throw new InvalidInput('Money amount cannot be negative. Got: USD -100.00');
   // ^ Clear what went wrong and where
   
   throw new InvalidInput('Minimum hops must be at least one. Got: 0');
   // ^ Clear it's a configuration issue
   ```
   
   No need for separate exception types.

---

## Decision Summary

### ❌ Do NOT Add:
1. **GraphConstructionException** - Use `InvalidInput` / `PrecisionViolation`
2. **OrderValidationException** - Use `InvalidInput`
3. **ConfigurationException** - Use `InvalidInput`

### ✅ Keep Current Hierarchy:
1. **InvalidInput** - All input/configuration validation
2. **GuardLimitExceeded** - Resource exhaustion (opt-in)
3. **PrecisionViolation** - Arithmetic precision issues
4. **InfeasiblePath** - User-space path unavailability

---

## Guidelines for Future Exception Additions

**Only add new exception type if**:

1. **Distinct error category**: Represents fundamentally different error type
2. **Different handling**: Consumer needs to handle it differently
3. **Semantic value**: Exception type conveys information message can't
4. **Not refinement**: Not a subcategory of existing exception

**Examples**:

✅ **GOOD reasons to add**:
- Network connectivity issues (if library made network calls)
- Database errors (if library accessed database)
- External service failures (if library called external services)

❌ **BAD reasons to add**:
- "Order errors" (still just InvalidInput)
- "Configuration errors" (still just InvalidInput)
- "Graph errors" (still just InvalidInput or PrecisionViolation)
- Per-class validation exceptions (unnecessary granularity)

---

## Impact Assessment

### If We Added Them (Negative Impact)

**Code Complexity**:
- 3 new exception classes to maintain
- Need to wrap existing exceptions
- More cognitive load for consumers

**Consumer Code**:
```php
// Before (simple)
try {
    $service->findBestPaths($request);
} catch (InvalidInput $e) {
    // Handle all input errors
}

// After (complex, no benefit)
try {
    $service->findBestPaths($request);
} catch (OrderValidationException $e) {
    // Same handling
} catch (ConfigurationException $e) {
    // Same handling
} catch (GraphConstructionException $e) {
    // Same handling
}
```

**Migration Burden**:
- Existing code breaks if we change exception types
- Need to update all catch blocks
- Documentation needs updates
- No functional benefit

---

## Conclusion

**Current exception hierarchy is optimal** for this library's needs:

- ✅ Clear semantic categories
- ✅ Appropriate granularity
- ✅ Consumer-friendly
- ✅ Message-driven context
- ✅ Easy to maintain

**Do NOT add**:
- GraphConstructionException
- OrderValidationException
- ConfigurationException

**Rationale**: These would be unnecessary refinements of `InvalidInput` that add complexity without providing value.

---

## References

- `src/Exception/` - Current exception classes
- `src/Application/Graph/GraphBuilder.php` - Graph construction
- `src/Domain/Order/Order.php` - Order validation
- `src/Application/Config/PathSearchConfig.php` - Configuration validation
- Previous audit: `docs/audits/error-handling-audit.md`

