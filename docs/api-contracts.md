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
- Used in `Path.residualTolerance` field
- Represents remaining tolerance after accounting for a path
- Always between 0.0 (no tolerance left) and 1.0 (full tolerance remaining)

---

## Path Results

### Path

**Class**: `SomeWork\P2PPathFinder\Application\PathSearch\Result\Path`

**Purpose**: Aggregated representation of a discovered conversion path derived from hops.

**API Methods**:

```php
// Access hops
$hops = $path->hops();            // PathHopCollection object
$hopArray = $path->hopsAsArray(); // list<PathHop>

// Access totals
$totalSpent = $path->totalSpent();        // Money object
$totalReceived = $path->totalReceived();  // Money object

// Access tolerance
$tolerance = $path->residualTolerance();          // DecimalTolerance object
$percentage = $path->residualTolerancePercentage(2); // "1.23"

// Access fees
$feeBreakdown = $path->feeBreakdown();    // MoneyMap object
$allFees = $path->feeBreakdownAsArray();  // array<string, Money>

// Convenience method (returns array of key properties)
$summary = $path->toArray();              // For debugging/internal use
```

**Properties**:
- **hops**: PathHopCollection object (individual conversion steps)
- **totalSpent**: Money object derived from the first hop's `spent()` amount
- **totalReceived**: Money object derived from the last hop's `received()` amount
- **residualTolerance**: DecimalTolerance object (remaining acceptable slippage)
- **feeBreakdown**: MoneyMap object that merges fees across all hops by currency

**Notes**:
- Paths are always built from at least one hop
- `feeBreakdown` aggregates hop fees deterministically by currency
- `hopsAsArray()` preserves hop ordering for serialization-friendly scenarios
- Derived totals stay in sync with the hop collection supplied at construction

---

### PathHop

**Class**: `SomeWork\P2PPathFinder\Application\PathSearch\Result\PathHop`

**Purpose**: Describes a single conversion hop in a path and the order that produced it.

**API Methods**:

```php
// Asset information
$from = $hop->from();     // "USD"
$to = $hop->to();         // "GBP"

// Monetary amounts
$spent = $hop->spent();       // Money object (USD 100.00)
$received = $hop->received(); // Money object (GBP 79.60)

// Fees
$fees = $hop->fees();             // MoneyMap object
$feeArray = $hop->feesAsArray();  // array<string, Money>

// Associated order (the same instance you supplied to the order book)
$order = $hop->order();

// Convenience method
$summary = $hop->toArray();       // For debugging/internal use
```

**Properties**:
- **from**: Source asset symbol (uppercase)
- **to**: Destination asset symbol (uppercase)
- **spent**: Money object (amount spent)
- **received**: Money object (amount received, after fees)
- **fees**: MoneyMap object (fees charged for this hop)
- **order**: Domain `Order` instance used to fill the hop

**Constraints**:
- `spent.currency` always equals `from`
- `received.currency` always equals `to`
- `fees` keys are either `from` or `to` currencies
- All asset symbols are uppercase

**Notes**:
- The `received` amount reflects fees already deducted
- Fees may be charged in source or destination currency; multi-currency fees are supported
- Use `order()` to access your upstream order metadata (IDs, venue references, etc.). Because the exact identifier shape is application-specific, attach IDs to your `Order` instances or map them externally (for example, via `spl_object_id($hop->order())`).

---

### PathHopCollection

**Class**: `SomeWork\P2PPathFinder\Application\PathSearch\Result\PathHopCollection`

**Purpose**: Ordered collection of path hops representing a conversion path.

**API Methods**:

```php
$collection = PathHopCollection::fromList([$hop1, $hop2]);

// Access hops
$count = $collection->count();
$firstHop = $collection->first();
$specificHop = $collection->at(0);  // Zero-indexed

// Iterate
foreach ($collection as $hop) {
    echo "Convert {$hop->from()} to {$hop->to()}\n";
}

// Get as array
$hopArray = $collection->all();      // list of PathHop objects
$simpleArray = $collection->toArray(); // For debugging
```

**Structure**:
- Immutable ordered collection of PathHop objects
- Hops are ordered by path sequence
- Empty collection available via `PathHopCollection::empty()`

**Notes**:
- First hop's `from` is the path's source asset
- Last hop's `to` is the path's destination asset
- Each hop's `to` matches the next hop's `from` (if any)

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
$bestPath = $result->bestPath();     // Path|null

// Access guard report
$guards = $result->guardLimits();    // SearchGuardReport object

// Iterate through paths
foreach ($result->paths() as $path) {
    echo "Path has {$path->hops()->count()} hops\n";
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
    echo "Found path with {$path->hops()->count()} hops\n";
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
- Typically contains `Path` objects from search
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

echo "Paths: {$outcome->paths()->count()}\n";

// Process found paths
foreach ($outcome->paths() as $path) {
    echo "Spent: {$path->totalSpent()->amount()} {$path->totalSpent()->currency()}\n";
    echo "Received: {$path->totalReceived()->amount()} {$path->totalReceived()->currency()}\n";
    echo "Tolerance remaining: {$path->residualTolerance()->percentage(2)}\n";

    // Process aggregated fees
    foreach ($path->feeBreakdown() as $currency => $fee) {
        echo "Fee in $currency: {$fee->amount()}\n";
    }

    // Process hops and related orders
    foreach ($path->hops() as $hop) {
        echo "  {$hop->from()} -> {$hop->to()}: ";
        echo "spent {$hop->spent()->amount()}, received {$hop->received()->amount()}\n";

        // Order reference: use your own identifiers on the supplied order instances
        $order = $hop->order();
        echo "  Filled order across {$order->assetPair()->base()} / {$order->assetPair()->quote()}\n";
    }
}

// Check for guard breaches
if ($guards->anyLimitReached()) {
    echo "Warning: Search was limited by guard constraints\n";
}
```

This example demonstrates:
- Paths composed of ordered hops with deterministic totals
- Fees in multiple currencies aggregated at the path level
- Hop-by-hop access to the originating orders for downstream reconciliation
- Guard metrics showing the search completed within limits

### Type Safety

All APIs use strongly-typed domain objects with PHPDoc annotations. This provides full type information for static analysis tools like PHPStan and Psalm, ensuring type safety at development time.

---

## Related Documentation

- [API Stability Guide](api-stability.md) - Public API surface definitions
- [README.md](../README.md) - Usage examples and getting started
- [Decimal Strategy](decimal-strategy.md) - Precision handling for monetary values

---

**Document Version**: 1.1.0
**Last Updated**: April 2025
