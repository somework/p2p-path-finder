# API Contracts

This document defines the object APIs for all public types in the P2P Path Finder library.

---

## Current API Contracts

### Object APIs (Stable)

The following object APIs remain stable and are the recommended way to access data:

---

## Core Domain Types

### Money

**Class**: `SomeWork\P2PPathFinder\Domain\Money\Money`

**Purpose**: Represents a monetary amount in a specific currency with decimal precision.

**API Methods**:

```php
$money = Money::fromString('USD', '100.50', 2);

echo $money->currency();  // "USD"
echo $money->amount();    // "100.50"
echo $money->scale();     // 2

$sum = $money->add($other);
$doubled = $money->multipliedBy('2.0', 2);
```

**Properties**:
- **Currency**: ISO currency code (uppercase, e.g., "USD")
- **Amount**: Decimal amount as numeric string for precision
- **Scale**: Decimal places (0-18)

**Notes**:
- Uses arbitrary precision arithmetic (Brick\Math)
- Amount is a string to preserve precision for large numbers
- Scale indicates significant decimal places
- Immutable value object

---

### MoneyMap

**Class**: `SomeWork\P2PPathFinder\Domain\Money\MoneyMap`

**Purpose**: Immutable map of currency codes to Money objects, used for fee breakdowns.

**API Methods**:

```php
$map = MoneyMap::fromList([
    Money::fromString('USD', '1.50', 2),
    Money::fromString('EUR', '0.45', 2),
]);

// Access by currency
$usdFee = $map->get('USD');
$hasEur = $map->has('EUR');

// Iterate over all entries
foreach ($map as $currency => $money) {
    echo "$currency: {$money->amount()}\n";
}
```

**Structure**:
- Immutable map with currency codes as keys
- Each value is a Money object
- Keys are sorted alphabetically
- Empty map available via `MoneyMap::empty()`

**Notes**:
- Currency keys always match Money object currencies
- Deterministic ordering for consistent behavior

---

### DecimalTolerance

**Class**: `SomeWork\P2PPathFinder\Domain\Tolerance\DecimalTolerance`

**Purpose**: Represents a tolerance ratio as a decimal value between 0 and 1.

**API Methods**:

```php
// Create tolerance
$tolerance = DecimalTolerance::fromNumericString('0.05', 2);

// Access values
echo $tolerance->ratio();        // "0.050000000000000000"
echo $tolerance->percentage(2);  // "5.00"
echo $tolerance->scale();        // 2

// Comparisons
if ($tolerance->isGreaterThanOrEqual('0.03')) {
    // Tolerance is >= 3%
}
```

**Properties**:
- **Ratio**: Decimal string between "0.0" and "1.0"
- **Scale**: Decimal precision (canonical scale: 18)
- **Percentage**: Human-readable percentage string

**Examples**:
- `"0.0000000000"` - Zero tolerance (exact match required)
- `"0.050000000000000000"` - 5% tolerance at scale 18
- `"1.000000000000000000"` - 100% tolerance (maximum)

**Usage in API**:
- Used in `PathResult.residualTolerance` field
- Represents remaining tolerance after accounting for a path
- Always between 0.0 (no tolerance left) and 1.0 (full tolerance remaining)

---

## Path Results

### PathResult

**Class**: `SomeWork\P2PPathFinder\Application\PathSearch\Result\PathResult`

**Purpose**: Aggregated representation of a discovered conversion path.

**API Methods**:

```php
// Access totals
$totalSpent = $path->totalSpent();        // Money object
$totalReceived = $path->totalReceived();  // Money object

// Access tolerance
$tolerance = $path->residualTolerance();  // DecimalTolerance object
$ratio = $tolerance->ratio();             // "0.0123456789"
$percentage = $tolerance->percentage(2);  // "1.23"

// Access fees
$feeBreakdown = $path->feeBreakdown();    // MoneyMap object
$allFees = $path->feeBreakdownAsArray();  // array<string, Money>

// Access legs
$legs = $path->legs();                    // PathLegCollection object
$legArray = $path->legsAsArray();         // array of PathLeg objects

// Convenience method (returns array of key properties)
$summary = $path->toArray();              // For debugging/internal use
```

**Properties**:
- **totalSpent**: Money object (source asset amount)
- **totalReceived**: Money object (destination asset amount)
- **residualTolerance**: DecimalTolerance object (remaining acceptable slippage)
- **feeBreakdown**: MoneyMap object (total fees by currency)
- **legs**: PathLegCollection object (individual conversion steps)

**Notes**:
- All amounts use arbitrary precision arithmetic
- Fee breakdown aggregates fees across all legs by currency
- Legs represent the conversion path sequence
- Empty legs possible for direct conversions

**Version History**:
- 1.0.0: Initial structure

---

### PathLeg

**Class**: `SomeWork\P2PPathFinder\Application\PathSearch\Result\PathLeg`

**Purpose**: Describes a single conversion leg in a path.

**API Methods**:

```php
// Asset information
$from = $leg->from();     // "USD"
$to = $leg->to();         // "GBP"

// Monetary amounts
$spent = $leg->spent();       // Money object (USD 100.00)
$received = $leg->received(); // Money object (GBP 79.60)

// Fees
$fees = $leg->fees();             // MoneyMap object
$feeArray = $leg->feesAsArray();  // array<string, Money>

// Convenience method
$summary = $leg->toArray();        // For debugging/internal use
```

**Properties**:
- **from**: Source asset symbol (uppercase)
- **to**: Destination asset symbol (uppercase)
- **spent**: Money object (amount spent)
- **received**: Money object (amount received, after fees)
- **fees**: MoneyMap object (fees charged for this leg)

**Constraints**:
- `spent.currency` always equals `from`
- `received.currency` always equals `to`
- `fees` keys are either `from` or `to` currencies
- All asset symbols are uppercase

**Notes**:
- The `received` amount reflects fees already deducted
- Fees may be charged in source or destination currency
- Multi-currency fees supported

**Version History**:
- 1.0.0: Initial structure

---

### PathLegCollection

**Class**: `SomeWork\P2PPathFinder\Application\PathSearch\Result\PathLegCollection`

**Purpose**: Ordered collection of path legs representing a conversion path.

**API Methods**:

```php
$collection = PathLegCollection::fromList([$leg1, $leg2]);

// Access legs
$count = $collection->count();
$firstLeg = $collection->first();
$specificLeg = $collection->at(0);  // Zero-indexed

// Iterate
foreach ($collection as $leg) {
    echo "Convert {$leg->from()} to {$leg->to()}\n";
}

// Get as array
$legsArray = $collection->all();    // array of PathLeg objects
$simpleArray = $collection->toArray(); // For debugging
```

**Structure**:
- Immutable ordered collection of PathLeg objects
- Legs are ordered by path sequence
- Empty collection available via `PathLegCollection::empty()`

**Notes**:
- First leg's `from` is the path's source asset
- Last leg's `to` is the path's destination asset
- Each leg's `to` matches next leg's `from` (if any)

---

## Search Results

### SearchOutcome

**Class**: `SomeWork\P2PPathFinder\Application\PathSearch\Api\Response\SearchOutcome`

**Purpose**: Container for search results and guard metrics.

**API Methods**:

```php
$result = $service->findBestPaths($request);

// Access paths
$paths = $result->paths();           // PathResultSet object
$hasPaths = $result->hasPaths();     // boolean

// Access guard report
$guards = $result->guardLimits();    // SearchGuardReport object

// Iterate through paths
foreach ($result->paths() as $path) {
    echo "Path found: {$path->route()}\n";
}

// Check for guard breaches
if ($guards->anyLimitReached()) {
    echo "Search was limited by guards\n";
}
```

**Properties**:
- **paths**: PathResultSet object (found conversion paths)
- **guardLimits**: SearchGuardReport object (search performance metrics)

**Notes**:
- Paths are ordered by configured strategy (default: cost, then hops, then route signature)
- Number of paths limited by `PathSearchConfig.resultLimit`
- Guard report provides diagnostic information about search limits and performance

**Version History**:
- 1.0.0: Initial structure

---

### SearchGuardReport

**Class**: `SomeWork\P2PPathFinder\Application\PathSearch\Result\SearchGuardReport`

**Purpose**: Immutable snapshot describing how the search interacted with its guard rails.

**API Methods**:

```php
$report = $outcome->guardLimits();

// Check individual limits
$expansionsReached = $report->expansionsReached();      // boolean
$statesReached = $report->visitedStatesReached();       // boolean
$timeBudgetReached = $report->timeBudgetReached();      // boolean
$anyLimitReached = $report->anyLimitReached();          // boolean

// Access metrics
$expansions = $report->expansions();              // int
$visitedStates = $report->visitedStates();        // int
$elapsedMs = $report->elapsedMilliseconds();      // float

// Access configured limits
$maxExpansions = $report->expansionLimit();       // int
$maxStates = $report->visitedStateLimit();        // int
$timeBudgetMs = $report->timeBudgetLimit();       // int|null
```

**Properties**:
- **Limits**: Configured maximum values for search constraints
- **Metrics**: Actual values recorded during search execution
- **Breached Flags**: Boolean indicators for each limit type

**Limit Types**:
- **expansions**: Maximum allowed node expansions
- **visited_states**: Maximum allowed visited states
- **time_budget_ms**: Time budget in milliseconds (null = unlimited)

**Notes**:
- All metrics start at 0 for fresh searches
- `elapsed_ms` measured using high-resolution timers
- `anyLimitReached()` is convenience method for checking any breach
- If time budget is null, `timeBudgetReached()` always returns false
- Breached limits cause early search termination

**Version History**:
- 1.0.0: Initial structure

---

### PathResultSet

**Class**: `SomeWork\P2PPathFinder\Application\PathSearch\Result\PathResultSet<TPath>`

**Purpose**: Generic ordered collection of path results.

**API Methods**:

```php
$paths = $outcome->paths();  // PathResultSet object

// Collection operations
$count = $paths->count();
$isEmpty = $paths->isEmpty();
$firstPath = $paths->first();

// Iterate through paths
foreach ($paths as $path) {
    echo "Found path: {$path->route()}\n";
}

// Slice collection
$top3Paths = $paths->slice(0, 3);

// Convert to array (for debugging)
$pathArray = $paths->toArray();
```

**Structure**:
- Immutable ordered collection of path objects
- Empty collection available via `PathResultSet::empty()`
- Paths ordered by configured `PathOrderStrategy`

**Notes**:
- Generic type - content type varies by usage
- Typically contains `PathResult` objects from search
- Ordering is stable and deterministic

---

## Usage Examples

### Working with Search Results

Here's how to work with a complete `SearchOutcome` containing paths and guard metrics:

```php
$outcome = $service->findBestPaths($request);

// Access guard metrics
$guards = $outcome->guardLimits();
echo "Search took: {$guards->elapsedMilliseconds()}ms\n";
echo "Expansions: {$guards->expansions()}\n";

// Process found paths
foreach ($outcome->paths() as $path) {
    echo "Path: {$path->route()}\n";
    echo "Spent: {$path->totalSpent()->amount()} {$path->totalSpent()->currency()}\n";
    echo "Received: {$path->totalReceived()->amount()} {$path->totalReceived()->currency()}\n";
    echo "Tolerance remaining: {$path->residualTolerance()->percentage(2)}\n";

    // Process fees
    foreach ($path->feeBreakdown() as $currency => $fee) {
        echo "Fee in $currency: {$fee->amount()}\n";
    }

    // Process legs
    foreach ($path->legs() as $leg) {
        echo "  {$leg->from()} -> {$leg->to()}: ";
        echo "spent {$leg->spent()->amount()}, received {$leg->received()->amount()}\n";
    }
}

// Check for guard breaches
if ($guards->anyLimitReached()) {
    echo "Warning: Search was limited by guard constraints\n";
}
```

This example demonstrates:
- Two paths found: one 2-hop path (USD -> JPY -> EUR) and one direct path (USD -> EUR)
- Fees in multiple currencies (JPY and EUR)
- Complete leg-by-leg breakdown for each path
- Guard metrics showing the search completed well within limits

### Type Safety

All APIs use strongly-typed domain objects with PHPDoc annotations. This provides full type information for static analysis tools like PHPStan and Psalm, ensuring type safety at development time.

---

## Related Documentation

- [API Stability Guide](api-stability.md) - Public API surface definitions
- [README.md](../README.md) - Usage examples and getting started
- [Decimal Strategy](decimal-strategy.md) - Precision handling for monetary values

---

**Document Version**: 1.0.0  
**Last Updated**: November 2024

