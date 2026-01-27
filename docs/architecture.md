# Architecture

This document describes the architectural design of the P2P Path Finder library, including its layered structure, key components, and extension points.

## Table of Contents

- [Overview](#overview)
- [Architectural Layers](#architectural-layers)
- [Key Components](#key-components)
- [Search Flow](#search-flow)
- [Design Patterns](#design-patterns)
- [Extension Points](#extension-points)
- [Performance Considerations](#performance-considerations)

---

## Overview

The P2P Path Finder library is built using **Domain-Driven Design (DDD)** principles with a clean, layered architecture optimized for finding optimal multi-hop conversion paths.

### Architectural Goals

1. **Separation of Concerns** - Clear boundaries between layers
2. **Immutability** - Value objects and DTOs use `readonly` properties
3. **Type Safety** - Leverages PHP 8.2+ features (readonly, enums, union types)
4. **Extensibility** - Strategy pattern for filters, fees, and ordering
5. **Performance** - Optimized graph search with configurable guard rails

---

## Architectural Layers

The library follows a three-layer architecture with strict dependency rules.

### Layer Overview

| Layer           | Responsibility                               | Example Components                                                  |
|-----------------|----------------------------------------------|---------------------------------------------------------------------|
| **Domain**      | Business entities, value objects, invariants | `Money`, `Order`, `ExchangeRate`, `FeePolicy`                       |
| **Application** | Use cases, algorithms, orchestration         | `ExecutionPlanSearchEngine`, `GraphBuilder`, `SearchGuards`         |
| **Public API**  | Entry points, request/response DTOs          | `ExecutionPlanService`, `SearchOutcome`, `ExecutionPlan`, `ExecutionStep` |

### Dependency Rule

**Dependencies point inward**: Public API → Application → Domain

- Domain layer has no dependencies
- Application layer depends only on Domain
- Public API depends on both but neither depends on it

### Component Interaction

```
User Code
    ↓
ExecutionPlanService (Public API)
    ↓
┌────────────────────────────────────────────┐
│ 1. GraphBuilder                            │ → Builds graph from OrderBook
│ 2. ExecutionPlanSearchEngine               │ → Finds optimal execution plan  
│ 3. ExecutionPlanMaterializer               │ → Converts results to DTOs
└────────────────────────────────────────────┘
    ↓
SearchOutcome<ExecutionPlan> (plan + guard report)
```

---

## Key Components

### Domain Layer

**Value Objects** (Immutable):
- `Money` - Monetary amount with currency and scale
- `ExchangeRate` - Conversion rate between currencies
- `OrderBounds` - Min/max amount constraints
- `ToleranceWindow` - Acceptable deviation range (0 to < 1)

**Entities**:
- `Order` - Buy/sell order with bounds, rate, optional fees
- `AssetPair` - Base/quote currency pair

**Domain Services**:
- `FeePolicy` (interface) - Strategy for fee calculation

**Invariants enforced at construction**:
- Money: amount ≥ 0, currency 3-12 letters, scale 0-30
- ExchangeRate: rate > 0, distinct currencies
- OrderBounds: min ≤ max, same currency
- ToleranceWindow: 0 ≤ min ≤ max < 1

See [Domain Invariants](domain-invariants.md) for complete specifications.

### Application Layer

**Service Layer**:
- `ExecutionPlanService` - Public API facade for execution plan search
- `PathSearchRequest` - Request DTO with order book + config
- `ExecutionPlanMaterializer` - Converts engine results to ExecutionPlan
- `LegMaterializer` - Converts raw fills to execution steps
- `ToleranceEvaluator` - Validates path tolerance compliance

**Graph Construction**:
- `GraphBuilder` - Converts orders to weighted directed graph
- `Graph` - Container for nodes and edges
- `GraphNode` - Represents currency with outgoing edges
- `GraphEdge` - Represents order with capacity and segments

**Search Algorithm** (Internal):
- `ExecutionPlanSearchEngine` - Successive shortest augmenting paths algorithm
- `PortfolioState` - Multi-currency balance tracking for split/merge
- `SearchGuards` - Enforces resource limits (expansions, states, time)

**Key Algorithm Features**:
- Dijkstra-based augmenting path search
- Guard rails prevent runaway searches
- **PortfolioState** enables multi-currency tracking for splits/merges

### Public API Layer

**Entry Point**:
```php
use SomeWork\P2PPathFinder\Application\PathSearch\Service\ExecutionPlanService;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;

$service = new ExecutionPlanService(new GraphBuilder());
$outcome = $service->findBestPlans($request);
```

**Configuration**:
```php
$config = PathSearchConfig::builder()
    ->withSpendAmount($money)
    ->withToleranceBounds('0.0', '0.05')
    ->withHopLimits(1, 4)
    ->withSearchGuards(50000, 100000)
    ->build();
```

**Results**:
```php
$plan = $outcome->bestPath();  // Single optimal ExecutionPlan or null

if (null !== $plan) {
    $plan->totalSpent();           // Sum of spends from source currency
    $plan->totalReceived();        // Sum of receives into target currency
    $plan->residualTolerance();    // Remaining tolerance headroom
    $plan->feeBreakdown();         // Aggregated MoneyMap across steps
    $plan->isLinear();             // Check if plan is a simple linear path
    $plan->stepCount();            // Number of execution steps

    foreach ($plan->steps() as $step) {
        $step->from();             // Asset symbol
        $step->to();               // Asset symbol
        $step->order();            // Original Order driving this step
        $step->fees();             // MoneyMap for step-level fees
        $step->sequenceNumber();   // Execution order (1-based)
    }
}

$outcome->guardLimits()->anyLimitReached();  // Check guard status
```

**Step-centric model**: Each `ExecutionPlan` is derived from an ordered `ExecutionStepCollection`. 
Totals (`totalSpent()`, `totalReceived()`), aggregated fees (`feeBreakdown()`), and remaining tolerance are 
computed from step data. Steps include `sequenceNumber()` for execution ordering and support split/merge topologies.

---

## Search Flow

### High-Level Flow

1. **Graph Construction** - `GraphBuilder` converts `OrderBook` to graph
2. **Search Initialization** - Create `PortfolioState` with source currency balance
3. **Augmenting Path Loop** - Find successive shortest paths until balance exhausted
4. **Result Materialization** - `ExecutionPlanMaterializer` converts to `ExecutionPlan`
5. **Outcome Assembly** - Combine results with guard report

### ExecutionPlanSearchEngine Algorithm

The `ExecutionPlanSearchEngine` uses a **successive shortest augmenting paths** algorithm:

```
1. Initialize PortfolioState with source currency balance
2. While balance remains in non-target currencies AND guards allow:
   a. Find cheapest augmenting path (Dijkstra from all currencies with balance)
   b. Calculate bottleneck (maximum flow through path)
   c. Execute flow along path:
      - Deduct spent amounts from source currencies
      - Add received amounts to target currencies
      - Mark currencies as visited when depleted
      - Record execution steps
3. Return ExecutionPlanSearchOutcome with plan + guard report
4. Service layer validates tolerance and creates SearchOutcome
```

**PortfolioState Invariants**:
- Non-negative balances: All balances >= 0
- Visited marking: Currency marked visited when balance depleted through spending
- No backtracking: Cannot receive into visited currency (prevents cycles)
- Order uniqueness: Each order used only once per portfolio state
- Immutability: All operations return new instances

### Split/Merge Flow Diagram

```
Split at Source (A → B and A → C):
┌─────┐     ┌─────┐
│  A  │────▶│  B  │
│(USD)│     │(EUR)│
└──┬──┘     └─────┘
   │
   │        ┌─────┐
   └───────▶│  C  │
            │(GBP)│
            └─────┘

Merge at Target (B → D and C → D):
┌─────┐     ┌─────┐
│  B  │────▶│     │
│(EUR)│     │  D  │
└─────┘     │(BTC)│
            │     │
┌─────┐     │     │
│  C  │────▶│     │
│(GBP)│     └─────┘
└─────┘

Diamond Pattern (Split + Merge):
           ┌─────┐
      ┌───▶│  B  │───┐
      │    │(EUR)│   │
┌─────┤    └─────┘   ├──▶┌─────┐
│  A  │              │   │  D  │
│(USD)│    ┌─────┐   │   │(BTC)│
└─────┤    │  C  │   │   └─────┘
      └───▶│(GBP)│───┘
           └─────┘
```

### Engine-to-Service Layer Communication

The search algorithm operates at two levels of abstraction to maintain clean separation of concerns:

**Engine Layer** (`@internal`):
- Returns raw fill data (order, spend amount, sequence number)
- Focus: Pure algorithmic search without domain object materialization

**Service Layer** (Public API):
- Consumes raw fill data from engine
- Materializes fills into full `ExecutionPlan` objects with `ExecutionStepCollection`
- Applies domain rules (fee calculation, tolerance validation, order reconciliation)
- Returns `SearchOutcome` - public API DTO with complete domain objects

**Benefits of this separation**:
- Engine remains focused on algorithmic efficiency (no domain object overhead)
- Service layer handles complex domain logic (fees, materialization, validation)
- Clear contract between layers enables independent testing and evolution

### Key Optimizations

- **Dominance filtering** - Skip worse paths to same state
- **Segment pruning** - Optimize fee-based edges
- **Zero Money cache** - Reuse instances in graph construction
- **Memoized exchange rates** - Share across orders
- **Stack-allocated candidates** - Defer heap allocation until tolerance passes

---

## Design Patterns

### 1. Strategy Pattern

**Used for**: Customizable algorithms

**Interfaces**:
- `FeePolicy` - Fee calculation strategies
- `OrderFilterInterface` - Order filtering strategies
- `PathOrderStrategy` - Result ordering strategies

**Example**:
```php
class TieredFeePolicy implements FeePolicy
{
    public function calculate(OrderSide $side, Money $base, Money $quote): FeeBreakdown
    {
        $rate = $quote->toDecimal()->compareTo('1000') >= 0 ? '0.0025' : '0.005';
        return FeeBreakdown::forQuote($quote->multiply($rate, $quote->scale()));
    }
}
```

### 2. Builder Pattern

**Used for**: Complex configuration construction

```php
$config = PathSearchConfig::builder()
    ->withSpendAmount($money)
    ->withToleranceBounds('0.0', '0.05')
    ->withHopLimits(1, 4)
    ->build();  // Validates and constructs
```

### 3. Value Object Pattern

**Used for**: Domain concepts with invariants

```php
final readonly class Money
{
    private function __construct(
        private BigDecimal $decimal,
        private string $currency,
        private int $scale
    ) {
        // Invariants enforced
    }
}
```

### 4. Facade Pattern

**Used for**: Simplify complex subsystem

```php
class ExecutionPlanService  // Facade
{
    public function findBestPlans(PathSearchRequest $request): SearchOutcome
    {
        // Coordinates GraphBuilder, ExecutionPlanSearchEngine, 
        // ExecutionPlanMaterializer, ToleranceEvaluator
    }
}
```

### 5. Repository Pattern

**Used for**: Data access abstraction

```php
class OrderBook  // In-memory repository
{
    public function filter(OrderFilterInterface ...$filters): Generator;
}
```

### 6. Priority Queue Pattern

**Used for**: Efficient best-first search in the execution plan engine

---

## Extension Points

The library provides three main extension points for customization.

### 1. Custom Order Filters

**Interface**: `OrderFilterInterface`

**Use case**: Pre-filter orders before search

```php
class MinimumLiquidityFilter implements OrderFilterInterface
{
    public function __construct(private Money $minimumAmount) {}
    
    public function accepts(Order $order): bool
    {
        return $order->bounds()->maximum()->compareTo($this->minimumAmount) >= 0;
    }
}

// Apply filter
$filtered = $orderBook->filter(new MinimumLiquidityFilter($minAmount));
```

**Built-in filters**:
- `MinimumAmountFilter` - Filter by minimum capacity
- `MaximumAmountFilter` - Filter by maximum capacity
- `ToleranceWindowFilter` - Filter by rate tolerance

**When to use**: Reduce search space by 30-70% for better performance.

See [examples/custom-order-filter.php](../examples/custom-order-filter.php)

### 2. Custom Path Ordering

**Interface**: `PathOrderStrategy`

**Use case**: Custom path ranking logic

```php
class MinimizeHopsStrategy implements PathOrderStrategy
{
    public function compare(PathOrderKey $left, PathOrderKey $right): int
    {
        // Prioritize fewer hops
        $hopCmp = $left->hops() <=> $right->hops();
        return $hopCmp !== 0 ? $hopCmp : $left->cost()->compare($right->cost(), 18);
    }
}

// Use strategy
$service = new ExecutionPlanService($graphBuilder, new MinimizeHopsStrategy());
```

**Use cases**:
- Minimize hops over cost
- Weighted scoring (hops + cost + fees)
- Route preferences (favor specific currencies)

See [examples/custom-ordering-strategy.php](../examples/custom-ordering-strategy.php)

### 3. Custom Fee Policies

**Interface**: `FeePolicy`

**Use case**: Model complex fee structures

```php
class TieredFeePolicy implements FeePolicy
{
    public function calculate(OrderSide $side, Money $base, Money $quote): FeeBreakdown
    {
        $rate = $this->getTierRate($quote);
        $fee = $quote->multiply($rate, $quote->scale());
        return FeeBreakdown::forQuote($fee);
    }
    
    public function fingerprint(): string
    {
        return 'tiered:0.5%<1k:0.25%>=1k';
    }
}
```

**Use cases**:
- Percentage fees (0.5% of quote)
- Fixed fees ($1.00 per transaction)
- Tiered fees (volume-based rates)
- Maker/taker fees (different rates)
- Min/max fee constraints

See [examples/custom-fee-policy.php](../examples/custom-fee-policy.php)

---

## Performance Considerations

### Memory Scaling

| Order Book Size | Peak Memory | Typical States | Expansions    |
|-----------------|-------------|----------------|---------------|
| 100 orders      | 8-15 MB     | 500-1,000      | 1,500-3,000   |
| 1,000 orders    | 12-30 MB    | 2,000-5,000    | 8,000-15,000  |
| 10,000 orders   | 50-150 MB   | 10,000-20,000  | 40,000-80,000 |

**Key factors**:
- ~5-8 KB per order (domain objects)
- ~1 KB per search state (visited state tracking)
- Guard limits cap maximum memory

See [Memory Characteristics](memory-characteristics.md) for detailed analysis.

### Search Complexity

**Time Complexity**:
- Best case: O((V + E) log V) where V = currencies, E = orders
- Worst case: O(G × log G) where G = guard limits

**Space Complexity**:
- O(V + E) for graph
- O(S) for search states where S ≤ maxVisitedStates

### Optimization Strategies

1. **Pre-filter order book** - 30-70% memory reduction
2. **Use conservative guard limits** - Predictable memory usage
3. **Limit hop depth** - Exponential complexity reduction
4. **Set time budgets** - Bounded latency guarantee

**Example configuration**:
```php
// Latency-sensitive (< 100ms, 10-30 MB)
$config = PathSearchConfig::builder()
    ->withHopLimits(1, 4)
    ->withSearchGuards(10000, 25000)
    ->withSearchTimeBudget(50)
    ->build();
```

---

## Public vs Internal API

### Public API (Stable)

**Safe to depend on**:
- `Application\PathSearch\Service\*` - Entry point services
- `Application\PathSearch\Api\*` - API request/response DTOs
- `Application\PathSearch\Config\*` - Configuration builders
- `Application\PathSearch\Result\*` - Search results
- `Domain\Money\*` - Money and currency objects
- `Domain\Order\*` - Order and order book objects
- `Domain\Tolerance\*` - Tolerance and precision objects
- `Exception\**` - All exceptions

### Internal API (May Change)

**Avoid depending on**:
- `Application\PathSearch\*` (except public API namespaces) - Search algorithm internals
- Classes marked `@internal`

**Why**: Internal APIs may change in MINOR versions for performance or implementation improvements.

See [API Stability Guide](api-stability.md) for complete details.

---

## Summary

### Key Design Decisions

| Decision                    | Rationale                                     |
|-----------------------------|-----------------------------------------------|
| **Domain-Driven Design**    | Clear domain concepts, enforced invariants    |
| **Immutable Value Objects** | Thread-safe, no defensive copying needed      |
| **BigDecimal Arithmetic**   | Exact decimal math, no float errors           |
| **Strategy Pattern**        | Customization without modification            |
| **Priority Queue Search**   | Efficient best-path discovery (Dijkstra-like) |
| **Guard Rails**             | Prevent runaway searches in production        |
| **Builder Pattern**         | Fluent, validated configuration               |

### Architecture Strengths

✅ **Clean Separation** - Domain, Application, API layers with clear boundaries  
✅ **Immutability** - Value objects and DTOs are immutable by design  
✅ **Type Safety** - PHP 8.2+ features for compile-time guarantees  
✅ **Extensibility** - Strategy pattern for customization  
✅ **Performance** - Optimized search with configurable limits  
✅ **Production Ready** - Predictable memory, guard limits, deterministic

---

## Related Documentation

- [API Stability Guide](api-stability.md) - Public vs internal API
- [Domain Invariants](domain-invariants.md) - Validation rules
- [Memory Characteristics](memory-characteristics.md) - Performance and scaling
- [Exception Handling](exceptions.md) - Error handling patterns
- [Getting Started](getting-started.md) - Quick start tutorial

---

*For implementation details, see the source code in `src/` directory.*
