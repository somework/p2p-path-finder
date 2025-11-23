# Exception Handling Guide

This guide covers all exceptions thrown by the P2P Path Finder library and how to handle them in production systems.

## Table of Contents

- [Exception Hierarchy](#exception-hierarchy)
- [Exception Quick Reference](#exception-quick-reference)
- [Common Exception Scenarios](#common-exception-scenarios)
- [Practical Catch Patterns](#practical-catch-patterns)
- [Production Error Handling](#production-error-handling)
- [HTTP Status Code Mapping](#http-status-code-mapping)

---

## Exception Hierarchy

All library exceptions implement `ExceptionInterface` for easy catch-all handling:

```
Throwable
  └─ ExceptionInterface (marker interface)
      ├─ InvalidInput extends InvalidArgumentException
      ├─ GuardLimitExceeded extends RuntimeException
      ├─ PrecisionViolation extends RuntimeException
      └─ InfeasiblePath extends RuntimeException
```

### Catch All Library Exceptions

```php
use SomeWork\P2PPathFinder\Exception\ExceptionInterface;

try {
    $outcome = $service->findBestPaths($request);
} catch (ExceptionInterface $e) {
    // Handle any library exception
    error_log("Path search failed: " . $e->getMessage());
}
```

---

## Exception Quick Reference

| Exception | When Thrown | Typical Cause | Recovery Strategy |
|-----------|-------------|---------------|-------------------|
| **InvalidInput** | Invalid configuration or input | Negative amounts, invalid tolerance bounds, min > max | Validate input before passing to library |
| **GuardLimitExceeded** | Search guard limit reached (opt-in) | Large order book, dense graph, high hop depth | Increase guard limits or pre-filter orders |
| **PrecisionViolation** | Arithmetic precision loss | Extreme scale differences, overflow | Use reasonable scales (0-30), check inputs |
| **InfeasiblePath** | Path cannot be materialized | Reserved for future use | Not currently thrown |

---

## Common Exception Scenarios

### Scenario 1: InvalidInput - Domain Validation

**When**: Creating domain objects with invalid values

**Examples**:

```php
// Negative amount
Money::fromString('USD', '-100.00', 2);
// → InvalidInput: "Money amount cannot be negative. Got: USD -100.00"

// Same base and quote currency
ExchangeRate::fromString('USD', 'USD', '1.0', 2);
// → InvalidInput: "Exchange rate requires distinct currencies."

// Min > Max in OrderBounds
OrderBounds::from($max, $min);
// → InvalidInput: "Minimum amount cannot exceed the maximum amount."

// Invalid currency code (too short)
Money::fromString('US', '100.00', 2);
// → InvalidInput: "Currency code must be 3-12 uppercase letters. Got: US"

// Tolerance bounds with min > max
->withToleranceBounds('0.10', '0.05')
// → InvalidInput: "Minimum tolerance cannot exceed maximum tolerance."
```

**How to handle**:

```php
try {
    $money = Money::fromString($currency, $amount, $scale);
} catch (InvalidInput $e) {
    // Log and return user-friendly error
    error_log("Invalid money input: " . $e->getMessage());
    return ['error' => 'Invalid amount or currency'];
}
```

### Scenario 2: InvalidInput - Configuration Validation

**When**: Building PathSearchConfig with invalid parameters

**Examples**:

```php
// Max hops < Min hops
->withHopLimits(5, 3)
// → InvalidInput: "Maximum hops must be greater than or equal to minimum hops."

// Negative result limit
->withResultLimit(-1)
// → InvalidInput: "Result limit must be at least one."

// Invalid tolerance bounds (out of range)
->withToleranceBounds('1.5', '2.0')
// → InvalidInput: "Tolerance bounds must be between 0 and 1."
```

**How to handle**:

```php
try {
    $config = PathSearchConfig::builder()
        ->withSpendAmount($amount)
        ->withToleranceBounds($minTol, $maxTol)
        ->withHopLimits($minHops, $maxHops)
        ->build();
} catch (InvalidInput $e) {
    // Return validation error to user
    http_response_code(400);
    return ['error' => 'Invalid configuration: ' . $e->getMessage()];
}
```

### Scenario 3: GuardLimitExceeded - Search Limits (Exception Mode)

**When**: Search hits guard limits with exception mode enabled

**Default Behavior**: Library returns `SearchOutcome` with guard report metadata (NO exception)

**Exception Mode**: Enabled via `withGuardLimitException()`:

```php
$config = PathSearchConfig::builder()
    ->withSpendAmount($amount)
    ->withToleranceBounds('0.0', '0.05')
    ->withHopLimits(1, 4)
    ->withSearchGuards(10000, 25000)
    ->withGuardLimitException()  // Enable exception mode
    ->build();
```

**How to handle**:

```php
use SomeWork\P2PPathFinder\Exception\GuardLimitExceeded;

try {
    $outcome = $service->findBestPaths($request);
} catch (GuardLimitExceeded $e) {
    $report = $e->getReport();
    
    error_log("Search guard limit exceeded: " . $e->getMessage());
    error_log("Visited states: {$report->visitedStates()} / {$report->visitedStateLimit()}");
    error_log("Expansions: {$report->expansions()} / {$report->expansionLimit()}");
    
    // Return partial results or error
    http_response_code(503);
    return [
        'error' => 'Search limit exceeded',
        'report' => $report->jsonSerialize(),
    ];
}
```

**Recommended**: Use default metadata mode instead:

```php
// Default mode (no exception)
$outcome = $service->findBestPaths($request);

if ($outcome->guardLimits()->anyLimitReached()) {
    // Log warning but continue with partial results
    error_log("Search hit guard limits, results may be incomplete");
}

// Process available results
foreach ($outcome->paths() as $path) {
    // Use partial results
}
```

### Scenario 4: PrecisionViolation - Arithmetic Errors (Rare)

**When**: Arithmetic precision cannot be maintained

**Examples**:
- Extreme scale differences (scale > 30)
- Division by near-zero values
- Overflow in calculations

**How to handle**:

```php
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

try {
    $result = $money->divide($divisor, $scale);
} catch (PrecisionViolation $e) {
    error_log("Precision violation: " . $e->getMessage());
    // Fallback or abort operation
    return ['error' => 'Calculation error'];
}
```

**Prevention**: Use reasonable scales (0-30) and validate inputs.

---

## Practical Catch Patterns

### Pattern 1: Catch Specific Exceptions

Handle each exception type differently:

```php
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\GuardLimitExceeded;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

try {
    $outcome = $service->findBestPaths($request);
    
    // Process results...
    
} catch (InvalidInput $e) {
    // User error - bad input
    http_response_code(400);
    return ['error' => 'Invalid input: ' . $e->getMessage()];
    
} catch (GuardLimitExceeded $e) {
    // Resource limit - retry with adjusted config
    http_response_code(503);
    return ['error' => 'Search limit exceeded, try reducing scope'];
    
} catch (PrecisionViolation $e) {
    // Rare calculation error
    http_response_code(400);
    return ['error' => 'Calculation error'];
}
```

### Pattern 2: Catch All Library Exceptions

Simpler catch-all approach:

```php
use SomeWork\P2PPathFinder\Exception\ExceptionInterface;

try {
    $outcome = $service->findBestPaths($request);
} catch (ExceptionInterface $e) {
    error_log("Path search failed: " . $e->getMessage());
    
    http_response_code(500);
    return ['error' => 'Search failed'];
}
```

### Pattern 3: Metadata Mode (Recommended)

Use guard reports instead of exceptions:

```php
// No try-catch needed for guards (default mode)
$outcome = $service->findBestPaths($request);

// Check for guard limits
if ($outcome->guardLimits()->anyLimitReached()) {
    error_log("Search hit guard limits");
    // Continue with partial results
}

// Check for empty results
if ($outcome->paths()->isEmpty()) {
    return ['error' => 'No paths found', 'guards' => $outcome->guardLimits()->jsonSerialize()];
}

// Process results
return [
    'paths' => $outcome->paths()->jsonSerialize(),
    'guards' => $outcome->guardLimits()->jsonSerialize(),
];
```

### Pattern 4: Input Validation First

Validate before library calls to avoid exceptions:

```php
// Validate inputs
if ($amount < 0) {
    return ['error' => 'Amount must be positive'];
}

if ($minHops > $maxHops) {
    return ['error' => 'Invalid hop range'];
}

if ($minTolerance > $maxTolerance) {
    return ['error' => 'Invalid tolerance range'];
}

// Build config (should not throw)
try {
    $config = PathSearchConfig::builder()
        ->withSpendAmount(Money::fromString($currency, $amount, $scale))
        ->withToleranceBounds($minTolerance, $maxTolerance)
        ->withHopLimits($minHops, $maxHops)
        ->build();
        
    $outcome = $service->findBestPaths($request);
    
} catch (ExceptionInterface $e) {
    // Shouldn't happen if validation passed
    error_log("Unexpected error: " . $e->getMessage());
    return ['error' => 'Internal error'];
}
```

---

## Production Error Handling

### Complete Production Handler

```php
use SomeWork\P2PPathFinder\Exception\ExceptionInterface;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\GuardLimitExceeded;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

function handlePathSearch(
    PathFinderService $service,
    PathSearchRequest $request
): array {
    try {
        $outcome = $service->findBestPaths($request);
        
        // Check guard limits (metadata mode)
        if ($outcome->guardLimits()->anyLimitReached()) {
            // Log warning but continue
            error_log("Search hit guard limits, results may be incomplete");
        }
        
        // Check for empty results
        if ($outcome->paths()->isEmpty()) {
            return [
                'success' => false,
                'error' => 'No paths found',
                'guards' => $outcome->guardLimits()->jsonSerialize(),
            ];
        }
        
        // Success
        return [
            'success' => true,
            'paths' => $outcome->paths()->jsonSerialize(),
            'guards' => $outcome->guardLimits()->jsonSerialize(),
        ];
        
    } catch (InvalidInput $e) {
        // User error - bad input (400)
        error_log("Invalid input: " . $e->getMessage());
        return [
            'success' => false,
            'error' => 'Invalid input: ' . $e->getMessage(),
            'http_status' => 400,
        ];
        
    } catch (GuardLimitExceeded $e) {
        // Resource limit - service unavailable (503)
        $report = $e->getReport();
        error_log("Guard limit exceeded: " . $e->getMessage());
        return [
            'success' => false,
            'error' => 'Search limit exceeded',
            'report' => $report->jsonSerialize(),
            'http_status' => 503,
        ];
        
    } catch (PrecisionViolation $e) {
        // Calculation error (400)
        error_log("Precision violation: " . $e->getMessage());
        return [
            'success' => false,
            'error' => 'Calculation error',
            'http_status' => 400,
        ];
        
    } catch (ExceptionInterface $e) {
        // Any other library exception (500)
        error_log("Unexpected library error: " . $e->getMessage());
        return [
            'success' => false,
            'error' => 'Search failed',
            'http_status' => 500,
        ];
        
    } catch (\Throwable $e) {
        // Non-library error (500)
        error_log("Unexpected error: " . $e->getMessage());
        return [
            'success' => false,
            'error' => 'Internal error',
            'http_status' => 500,
        ];
    }
}
```

### API Integration Example

```php
// API endpoint handler
public function searchPaths(Request $request): JsonResponse
{
    $result = handlePathSearch($this->pathFinderService, $pathSearchRequest);
    
    if (!$result['success']) {
        return new JsonResponse(
            ['error' => $result['error']],
            $result['http_status'] ?? 500
        );
    }
    
    return new JsonResponse([
        'paths' => $result['paths'],
        'guards' => $result['guards'],
    ]);
}
```

---

## HTTP Status Code Mapping

For REST APIs, map exceptions to HTTP status codes:

| Exception | HTTP Status | Reason | Retry? |
|-----------|-------------|--------|--------|
| **InvalidInput** | 400 Bad Request | Client sent invalid data | ❌ Fix input first |
| **GuardLimitExceeded** | 503 Service Unavailable | Server resource limits | ✅ Yes, with adjusted config |
| **PrecisionViolation** | 400 Bad Request | Client sent problematic values | ❌ Fix input first |
| **ExceptionInterface** | 500 Internal Server Error | Unexpected library error | ⚠️ May be transient |
| **Empty results** | 200 OK | Valid outcome, no paths found | N/A (not an error) |
| **Guard limits (metadata)** | 200 OK | Partial results available | N/A (not an error) |

### Example API Responses

**Success**:
```json
HTTP/1.1 200 OK
{
  "paths": [...],
  "guards": {
    "limits": {"expansions": 50000, "visited_states": 25000},
    "metrics": {"expansions": 3421, "visited_states": 1892},
    "breached": {"any": false}
  }
}
```

**Invalid Input**:
```json
HTTP/1.1 400 Bad Request
{
  "error": "Invalid input: Money amount cannot be negative. Got: USD -100.00"
}
```

**Guard Limit Exceeded** (exception mode):
```json
HTTP/1.1 503 Service Unavailable
{
  "error": "Search limit exceeded",
  "report": {
    "limits": {"expansions": 10000, "visited_states": 5000},
    "metrics": {"expansions": 10000, "visited_states": 4832},
    "breached": {"expansions": true, "any": true}
  }
}
```

**No Paths Found** (not an error):
```json
HTTP/1.1 200 OK
{
  "paths": [],
  "guards": {
    "metrics": {"expansions": 234, "visited_states": 127},
    "breached": {"any": false}
  }
}
```

---

## Best Practices

### 1. Validate Input Before Library Calls

```php
// Good: Validate first
if ($amount <= 0) {
    return ['error' => 'Amount must be positive'];
}
$money = Money::fromString($currency, $amount, $scale);

// Avoid: Relying on exceptions for validation
try {
    $money = Money::fromString($currency, $amount, $scale);
} catch (InvalidInput $e) {
    // Better to validate first
}
```

### 2. Use Metadata Mode for Guard Limits

```php
// Good: Check metadata
$outcome = $service->findBestPaths($request);
if ($outcome->guardLimits()->anyLimitReached()) {
    // Handle gracefully
}

// Avoid: Exception mode (unless you need hard failure)
$config->withGuardLimitException();
```

### 3. Log All Exceptions

```php
// Good: Log with context
catch (InvalidInput $e) {
    error_log(sprintf(
        "Path search failed: %s | Currency: %s | Amount: %s",
        $e->getMessage(),
        $currency,
        $amount
    ));
}
```

### 4. Provide User-Friendly Messages

```php
// Good: User-friendly
catch (InvalidInput $e) {
    return ['error' => 'Invalid amount or currency'];
}

// Avoid: Technical messages
catch (InvalidInput $e) {
    return ['error' => $e->getMessage()]; // Too technical
}
```

### 5. Monitor Exception Rates

Track exception rates to identify issues:

```php
// Metrics to track
$metrics = [
    'exception_rate' => 0.05,  // 5% of requests throw
    'invalid_input_rate' => 0.04,
    'guard_limit_rate' => 0.01,
    'precision_violation_rate' => 0.0001,
];

// Alert if rates exceed thresholds
if ($metrics['exception_rate'] > 0.10) {
    alert("High exception rate in path search");
}
```

---

## FAQ

**Q: What's the difference between `InvalidInput` and `InvalidArgumentException`?**  
A: `InvalidInput` extends `InvalidArgumentException` and implements `ExceptionInterface` for library-specific catching.

**Q: Should I catch exceptions for every library call?**  
A: Validate inputs first, then use try-catch as a safety net. Most exceptions are preventable with proper validation.

**Q: When should I use exception mode for guard limits?**  
A: Only when you need hard failures (e.g., strict SLA requirements). Default metadata mode is recommended for most cases.

**Q: Can `PrecisionViolation` be prevented?**  
A: Yes, use reasonable scales (0-30) and avoid extreme values. This exception is rare with normal inputs.

**Q: What if no paths are found?**  
A: Empty results are NOT an error - check `$outcome->paths()->isEmpty()` and handle as a valid business outcome.

---

## Related Documentation

- [Troubleshooting Guide](troubleshooting.md) - Common issues and solutions
- [Getting Started Guide](getting-started.md) - Library basics
- [API Contracts](api-contracts.md) - JSON serialization format
- [Domain Invariants](domain-invariants.md) - Validation rules

---

*For exception class reference, see `src/Exception/` directory.*
