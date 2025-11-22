# Task: Future Enhancements and Experimental Features Investigation

## Context

The library has a solid 1.0 foundation. This task explores potential future enhancements that could be added in 2.0 or later versions. These are speculative and should only be pursued if there's clear user demand or strategic value.

This is a **P4 Experimental** task - meant for exploration and research, not immediate implementation.

## Problem

**Potential areas for enhancement:**

1. **Alternative search strategies**:
   - Current: Dijkstra-like with tolerance
   - Alternatives: A*, Bellman-Ford, Yen's K-shortest paths
   - Question: Would alternative algorithms provide value?

2. **Parallel search**:
   - Current: Single-threaded
   - Future: Multi-threaded search for large graphs?
   - Question: Is PHP parallelization practical/beneficial?

3. **Graph caching/indexing**:
   - Current: Graph built on every search
   - Future: Cache graph between searches, incrementally update
   - Question: Would caching help high-frequency scenarios?

4. **Custom heuristics**:
   - Current: Fixed tolerance amplifier and cost calculation
   - Future: Pluggable heuristics for search optimization
   - Question: Do users need custom heuristics?

5. **Streaming/lazy evaluation**:
   - Current: All paths materialized
   - Future: Lazy path generation (yield paths as found)
   - Question: Would streaming help for large result sets?

6. **Distributed search**:
   - Current: In-process
   - Future: Distribute search across services/workers
   - Question: Are graphs large enough to warrant distribution?

7. **Machine learning optimization**:
   - Current: Deterministic algorithm
   - Future: ML-based guard tuning, path prediction
   - Question: Is ML appropriate for deterministic routing?

8. **Graph persistence**:
   - Current: In-memory only
   - Future: Persist to Redis, database, etc.
   - Question: Do users need graph persistence?

9. **Real-time updates**:
   - Current: Static order book per search
   - Future: Handle order book updates during search
   - Question: Are updates frequent enough to matter?

10. **Advanced fee structures**:
    - Current: Simple FeePolicy interface
    - Future: Time-based fees, volume discounts, complex fee chains
    - Question: Are current fee policies sufficient?

## Proposed Changes

### 1. Research alternative algorithms

**Evaluate**:
- **A* search**: Uses heuristic to guide search
  - Benefit: Potentially faster if good heuristic available
  - Cost: Requires admissible heuristic (hard to guarantee)
- **Bellman-Ford**: Handles negative weights
  - Benefit: More flexible than Dijkstra
  - Cost: Slower (O(VE) vs O(E log V))
- **Yen's K-shortest paths**: Find K paths without overlap penalty
  - Benefit: Better K-best path finding
  - Cost: More complex implementation

**Research**:
- Read papers on P2P path finding
- Review other implementations
- Identify if any algorithm is clearly superior

**Document findings** in docs/research/algorithms.md

### 2. Investigate PHP parallelization

**Options**:
- **amphp/parallel** - fiber-based concurrency
- **php-fpm pool** - multiple processes
- **pthread extension** - true threading (requires ZTS PHP)
- **External workers** - Queue-based job distribution

**Prototype**:
- Implement parallel graph exploration
- Benchmark against single-threaded
- Measure overhead vs benefit

**Document findings** in docs/research/parallelization.md

**Likely conclusion**: PHP parallelization overhead probably exceeds benefit for typical graph sizes

### 3. Evaluate graph caching

**Scenarios where caching helps**:
- High-frequency searches (thousands per second)
- Stable order books (updates rare)
- Large graphs (thousands of orders)

**Caching strategies**:
- **Full graph cache**: Cache entire Graph object
- **Incremental updates**: Add/remove edges on order updates
- **Materialized views**: Pre-compute common subgraphs

**Prototype**:
- Implement simple graph cache
- Benchmark with cache vs without
- Measure memory overhead

**Document findings** in docs/research/graph-caching.md

### 4. Design pluggable heuristic system

**Current heuristics**:
- Tolerance amplifier (fixed formula)
- Cost calculation (fixed to 1/product)
- Priority queue ordering (fixed: cost → hops → signature → discovery)

**Pluggable design**:
```php
interface SearchHeuristic
{
    public function calculatePriority(SearchState $state): BigDecimal;
    public function shouldPrune(SearchState $state): bool;
}
```

**Question**: Is this over-engineering? Do users need this flexibility?

**Research**: Survey potential users or review issues for requests

### 5. Prototype lazy path generation

**Current**:
```php
$outcome = $service->findBestPaths($request);
foreach ($outcome->paths() as $path) {
    // All paths already found
}
```

**Lazy**:
```php
$generator = $service->findPathsLazy($request);
foreach ($generator as $path) {
    // Paths yielded as found
    if (someCondition($path)) {
        break; // Can stop early
    }
}
```

**Benefits**:
- Early termination possible
- Lower memory for large result sets

**Costs**:
- More complex API
- Search state must be maintained during iteration

**Prototype and evaluate**

### 6. Research distributed search

**Scenarios**:
- Massive graphs (millions of orders)
- High availability requirements
- Geographic distribution of data

**Approaches**:
- **Partitioned graphs**: Distribute graph across workers, merge results
- **Work stealing**: Workers steal search states from queue
- **Map-reduce**: Map subgraphs to workers, reduce results

**Likely conclusion**: Overkill for typical P2P routing, but document for reference

### 7. Explore ML applications

**Potential applications**:
- **Guard tuning**: ML model predicts optimal guards for given order book
- **Path prediction**: ML predicts likely optimal path without full search
- **Feature extraction**: ML learns graph features that predict performance

**Concerns**:
- Contradicts determinism guarantee
- Adds complex dependency (ML library)
- Training data required

**Likely conclusion**: Not appropriate for core library, but possible add-on package

### 8. Design graph persistence

**Use cases**:
- Cache graph across requests
- Share graph across processes
- Persist graph for audit/debugging

**Storage options**:
- **Redis**: Fast, in-memory, networked
- **Database**: Durable, queryable
- **Filesystem**: Simple, portable

**Serialization**:
- JSON (human-readable, inefficient)
- MessagePack (compact, fast)
- PHP serialize (simple, PHP-only)

**Design interface**:
```php
interface GraphStore
{
    public function save(string $key, Graph $graph): void;
    public function load(string $key): ?Graph;
    public function delete(string $key): void;
}
```

**Evaluate** if there's demand before implementing

### 9. Handle real-time order updates

**Scenarios**:
- Order book changes during search
- New orders arrive mid-search
- Orders filled/cancelled mid-search

**Approaches**:
- **Ignore**: Search uses snapshot (current approach)
- **Restart**: Abort and restart with new order book
- **Incremental**: Update graph and continue search
- **Optimistic**: Continue search, validate at end

**Design**:
```php
interface OrderBookObserver
{
    public function onOrderAdded(Order $order): void;
    public function onOrderRemoved(string $orderId): void;
}
```

**Likely conclusion**: Snapshot approach is sufficient; real-time updates add significant complexity

### 10. Design advanced fee structures

**Current**: Simple FeePolicy interface with base/quote fees

**Advanced scenarios**:
- **Time-based fees**: Different fees at different times
- **Volume discounts**: Fees decrease with volume
- **Maker/taker fees**: Different fees for market makers vs takers
- **Fee chains**: Multiple fee policies applied in sequence
- **Dynamic fees**: Fees based on external factors (gas prices, etc.)

**Design**:
```php
interface AdvancedFeePolicy extends FeePolicy
{
    public function calculateWithContext(
        OrderSide $side,
        Money $base,
        Money $quote,
        FeeContext $context
    ): FeeBreakdown;
}

class FeeContext
{
    public function __construct(
        public readonly DateTimeImmutable $timestamp,
        public readonly ?Money $dailyVolume,
        public readonly array $metadata,
    ) {}
}
```

**Evaluate** if there's demand for this complexity

## Dependencies

- None - these are future investigations, not for 1.0

## Effort Estimate

**L-XL** (1-3 days per investigation, highly variable)

Each investigation above could be:
- S (≤2h): Literature review, feasibility assessment
- M (0.5-1d): Design and documentation
- L (1-3d): Prototype and evaluation
- XL (>3d): Full implementation

**For now**: Focus on literature review and design (S-M range per item)

## Risks / Considerations

- **Premature optimization**: Don't build features without clear demand
- **Scope creep**: These features could massively expand scope
- **Complexity**: Each feature adds maintenance burden
- **Breaking changes**: Some features might require API changes
- **Competing priorities**: Focus on 1.0 stability before 2.0 features

**Recommendation**: 
- Document research findings
- Wait for user feedback and feature requests
- Prioritize based on actual demand
- Consider separate packages for experimental features

## Definition of Done

- [ ] All 10 enhancement areas researched (literature review)
- [ ] Findings documented in docs/research/
- [ ] Feasibility assessment for each
- [ ] Design sketches for promising features
- [ ] Estimated effort for full implementation
- [ ] Priority recommendations based on value vs effort
- [ ] User research plan (survey, interviews) if needed
- [ ] Decision: which (if any) to pursue for 2.0
- [ ] No actual implementation (unless green-lit for specific feature)

**Priority:** P4 – Experimental / Optional

