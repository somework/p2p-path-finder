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

## Execution Plan Results (Recommended)

### ExecutionPlan

**Class**: `SomeWork\P2PPathFinder\Application\PathSearch\Result\ExecutionPlan`

**Purpose**: Represents a complete execution plan that can express both linear paths and split/merge execution.

**API Methods**:

```php
// Access steps
$steps = $plan->steps();            // ExecutionStepCollection object
$stepCount = $plan->stepCount();    // int

// Access totals
$totalSpent = $plan->totalSpent();        // Money object
$totalReceived = $plan->totalReceived();  // Money object

// Access currencies
$source = $plan->sourceCurrency();    // "USD"
$target = $plan->targetCurrency();    // "BTC"

// Access tolerance
$tolerance = $plan->residualTolerance();  // DecimalTolerance object

// Access fees
$feeBreakdown = $plan->feeBreakdown();    // MoneyMap object

// Check linearity
$isLinear = $plan->isLinear();            // bool
$path = $plan->asLinearPath();            // Path|null (null if non-linear)

// Convenience method
$summary = $plan->toArray();              // For debugging/internal use
```

**Properties**:
- **steps**: ExecutionStepCollection object (individual execution steps)
- **sourceCurrency**: Source currency code (uppercase)
- **targetCurrency**: Target currency code (uppercase)
- **totalSpent**: Money object (sum of spends from source currency)
- **totalReceived**: Money object (sum of receives into target currency)
- **residualTolerance**: DecimalTolerance object (remaining acceptable slippage)
- **feeBreakdown**: MoneyMap object (aggregated fees across all steps)

**Notes**:
- Plans contain at least one step
- `totalSpent()` sums amounts spent in the source currency
- `totalReceived()` sums amounts received in the target currency
- `isLinear()` returns true if the plan is a simple chain (no splits/merges)
- `asLinearPath()` converts to `Path` only if linear, otherwise returns null

**Version History**:
- 2.0.0: Initial introduction

---

### ExecutionStep

**Class**: `SomeWork\P2PPathFinder\Application\PathSearch\Result\ExecutionStep`

**Purpose**: Describes a single execution step in an execution plan with sequence ordering.

**API Methods**:

```php
// Asset information
$from = $step->from();     // "USD"
$to = $step->to();         // "EUR"

// Monetary amounts
$spent = $step->spent();       // Money object (USD 100.00)
$received = $step->received(); // Money object (EUR 90.91)

// Fees
$fees = $step->fees();             // MoneyMap object

// Associated order
$order = $step->order();           // Domain Order instance

// Execution sequence
$sequence = $step->sequenceNumber();  // int (1-based)

// Convenience method
$summary = $step->toArray();          // For debugging/internal use
```

**Properties**:
- **from**: Source asset symbol (uppercase)
- **to**: Destination asset symbol (uppercase)
- **spent**: Money object (amount spent)
- **received**: Money object (amount received, after fees)
- **fees**: MoneyMap object (fees charged for this step)
- **order**: Domain `Order` instance used to fill the step
- **sequenceNumber**: Execution order (1-based integer)

**Constraints**:
- `spent.currency` always equals `from`
- `received.currency` always equals `to`
- `fees` keys are either `from` or `to` currencies
- All asset symbols are uppercase
- `sequenceNumber` is at least 1

**Notes**:
- The `received` amount reflects fees already deducted
- `sequenceNumber` indicates execution order within the plan
- Use `order()` to access order metadata (IDs, venue references, etc.)

**Version History**:
- 2.0.0: Initial introduction

---

### ExecutionStepCollection

**Class**: `SomeWork\P2PPathFinder\Application\PathSearch\Result\ExecutionStepCollection`

**Purpose**: Immutable ordered collection of execution steps sorted by sequence number.

**API Methods**:

```php
$collection = ExecutionStepCollection::fromList([$step1, $step2]);

// Access steps
$count = $collection->count();
$isEmpty = $collection->isEmpty();
$firstStep = $collection->first();
$lastStep = $collection->last();
$specificStep = $collection->at(0);  // Zero-indexed

// Iterate
foreach ($collection as $step) {
    echo "Step {$step->sequenceNumber()}: {$step->from()} to {$step->to()}\n";
}

// Get as array
$stepArray = $collection->all();      // list of ExecutionStep objects
$simpleArray = $collection->toArray(); // For debugging
```

**Structure**:
- Immutable ordered collection of ExecutionStep objects
- Steps are sorted by sequence number
- Empty collection available via `ExecutionStepCollection::empty()`

**Notes**:
- Steps are automatically sorted by `sequenceNumber()` on creation
- Deterministic iteration order based on sequence numbers

**Version History**:
- 2.0.0: Initial introduction

---

## Search Results

### SearchOutcome

**Class**: `SomeWork\P2PPathFinder\Application\PathSearch\Api\Response\SearchOutcome`

**Purpose**: Container for search results and guard metrics.

**Important**: `ExecutionPlanService::findBestPlans()` returns at most **one** optimal execution plan. The `paths()` collection will contain either 0 or 1 entries.

```php
$result = $service->findBestPlans($request);

// Access the single plan (recommended approach)
$hasPaths = $result->hasPaths();     // true if a plan was found
$bestPath = $result->bestPath();     // ExecutionPlan or null
$result->paths()->count();           // 0 or 1

// Access guard report
$guards = $result->guardLimits();    // SearchGuardReport object

// Process the single optimal plan
if (null !== $bestPath) {
    echo "Plan has {$bestPath->stepCount()} steps\n";
    echo "Is linear: " . ($bestPath->isLinear() ? 'yes' : 'no') . "\n";
}

// Check for guard breaches
if ($guards->anyLimitReached()) {
    echo "Search was limited by guards\n";
}
```

**Why single plan?**: The algorithm optimizes for a single global optimum that may include split/merge execution. For alternative routes, run separate searches with different constraints (modified tolerance bounds, different spend amounts, or filtered order books).

**Properties**:
- **paths**: PathResultSet object containing 0 or 1 ExecutionPlan
- **guardLimits**: SearchGuardReport object (search performance metrics)

**Notes**:
- Guard report provides diagnostic information about search limits and performance

**Version History**:
- 1.0.0: Initial structure
- 2.0.0: Returns single optimal ExecutionPlan only

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

---

## Service Contracts

### ExecutionPlanService (Recommended)

**Class**: `SomeWork\P2PPathFinder\Application\PathSearch\Service\ExecutionPlanService`

**Purpose**: Service for finding optimal execution plans that may include split/merge routes.

**API Methods**:

```php
use SomeWork\P2PPathFinder\Application\PathSearch\Service\ExecutionPlanService;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;

// Construction
$service = new ExecutionPlanService(new GraphBuilder());

// With custom ordering strategy
$service = new ExecutionPlanService(new GraphBuilder(), $customOrderingStrategy);

// Execute search
$outcome = $service->findBestPlans($request);  // SearchOutcome<ExecutionPlan>
```

**Capabilities**:
- Multiple orders for same currency direction
- Split execution (input split across parallel routes)
- Merge execution (routes converging at target)
- Linear paths (single chain from source to target)

**Version History**:
- 2.0.0: Initial introduction

---

## Usage Examples

### Working with Search Results (ExecutionPlanService)

Here's how to work with a complete `SearchOutcome` from `ExecutionPlanService`:

```php
$outcome = $service->findBestPlans($request);

// Access guard metrics
$guards = $outcome->guardLimits();
echo "Search took: {$guards->elapsedMilliseconds()}ms\n";
echo "Expansions: {$guards->expansions()}\n";

echo "Plans: {$outcome->paths()->count()}\n";

// Process found plans
foreach ($outcome->paths() as $plan) {
    echo "Spent: {$plan->totalSpent()->amount()} {$plan->totalSpent()->currency()}\n";
    echo "Received: {$plan->totalReceived()->amount()} {$plan->totalReceived()->currency()}\n";
    echo "Tolerance remaining: {$plan->residualTolerance()->percentage(2)}\n";
    echo "Is Linear: " . ($plan->isLinear() ? 'yes' : 'no') . "\n";

    // Process aggregated fees
    foreach ($plan->feeBreakdown() as $currency => $fee) {
        echo "Fee in $currency: {$fee->amount()}\n";
    }

    // Process steps with sequence numbers
    foreach ($plan->steps() as $step) {
        echo "  Step {$step->sequenceNumber()}: {$step->from()} -> {$step->to()}: ";
        echo "spent {$step->spent()->amount()}, received {$step->received()->amount()}\n";

        // Order reference
        $order = $step->order();
        echo "  Filled order across {$order->assetPair()->base()} / {$order->assetPair()->quote()}\n";
    }
}

// Check for guard breaches
if ($guards->anyLimitReached()) {
    echo "Warning: Search was limited by guard constraints\n";
}
```


### Type Safety

All APIs use strongly-typed domain objects with PHPDoc annotations. This provides full type information for static analysis tools like PHPStan and Psalm, ensuring type safety at development time.

---

## Related Documentation

- [API Stability Guide](api-stability.md) - Public API surface definitions
- [README.md](../README.md) - Usage examples and getting started
- [Decimal Strategy](decimal-strategy.md) - Precision handling for monetary values

---

**Document Version**: 2.0.0
**Last Updated**: January 2026
