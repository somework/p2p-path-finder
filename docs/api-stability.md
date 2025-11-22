# API Stability Guide

This document defines the public API surface of the p2p-path-finder package that will remain stable across minor and patch releases in the 1.0+ series. Classes and methods marked as `@internal` may change without notice and should not be relied upon by consuming applications.

## Table of Contents

- [Public API (Stable in 1.0+)](#public-api-stable-in-10)
  - [Core Services](#core-services)
  - [Configuration](#configuration)
  - [Request and Response Objects](#request-and-response-objects)
  - [Extension Points (Interfaces)](#extension-points-interfaces)
  - [Domain Layer](#domain-layer)
  - [Graph Building](#graph-building)
  - [Exceptions](#exceptions)
- [Internal API (May change without notice)](#internal-api-may-change-without-notice)
- [Requires Decision](#requires-decision)

---

## Public API (Stable in 1.0+)

The following classes, interfaces, and methods form the stable public API surface. These are safe to depend on in production applications and will follow semantic versioning guarantees.

### Core Services

#### `SomeWork\P2PPathFinder\Application\Service\PathFinderService`

**Purpose**: Main facade for path finding operations

**Public Methods**:
- `__construct(GraphBuilder $graphBuilder, ?PathOrderStrategy $orderingStrategy = null)` - Constructs the service with required dependencies
- `findBestPaths(PathSearchRequest $request): SearchOutcome` - Searches for optimal conversion paths

**Description**: The primary entry point for path finding operations. Orchestrates filtering, graph construction, and search execution. The service accepts an optional custom `PathOrderStrategy` for controlling result ordering.

**Usage**: Documented in README.md as the main consumer-facing service.

---

#### `SomeWork\P2PPathFinder\Application\OrderBook\OrderBook`

**Purpose**: Container for orders participating in path search

**Public Methods**:
- `__construct(iterable $orders = [])` - Creates order book from iterable collection
- `add(Order $order): void` - Appends an order to the book
- `getIterator(): Traversable` - Returns iterator over orders
- `filter(OrderFilterInterface ...$filters): Generator` - Filters orders using provided filter strategies

**Description**: Iterable collection of orders that can be filtered before graph construction.

---

### Configuration

#### `SomeWork\P2PPathFinder\Application\Config\PathSearchConfig`

**Purpose**: Immutable configuration for path search operations

**Public Methods**:
- `builder(): PathSearchConfigBuilder` - Returns fluent builder for constructing configurations
- `spendAmount(): Money` - Returns target spend amount
- `toleranceWindow(): ToleranceWindow` - Returns tolerance window applied to spend
- `minimumTolerance(): string` - Returns lower tolerance bound as numeric-string
- `maximumTolerance(): string` - Returns upper tolerance bound as numeric-string
- `minimumHops(): int` - Returns minimum hops allowed in results
- `maximumHops(): int` - Returns maximum hops allowed in results
- `resultLimit(): int` - Returns maximum number of paths to return
- `minimumSpendAmount(): Money` - Returns minimum spend after tolerance adjustments
- `maximumSpendAmount(): Money` - Returns maximum spend after tolerance adjustments
- `pathFinderTolerance(): string` - Returns tolerance used by search heuristic
- `pathFinderToleranceSource(): string` - Returns origin of tolerance value
- `pathFinderMaxExpansions(): int` - Returns maximum state expansions allowed
- `pathFinderMaxVisitedStates(): int` - Returns maximum unique states tracked
- `pathFinderTimeBudgetMs(): ?int` - Returns wall-clock budget in milliseconds
- `throwOnGuardLimit(): bool` - Returns whether guard breaches throw exceptions

**Description**: Captures all parameters used during graph exploration. Built via fluent builder pattern.

---

#### `SomeWork\P2PPathFinder\Application\Config\PathSearchConfigBuilder`

**Purpose**: Fluent builder for constructing validated PathSearchConfig instances

**Public Methods**:
- `withSpendAmount(Money $amount): self` - Sets source asset spend amount
- `withToleranceBounds(string $minimumTolerance, string $maximumTolerance): self` - Configures acceptable deviation from desired spend
- `withHopLimits(int $minimumHops, int $maximumHops): self` - Sets minimum and maximum hops
- `withResultLimit(int $limit): self` - Limits number of paths returned
- `withSearchGuards(int $maxVisitedStates, int $maxExpansions, ?int $timeBudgetMs = null): self` - Configures guard limits
- `withSearchTimeBudget(?int $timeBudgetMs): self` - Sets wall-clock budget
- `withGuardLimitException(bool $shouldThrow = true): self` - Enables exception on guard breach
- `build(): PathSearchConfig` - Builds validated configuration instance

**Description**: Part of the public API as documented in README examples.

---

#### `SomeWork\P2PPathFinder\Application\Config\SearchGuardConfig`

**Purpose**: Configuration for search guard limits

**Public Methods**:
- `__construct(int $maxVisitedStates, int $maxExpansions, ?int $timeBudgetMs = null)` - Creates guard configuration
- `defaults(): self` - Returns default guard configuration
- `maxVisitedStates(): int` - Returns maximum visited states limit
- `maxExpansions(): int` - Returns maximum expansions limit
- `timeBudgetMs(): ?int` - Returns time budget in milliseconds
- `withTimeBudget(?int $timeBudgetMs): self` - Returns copy with updated time budget

**Description**: Encapsulates search guard rail configuration.

---

### Request and Response Objects

#### `SomeWork\P2PPathFinder\Application\Service\PathSearchRequest`

**Purpose**: Immutable request DTO carrying dependencies for path search

**Public Methods**:
- `__construct(OrderBook $orderBook, PathSearchConfig $config, string $targetAsset)` - Creates request with required parameters
- `orderBook(): OrderBook` - Returns order book
- `config(): PathSearchConfig` - Returns configuration
- `targetAsset(): string` - Returns normalized target asset identifier
- `spendAmount(): Money` - Returns spend amount
- `sourceAsset(): string` - Returns source asset from spend amount currency
- `minimumHops(): int` - Returns minimum hops from config
- `maximumHops(): int` - Returns maximum hops from config
- `spendConstraints(): SpendConstraints` - Returns derived spend constraints

**Description**: Mandatory DTO passed to `PathFinderService::findBestPaths()`. Normalizes target asset and derives spend constraints.

**Usage**: Documented in README.md as the required request parameter.

---

#### `SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\SpendConstraints`

**Purpose**: Spend boundaries derived from tolerance window configuration

**Public Methods**:
- `from(Money $min, Money $max, ?Money $desired = null): self` - Creates constraints from Money instances
- `fromScalars(string $currency, string $minAmount, string $maxAmount, ?string $desiredAmount = null): self` - Creates from numeric strings
- `min(): Money` - Returns minimum spend boundary
- `max(): Money` - Returns maximum spend boundary
- `desired(): ?Money` - Returns desired spend amount
- `bounds(): array` - Returns associative array with min/max bounds

**Description**: Value object encapsulating minimum, maximum, and desired spend amounts that constrain path finding. Exposed through `PathSearchRequest::spendConstraints()` to provide transparency into how the tolerance window translates to actual spend constraints.

**Usage Context**: Consumers typically don't need to interact with this directly as path finding happens automatically based on PathSearchConfig. Provided for transparency and advanced use cases where understanding the derived boundaries is useful.

---

#### `SomeWork\P2PPathFinder\Application\PathFinder\Result\SearchOutcome`

**Purpose**: Container for search results and guard metrics

**Generic Type**: `SearchOutcome<TPath>` where TPath is the result type (typically PathResult)

**Public Methods**:
- `__construct(PathResultSet $paths, SearchGuardReport $guardLimits)` - Creates outcome
- `fromResultSet(PathResultSet $paths, SearchGuardReport $guardLimits): self` - Static factory
- `empty(SearchGuardReport $guardLimits): self` - Creates empty outcome
- `paths(): PathResultSet` - Returns path results collection
- `hasPaths(): bool` - Returns whether any paths were found
- `guardLimits(): SearchGuardReport` - Returns guard metrics
- `jsonSerialize(): array` - Serializes to JSON-compatible array

**Description**: Returned by `PathFinderService::findBestPaths()`. Contains both path results and guard metrics.

**Usage**: Extensively documented in README.md for accessing results and guard reports.

---

#### `SomeWork\P2PPathFinder\Application\PathFinder\Result\PathResultSet`

**Purpose**: Immutable ordered collection of path results

**Generic Type**: `PathResultSet<TPath>`

**Public Methods**:
- `empty(): self` - Creates empty result set
- `fromPaths(PathOrderStrategy $orderingStrategy, iterable $paths, callable $orderKeyResolver): self` - Creates from paths with resolver
- `getIterator(): Traversable` - Returns iterator over paths
- `count(): int` - Returns number of paths
- `isEmpty(): bool` - Returns whether collection is empty
- `toArray(): array` - Returns paths as array
- `slice(int $offset, ?int $length = null): self` - Returns subset of paths
- `first(): mixed` - Returns first path or null
- `jsonSerialize(): array` - Serializes to JSON-compatible array

**Description**: Immutable collection providing iteration, slicing, and serialization helpers. Implements `IteratorAggregate`, `Countable`, and `JsonSerializable`.

**Usage**: Documented in README.md as the return type from `SearchOutcome::paths()`.

---

#### `SomeWork\P2PPathFinder\Application\Result\PathResult`

**Purpose**: Aggregated representation of a discovered conversion path

**Public Methods**:
- `__construct(Money $totalSpent, Money $totalReceived, DecimalTolerance $residualTolerance, ?PathLegCollection $legs = null, ?MoneyMap $feeBreakdown = null)` - Creates path result
- `totalSpent(): Money` - Returns total amount spent across entire path
- `totalReceived(): Money` - Returns total amount received at destination
- `residualTolerance(): DecimalTolerance` - Returns remaining tolerance after path execution
- `residualTolerancePercentage(int $scale = 2): string` - Returns tolerance as percentage
- `feeBreakdown(): MoneyMap` - Returns fees by currency
- `feeBreakdownAsArray(): array` - Returns fees as associative array
- `legs(): PathLegCollection` - Returns collection of path legs
- `legsAsArray(): array` - Returns legs as array
- `toArray(): array` - Returns complete path data as array
- `jsonSerialize(): array` - Serializes to JSON-compatible array

**Description**: Complete representation of a single path with spent/received amounts, tolerance consumption, fee breakdown, and leg-by-leg details.

**Usage**: Referenced in README.md examples as the result type from `SearchOutcome::paths()`.

---

#### `SomeWork\P2PPathFinder\Application\Result\PathLeg`

**Purpose**: Single conversion leg in a path

**Public Methods**:
- `__construct(string $fromAsset, string $toAsset, Money $spent, Money $received, ?MoneyMap $fees = null)` - Creates path leg
- `from(): string` - Returns source asset symbol
- `to(): string` - Returns destination asset symbol
- `spent(): Money` - Returns amount spent in this leg
- `received(): Money` - Returns amount received in this leg
- `fees(): MoneyMap` - Returns fees incurred
- `feesAsArray(): array` - Returns fees as array
- `toArray(): array` - Returns leg data as array
- `jsonSerialize(): array` - Serializes to JSON-compatible array

**Description**: Represents a single hop in a conversion path with from/to assets, spent/received amounts, and fees.

---

#### `SomeWork\P2PPathFinder\Application\Result\PathLegCollection`

**Purpose**: Immutable collection of path legs

**Public Methods**:
- `empty(): self` - Creates empty collection
- `fromList(array $legs): self` - Creates from array of PathLeg instances
- `all(): array` - Returns all legs as array
- `count(): int` - Returns number of legs
- `isEmpty(): bool` - Returns whether collection is empty
- `getIterator(): Traversable` - Returns iterator over legs
- `jsonSerialize(): array` - Serializes to JSON-compatible array

**Description**: Type-safe collection for path legs implementing iteration and serialization.

---

#### `SomeWork\P2PPathFinder\Application\PathFinder\Result\SearchGuardReport`

**Purpose**: Snapshot of search guard metrics

**Public Methods**:
- `__construct(bool $expansionsReached, bool $visitedStatesReached, bool $timeBudgetReached, int $expansions, int $visitedStates, float $elapsedMilliseconds, int $expansionLimit, int $visitedStateLimit, ?int $timeBudgetLimit)` - Creates report
- `fromMetrics(int $expansions, int $visitedStates, float $elapsedMilliseconds, int $expansionLimit, int $visitedStateLimit, ?int $timeBudgetLimit, bool $expansionLimitReached = false, bool $visitedStatesReached = false, bool $timeBudgetReached = false): self` - Creates from metrics
- `idle(int $maxVisitedStates, int $maxExpansions, ?int $timeBudgetMs = null): self` - Creates idle report with zero metrics
- `none(): self` - Creates minimal report
- `expansionsReached(): bool` - Returns whether expansion limit was hit
- `visitedStatesReached(): bool` - Returns whether visited states limit was hit
- `timeBudgetReached(): bool` - Returns whether time budget was exceeded
- `anyLimitReached(): bool` - Returns whether any limit was reached
- `expansions(): int` - Returns actual expansion count
- `visitedStates(): int` - Returns actual visited states count
- `elapsedMilliseconds(): float` - Returns elapsed time
- `expansionLimit(): int` - Returns configured expansion limit
- `visitedStateLimit(): int` - Returns configured visited state limit
- `timeBudgetLimit(): ?int` - Returns configured time budget
- `jsonSerialize(): array` - Serializes to JSON-compatible array

**Description**: Reports on guard rail interactions during search, including which limits were reached and actual metrics.

**Usage**: Extensively documented in README.md for monitoring guard behavior.

---

#### `SomeWork\P2PPathFinder\Application\Result\MoneyMap`

**Purpose**: Type-safe map of Money values indexed by currency

**Public Methods**:
- `empty(): self` - Creates empty map
- `fromList(array $amounts, bool $merge = false): self` - Creates from array of Money instances
- `merge(MoneyMap $other): self` - Merges with another map
- `toArray(): array` - Returns associative array keyed by currency
- `isEmpty(): bool` - Returns whether map is empty
- `jsonSerialize(): array` - Serializes to JSON-compatible array

**Description**: Used for fee breakdowns and currency-grouped amounts.

---

### Extension Points (Interfaces)

#### `SomeWork\P2PPathFinder\Application\Filter\OrderFilterInterface`

**Purpose**: Strategy for filtering orders before graph construction

**Public Methods**:
- `accepts(Order $order): bool` - Determines if order satisfies filter conditions

**Description**: Implement this interface to create custom order filters. Used with `OrderBook::filter()`.

**Contract Requirements**:
- **Performance**: Must be O(1) per order (constant time evaluation)
- **Immutability**: Must not modify the order being evaluated
- **Stateless**: Filter evaluation must be side-effect free (pure function)
- **Thread-safe**: Immutable state after construction

**Best Practices**:
1. Single Responsibility - each filter checks one criterion
2. Composition - combine multiple simple filters rather than one complex filter
3. Early Return - return false as soon as a condition fails
4. Scale Handling - normalize scales when comparing Money values
5. Currency Matching - always verify currency compatibility before comparisons

**Built-in Implementations**:
- `CurrencyPairFilter` - Filters by asset pair
- `MaximumAmountFilter` - Filters by maximum amount
- `MinimumAmountFilter` - Filters by minimum amount  
- `ToleranceWindowFilter` - Filters by tolerance window

**Usage Example**: See `examples/custom-order-filter.php` for comprehensive examples including:
- Custom filter implementations
- Filter composition patterns
- Integration with PathFinderService
- Performance best practices

---

#### `SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathOrderStrategy`

**Purpose**: Strategy for comparing and ordering path results during search

**Public Methods**:
- `compare(PathOrderKey $left, PathOrderKey $right): int` - Compares two path order keys to determine their relative priority

**Description**: Implement this interface to customize how paths are ranked and prioritized during search. The strategy determines which paths appear first in search results. This is a key extension point for customizing search behavior based on business requirements.

**Contract Requirements**:
- **Comparison Semantics**: Return negative if `$left` ranks before `$right`, zero if equal, positive if after
- **Transitivity**: If A < B and B < C, then A < C must hold
- **Stability**: Always use `insertionOrder()` as the final tie-breaker to ensure stable sorting
- **Determinism**: Given the same inputs, always produce the same result
- **Consistency**: Must satisfy antisymmetry and equality propagation

**Common Strategies**:
- **Cost-first** (default): Minimize total path cost, then hops, then route signature
- **Hops-first**: Minimize number of hops (route complexity), then cost
- **Hybrid**: Balance cost and hops using weighted scoring
- **Route-aware**: Prefer certain currencies or exchanges in the path

**Default Implementation**: `CostHopsSignatureOrderingStrategy` - Orders by cost (at scale 6), then hops, then route signature, then insertion order.

**Usage**: Pass custom strategy to `PathFinderService` constructor. See `examples/custom-ordering-strategy.php` for comprehensive examples including:
- `MinimizeHopsStrategy` - Prioritizes simpler paths with fewer hops
- `WeightedScoringStrategy` - Balances cost and complexity using weighted scores
- `RoutePreferenceStrategy` - Prefers paths containing specific currencies

**Example**:
```php
$customStrategy = new MinimizeHopsStrategy(costScale: 6);
$service = new PathFinderService($graphBuilder, $customStrategy);
$results = $service->findBestPaths($request);
```

**Rationale**: Core extension point for customizing search prioritization. Interface is stable with clear contract requirements documented. Multiple working examples demonstrate proper implementation patterns.

---

#### `SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathOrderKey`

**Purpose**: Value object containing path attributes for ordering comparisons

**Public Methods**:
- `__construct(PathCost $cost, int $hops, RouteSignature $routeSignature, int $insertionOrder, array $payload = [])` - Creates order key
- `cost(): PathCost` - Returns path cost
- `hops(): int` - Returns hop count
- `routeSignature(): RouteSignature` - Returns route signature
- `insertionOrder(): int` - Returns discovery order
- `payload(): array` - Returns custom payload data

**Description**: Passed to `PathOrderStrategy::compare()` implementations. Contains all attributes needed to make ordering decisions. The `insertionOrder` field must be used as the final tie-breaker to ensure stable sorting.

**Usage**: Received as parameters in custom `PathOrderStrategy::compare()` implementations. Access attributes to implement custom comparison logic.

**Rationale**: Essential for implementing custom PathOrderStrategy. Provides all path attributes needed for ordering decisions. Used in all ordering examples.

---

#### `SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathCost`

**Purpose**: Value object representing normalized path cost for ordering comparisons

**Public Methods**:
- `__construct(BigDecimal|string $value)` - Creates cost from value
- `value(): string` - Returns cost as canonical numeric string (18 decimal places)
- `decimal(): BigDecimal` - Returns underlying BigDecimal representation
- `equals(PathCost $other): bool` - Checks equality at full precision
- `compare(PathCost $other, int $scale = 18): int` - Compares costs at specified scale

**Description**: Encapsulates path cost with normalized precision. The `compare()` method allows scale-controlled comparisons, which is useful for ordering strategies that want to ignore insignificant cost differences.

**Usage**: Accessed via `PathOrderKey::cost()` in custom ordering strategies. Use `compare()` method for precise cost comparisons.

**Rationale**: Required for custom ordering strategies that prioritize by cost. Provides scale-aware comparison to avoid floating-point precision issues.

---

#### `SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\RouteSignature`

**Purpose**: Value object representing route as currency sequence for deterministic ordering

**Public Methods**:
- `fromPathEdgeSequence(PathEdgeSequence $edges): self` - Creates from edge sequence
- `fromString(string $value): self` - Creates from string representation
- `fromNodes(iterable $nodes): self` - Creates from node sequence
- `value(): string` - Returns signature string (e.g., "USD->EUR->GBP")
- `compare(RouteSignature $other): int` - Lexicographically compares signatures

**Description**: Represents a path's route as a sequence of currencies. Used for deterministic ordering when cost and hops are equal. The `compare()` method provides lexicographical comparison.

**Usage**: Accessed via `PathOrderKey::routeSignature()` in custom ordering strategies. Useful for route-aware prioritization or as a tie-breaker.

**Rationale**: Required for custom ordering strategies that consider route composition. Provides deterministic comparison of path routes.

---

#### `SomeWork\P2PPathFinder\Domain\Order\FeePolicy`

**Purpose**: Strategy for calculating order fees

**Public Methods**:
- `calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown` - Calculates fee components
- `fingerprint(): string` - Returns unique identifier for policy configuration

**Description**: Implement this interface to define custom fee calculation logic. Attached to Order instances.

**Important**: The `fingerprint()` method must return a globally unique string representing the policy configuration. See interface documentation for requirements.

---

### Domain Layer

#### `SomeWork\P2PPathFinder\Domain\Order\Order`

**Purpose**: Domain entity describing an order that can be traversed in path search

**Public Methods**:
- `__construct(OrderSide $side, AssetPair $assetPair, OrderBounds $bounds, ExchangeRate $effectiveRate, ?FeePolicy $feePolicy = null)` - Creates order
- `side(): OrderSide` - Returns BUY or SELL side
- `assetPair(): AssetPair` - Returns asset pair
- `bounds(): OrderBounds` - Returns admissible fill bounds for base asset
- `effectiveRate(): ExchangeRate` - Returns effective exchange rate
- `feePolicy(): ?FeePolicy` - Returns fee policy if any
- `validatePartialFill(Money $baseAmount): void` - Validates amount can partially fill order
- `calculateQuoteAmount(Money $baseAmount): Money` - Calculates quote proceeds for base amount
- `calculateEffectiveQuoteAmount(Money $baseAmount): Money` - Calculates quote adjusted by fees
- `calculateGrossBaseSpend(Money $baseAmount, ?FeeBreakdown $feeBreakdown = null): Money` - Calculates total base required including fees

**Description**: Immutable domain entity representing a tradeable order with validation and calculation methods.

---

#### `SomeWork\P2PPathFinder\Domain\Order\OrderSide`

**Purpose**: Enum representing order side (BUY or SELL)

**Public Cases**:
- `OrderSide::BUY` - Buy side order
- `OrderSide::SELL` - Sell side order

**Description**: Backed enum with string values.

---

#### `SomeWork\P2PPathFinder\Domain\Order\FeeBreakdown`

**Purpose**: Container for fee components

**Public Methods**:
- `none(): self` - Creates zero-fee breakdown
- `base(Money $baseFee): self` - Creates breakdown with base fee
- `quote(Money $quoteFee): self` - Creates breakdown with quote fee
- `both(Money $baseFee, Money $quoteFee): self` - Creates breakdown with both fees
- `baseFee(): ?Money` - Returns base fee if any
- `quoteFee(): ?Money` - Returns quote fee if any
- `isZero(): bool` - Returns whether all fees are zero

**Description**: Value object containing optional base and quote fee amounts.

---

#### `SomeWork\P2PPathFinder\Domain\ValueObject\Money`

**Purpose**: Immutable monetary amount with arbitrary precision arithmetic

**Public Methods**:
- `fromString(string $currency, string $amount, int $scale = 2): self` - Creates from string components
- `zero(string $currency, int $scale = 2): self` - Creates zero-value amount
- `withScale(int $scale): self` - Returns copy with different scale
- `currency(): string` - Returns ISO currency code
- `amount(): string` - Returns normalized numeric-string representation
- `scale(): int` - Returns scale (fractional digits)
- `decimal(): BigDecimal` - Returns BigDecimal representation
- `add(Money $other, ?int $scale = null): self` - Adds another money value
- `subtract(Money $other, ?int $scale = null): self` - Subtracts another money value
- `multiply(string $multiplier, ?int $scale = null): self` - Multiplies by scalar
- `divide(string $divisor, ?int $scale = null): self` - Divides by scalar
- `compare(Money $other, ?int $scale = null): int` - Compares two values (-1, 0, 1)
- `equals(Money $other): bool` - Tests equality
- `greaterThan(Money $other): bool` - Tests if greater than
- `lessThan(Money $other): bool` - Tests if less than
- `isZero(): bool` - Tests if amount equals zero

**Description**: Core value object backed by `Brick\Math\BigDecimal`. All arithmetic uses half-up rounding.

**Usage**: Used throughout the package for all monetary amounts.

---

#### `SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate`

**Purpose**: Exchange rate between two assets

**Public Methods**:
- `fromString(string $baseCurrency, string $quoteCurrency, string $rate, int $scale = 8): self` - Creates exchange rate
- `convert(Money $money, ?int $scale = null): self` - Converts base currency amount to quote currency
- `invert(): self` - Returns inverted rate (quote becomes base)
- `baseCurrency(): string` - Returns base currency
- `quoteCurrency(): string` - Returns quote currency
- `rate(): string` - Returns normalized rate as numeric-string
- `scale(): int` - Returns scale used for precision
- `decimal(): BigDecimal` - Returns BigDecimal representation

**Description**: Immutable value object representing currency conversion rates with arbitrary precision.

---

#### `SomeWork\P2PPathFinder\Domain\ValueObject\AssetPair`

**Purpose**: Pair of asset symbols

**Public Methods**:
- `fromString(string $base, string $quote): self` - Creates asset pair
- `base(): string` - Returns base asset symbol
- `quote(): string` - Returns quote asset symbol

**Description**: Value object representing a currency pair.

---

#### `SomeWork\P2PPathFinder\Domain\ValueObject\OrderBounds`

**Purpose**: Minimum and maximum bounds for order fills

**Public Methods**:
- `from(Money $min, Money $max): self` - Creates bounds from min/max
- `min(): Money` - Returns minimum bound
- `max(): Money` - Returns maximum bound
- `contains(Money $amount): bool` - Tests if amount is within bounds
- `clamp(Money $amount): Money` - Clamps amount to bounds

**Description**: Value object enforcing order fill boundaries.

---

#### `SomeWork\P2PPathFinder\Domain\ValueObject\ToleranceWindow`

**Purpose**: Tolerance bounds for path search

**Public Methods**:
- `fromStrings(string $minimum, string $maximum): self` - Creates window from numeric-strings
- `minimum(): string` - Returns minimum tolerance as numeric-string
- `maximum(): string` - Returns maximum tolerance as numeric-string
- `scale(): int` - Returns canonical scale (18)
- `heuristicTolerance(): string` - Returns tolerance used by search heuristic
- `heuristicSource(): string` - Returns source of heuristic tolerance

**Description**: Value object representing acceptable deviation from desired spend amount.

---

#### `SomeWork\P2PPathFinder\Domain\ValueObject\DecimalTolerance`

**Purpose**: Normalized decimal tolerance value

**Public Methods**:
- `fromNumericString(string $ratio, int $scale): self` - Creates from numeric string
- `ratio(): string` - Returns tolerance ratio as numeric-string
- `percentage(int $scale = 2): string` - Returns tolerance as percentage string

**Description**: Represents normalized tolerance ratios with percentage conversion.

---

### Graph Building

#### `SomeWork\P2PPathFinder\Application\Graph\GraphBuilder`

**Purpose**: Converts orders into weighted directed graph

**Public Methods**:
- `__construct()` - Creates builder instance
- `build(iterable $orders): Graph` - Builds graph from orders

**Description**: Public service for constructing graph representation from order book. Mentioned in README as part of public API.

**Usage**: Pass to `PathFinderService` constructor.

---

#### `SomeWork\P2PPathFinder\Application\Graph\Graph`

**Purpose**: Immutable graph representation for path finding

**Public Methods**:
- `__construct(GraphNodeCollection $nodes)` - Creates graph
- `nodes(): GraphNodeCollection` - Returns all nodes
- `node(string $currency): ?GraphNode` - Returns specific node
- `hasNode(string $currency): bool` - Tests if node exists

**Description**: Read-only graph structure used internally by path finder. Publicly accessible as part of graph building API.

---

### Exceptions

All exceptions under `SomeWork\P2PPathFinder\Exception` namespace implement `ExceptionInterface`.

#### `SomeWork\P2PPathFinder\Exception\ExceptionInterface`

**Purpose**: Marker interface for all package exceptions

**Description**: Implement to catch all library-originated failures in one clause.

---

#### `SomeWork\P2PPathFinder\Exception\InvalidInput`

**Purpose**: Validation failure for configuration or input data

**Description**: Thrown when configuration, path legs, or fee breakdowns fail validation.

---

#### `SomeWork\P2PPathFinder\Exception\PrecisionViolation`

**Purpose**: Arithmetic precision limit exceeded

**Description**: Signals arithmetic inputs that cannot be represented within configured decimal precision.

---

#### `SomeWork\P2PPathFinder\Exception\GuardLimitExceeded`

**Purpose**: Search guard limit breach

**Description**: Thrown when `PathSearchConfig::withGuardLimitException()` is enabled and configured guardrails (visited states, expansions, or time budget) are reached.

**Usage**: Documented in README.md with opt-in escalation examples.

---

#### `SomeWork\P2PPathFinder\Exception\InfeasiblePath`

**Purpose**: No viable path found

**Description**: Indicates that no route satisfies the requested constraints.

---

## Internal API (May change without notice)

The following classes are marked `@internal` and are implementation details. They may be refactored, renamed, or removed in minor releases without notice.

### Search Engine

#### `SomeWork\P2PPathFinder\Application\PathFinder\PathFinder` (@internal)

**Purpose**: Internal search algorithm implementation

**Location**: `src/Application/PathFinder/PathFinder.php` (line 50)

**Rationale**: Core search algorithm. Hidden behind `PathFinderService` facade. Not intended for direct consumer use.

---

### Support Services

#### `SomeWork\P2PPathFinder\Application\Service\OrderSpendAnalyzer` (@internal)

**Purpose**: Encapsulates filtering orders and determining spend bounds

**Location**: `src/Application/Service/OrderSpendAnalyzer.php` (line 22)

**Rationale**: Internal helper used by `PathFinderService`. Implementation details subject to change.

---

#### `SomeWork\P2PPathFinder\Application\Service\LegMaterializer` (@internal)

**Purpose**: Resolves concrete path legs from abstract graph edges

**Location**: `src/Application/Service/LegMaterializer.php` (line 27)

**Rationale**: Internal helper for materializing search results. Complex iterative logic subject to optimization.

---

#### `SomeWork\P2PPathFinder\Application\Service\ToleranceEvaluator` (@internal)

**Purpose**: Validates materialized paths against configured tolerance bounds

**Location**: `src/Application/Service/ToleranceEvaluator.php` (line 24)

**Rationale**: Internal helper for tolerance validation. Algorithm may evolve.

---

#### `SomeWork\P2PPathFinder\Application\Service\PathFinderService::withRunnerFactory()` (@internal)

**Purpose**: Test-only factory hook for dependency injection

**Location**: `src/Application/Service/PathFinderService.php` (line 71)

**Usage**: Exclusively used in test code (`PathFinderServiceGuardsTest.php`) to inject mock PathFinder implementations for testing guard behavior, exception handling, and edge cases.

**Rationale**: 
- **Test-only utility**: Zero production usage, only appears in test code (9 test usages)
- **Purpose**: Enables dependency injection of controlled PathFinder implementations to test:
  - Guard limit enforcement (expansions, visited states, time budget)
  - Exception handling when limits are exceeded
  - Edge cases like empty candidates, mismatched currencies
- **Not for consumers**: Production code should use the standard `PathFinderService` constructor
- **May change**: As an internal test utility, this method may be modified or removed without notice

**Warning**: This method is explicitly NOT part of the public API. Consumers should never use it in production code.

---

### Internal Search Components

All classes under `src/Application/PathFinder/Search/` namespace are internal implementation details:

- `SearchBootstrap` - Initializes search structures
- `SearchState` - Represents search state during traversal
- `SearchStatePriority` - Priority for queue ordering
- `SearchStatePriorityQueue` - Priority queue implementation
- `SearchStateRecord` - Record of visited state
- `SearchStateRecordCollection` - Collection of state records
- `SearchStateRegistry` - Registry for visited states
- `SearchStateSignature` - Signature for state deduplication
- `SearchStateSignatureFormatter` - Formats signatures for comparison
- `SearchQueueEntry` - Entry in search queue
- `InsertionOrderCounter` - Tracks insertion order
- `SegmentPruner` - Prunes infeasible segments

**Rationale**: These are low-level implementation details of the search algorithm that may be optimized or restructured.

---

### Internal Result Components

The following result-related components are internal:

- `CandidateResultHeap` - Heap for candidate results
- `CandidateHeapEntry` - Entry in candidate heap
- `CandidatePriority` - Priority for candidate ordering

**Rationale**: Internal data structures used during search execution.

---

### Internal Value Objects

#### `SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\CandidatePath` (@internal)

**Purpose**: Represents candidate paths during search algorithm execution

**Location**: `src/Application/PathFinder/ValueObject/CandidatePath.php` (line 19)

**Exposure**: Used in internal callback `callable(CandidatePath):bool` within PathFinderService

**Rationale**:
- **Not exposed to consumers**: The callback is created inside `PathFinderService::findBestPaths()` and consumers never see CandidatePath
- **Internal implementation detail**: Consumers call `findBestPaths(PathSearchRequest)` and receive `SearchOutcome<PathResult>`
- **Test-only visibility**: Only appears in internal PathFinder tests
- **No consumer use case**: PathResult provides all necessary information to consumers

**Warning**: This class is an internal implementation detail. Consumers should never depend on it.

---

### Internal Support Classes

- `OrderFillEvaluator` - Evaluates order fills
- `SerializesMoney` (trait) - Money serialization helper

**Rationale**: Utility classes used internally by services.

---

## Requires Decision

The following classes are exposed in public API signatures but may need explicit public/internal designation.

### `SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\CandidatePath`

**Status**: ✅ **DECISION MADE: INTERNAL**

**Analysis**: 
- Used in internal callback `callable(CandidatePath):bool` within PathFinderService
- **NOT exposed to consumers**: The callback is created inside `PathFinderService::findBestPaths()` and consumers never interact with CandidatePath directly
- Consumers call `findBestPaths(PathSearchRequest)` and receive `SearchOutcome<PathResult>`
- Only used in internal tests

**Decision**: Mark as **INTERNAL**. The callback is an implementation detail of PathFinderService. Consumers interact with PathResult, not CandidatePath.

**Action Taken**: Updated PHPDoc to clarify it's internal and not exposed to consumers.

---


### Graph Component Classes

The following graph-related classes are used publicly through GraphBuilder but their individual usage is unclear:

- `Graph` - Already marked as PUBLIC above (returned by GraphBuilder)
- `GraphNode` - Used when traversing Graph
- `GraphNodeCollection` - Collection of nodes
- `GraphEdge` - Represents graph edge
- `GraphEdgeCollection` - Collection of edges
- `EdgeCapacity` - Capacity bounds for edge
- `EdgeSegment` - Segment of edge capacity
- `EdgeSegmentCollection` - Collection of segments
- `SegmentCapacityTotals` - Totals of segment capacities

**Decision Needed**: Are these classes part of the public Graph API?

**Recommendation**: Mark graph traversal classes as **PUBLIC** since Graph is public and consumers may want to inspect the constructed graph. Mark as read-only data structures with clear documentation that they're immutable view objects.

**Specifically**:
- `Graph`, `GraphNode`, `GraphNodeCollection` - **PUBLIC** (graph inspection)
- `GraphEdge`, `GraphEdgeCollection` - **PUBLIC** (graph inspection)  
- `EdgeCapacity`, `EdgeSegment`, `EdgeSegmentCollection`, `SegmentCapacityTotals` - **PUBLIC** (capacity inspection)

These provide transparency into how the graph is constructed without exposing internal search mechanics.

---

### Filter Implementations

The package includes several concrete filter implementations:

- `CurrencyPairFilter`
- `MaximumAmountFilter`
- `MinimumAmountFilter`
- `ToleranceWindowFilter`

**Current Status**: Not documented in README as public API

**Decision Needed**: Should these be part of the stable API?

**Recommendation**: Mark as **PUBLIC**. They're useful implementations of OrderFilterInterface that consumers may want to use directly. Add to public API documentation with usage examples.

---

### `SomeWork\P2PPathFinder\Application\PathFinder\Result\PathResultSetEntry`

**Issue**: Used in PathResultSet::fromEntries() which is marked @internal

**Current Usage**: Internal entry type for result set construction

**Recommendation**: Mark as **INTERNAL**. It's an implementation detail of PathResultSet construction.

---

### `SomeWork\P2PPathFinder\Application\Service\MaterializedResult`

**Issue**: Used internally during materialization

**Current Usage**: Internal helper class

**Recommendation**: Mark as **INTERNAL**. Implementation detail.

---

## Summary

### Public API Count

- **Services**: 2 classes
- **Configuration**: 3 classes
- **Request/Response**: 9 classes + 1 generic collection (added SpendConstraints)
- **Interfaces**: 3 interfaces (extensibility)
- **Ordering**: 4 classes (PathOrderKey, PathCost, RouteSignature, CostHopsSignatureOrderingStrategy)
- **Domain Objects**: 10+ value objects and entities
- **Exceptions**: 5 classes + 1 interface
- **Graph Building**: 9+ classes

**Total: ~45-50 public classes/interfaces**

### Internal API Count

- **Search Engine**: 1 core class (PathFinder)
- **Support Services**: 4 classes (PathFinderService::withRunnerFactory)
- **Internal Search Components**: 13+ classes
- **Internal Result Components**: 3+ classes
- **Internal Value Objects**: 1 class (CandidatePath)
- **Internal Support**: 2+ classes

**Total: ~24+ internal classes**

### Requires Decision Count

- **Medium Priority**: 9 items (Graph components)
- **Low Priority**: 4 items (Filter implementations)

**Total: ~12 items pending categorization** (5 resolved: SpendConstraints → PUBLIC, CandidatePath → INTERNAL, PathOrderKey → PUBLIC, PathCost → PUBLIC, RouteSignature → PUBLIC)

---

## Version Compatibility Guarantee

Starting from version **1.0.0**:

- **Public API**: Breaking changes only in major versions (2.0.0, 3.0.0, etc.)
- **Internal API**: May change in any minor version (1.1.0, 1.2.0, etc.) without notice
- **Deprecation Policy**: Public APIs will be deprecated for at least one minor version before removal in the next major version

---

## Notes

- This document will be updated as API boundaries are refined during 1.0 stabilization
- All public APIs have comprehensive docblock documentation
- README.md should be updated to link to this document
- Consider adding `@api` annotations to public classes to make the distinction explicit in the code

