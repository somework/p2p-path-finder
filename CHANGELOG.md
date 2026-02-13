# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Top-K Execution Plan Discovery**: `ExecutionPlanService::findBestPlans()` now returns up to K distinct execution plans
  - Configure via `PathSearchConfig::withResultLimit(K)` (default: 1 for backward compatibility)
  - **Disjoint mode** (default, `withDisjointPlans(true)`): each plan uses completely disjoint order sets — no order appears in multiple plans
  - **Reusable mode** (`withDisjointPlans(false)`): plans may share orders with penalty-based diversification, useful for rate comparison scenarios
  - Plans are ranked by cost (best/cheapest first)
  - If fewer than K alternatives exist, returns as many as found
  - Guard metrics (expansions, visited states, elapsed time) are aggregated across all K iterations
  - See [UPGRADING.md](UPGRADING.md#top-k-execution-plan-discovery) for usage examples
  - New example: `examples/top-k-execution-plans.php`

- **Graph filtering**: `Graph::withoutOrders(array $excludedOrderIds)` for immutable graph filtering
  - Filters edges by order ID (via `spl_object_id`)
  - Returns new graph instance if changes occur, same instance if no changes
  - Propagates through `GraphNodeCollection`, `GraphNode`, `GraphEdgeCollection`

- **SearchGuardReport aggregation**: `SearchGuardReport::aggregate(array $reports)` combines metrics from multiple searches
  - Sums numerical metrics (expansions, visitedStates, elapsedMilliseconds)
  - Uses logical OR for boolean "reached" flags
  - Takes limits from first report

- **Top-K benchmarks**: New benchmark scenarios in `ExecutionPlanBench.php`
  - `benchFindTopKPlans`: Varying K values with different order book sizes
  - `benchGraphFiltering`: Graph filtering performance with varying exclusion set sizes

### Changed
- TBD

### Removed
- TBD

---

## [2.0.0] - TBD

**⚠️ BREAKING CHANGES**: This version removes deprecated APIs and introduces `ExecutionPlanService` as the sole public API for path finding. See [UPGRADING.md](UPGRADING.md#upgrading-from-1x-to-20) for complete migration guide.

### Added
- **ExecutionPlanService**: New recommended service for path finding that supports split/merge execution
  - `ExecutionPlanService::findBestPlans()` - Returns `ExecutionPlan` objects
  - Supports multi-order same direction (multiple orders for USD→BTC)
  - Supports split execution (input split across parallel routes)
  - Supports merge execution (routes converging at target)
  - See [Getting Started Guide](docs/getting-started.md#executionplanservice-recommended)

- **ExecutionPlan result type**: Complete execution plan that can express both linear and split/merge topologies
  - `steps()` - Returns `ExecutionStepCollection`
  - `isLinear()` - Check if plan is a simple linear path
  - `asLinearPath()` - Convert to legacy `Path` format (returns null if non-linear)
  - `sourceCurrency()` / `targetCurrency()` - Source and target currencies
  - `stepCount()` - Number of execution steps
  - See [API Contracts](docs/api-contracts.md#executionplan)

- **ExecutionStep**: Single step in an execution plan with sequence ordering
  - `sequenceNumber()` - Execution order (1-based)
  - `from()` / `to()` - Source and destination currencies
  - `spent()` / `received()` - Monetary amounts
  - `order()` / `fees()` - Order reference and fees
  - See [API Contracts](docs/api-contracts.md#executionstep)

- **ExecutionStepCollection**: Immutable ordered collection sorted by sequence number
  - `fromList()` - Create from array of steps
  - `all()` / `at()` / `first()` / `last()` - Access steps
  - Automatic sorting by sequence number

- **PortfolioState** (internal): Multi-currency balance tracking for split/merge execution
  - Tracks balances across multiple currencies simultaneously
  - Prevents backtracking (cannot return to fully spent currency)
  - Each order used only once per portfolio state
  - See [Architecture Guide](docs/architecture.md#executionplansearchengine-algorithm-recommended)

- **ExecutionPlanSearchEngine** (internal): Successive shortest augmenting paths algorithm
  - Finds optimal execution plans considering all available liquidity
  - Uses Dijkstra-based path finding with portfolio state tracking
  - Supports complex topologies (splits, merges, diamond patterns)

- **New examples**:
  - `examples/execution-plan-basic.php` - Basic ExecutionPlanService usage
  - `examples/execution-plan-split-merge.php` - Split/merge execution patterns
  - Updated `examples/advanced-search-strategies.php` with ExecutionPlanService

### Changed
- **Major namespace refactoring**: Reorganized entire codebase for better structure and maintainability
  - `Application/Graph/` → `Application/PathSearch/Model/Graph/`
  - `Application/PathFinder/` → `Application/PathSearch/`
  - `Domain/ValueObject/` → `Domain/Money/`, `Domain/Tolerance/`, `Domain/Order/`
  - `Application/Service/` → `Application/PathSearch/Service/`
  - Updated all imports and references across 78 source files and 173 test files
- **Cleaned up domain models**: Removed serialization interfaces for cleaner, more focused domain objects
  - Eliminated `JsonSerializable` implementations from core classes
  - Removed serialization-specific traits and logic
  - Updated examples to use direct object APIs
- **Documentation overhaul**: All documentation now uses `ExecutionPlanService` exclusively as the primary API
  - `getting-started.md`: Updated all examples to use `ExecutionPlanService`, removed legacy migration section
  - `architecture.md`: Updated component diagrams and flow descriptions, removed legacy `PathSearchService` flow
  - `api-contracts.md`: Removed deprecated `Path`, `PathHop`, `PathSearchService` sections
  - `api/index.md`: Removed `PathSearchService` API documentation
  - `memory-characteristics.md`: Removed legacy comparison section
  - `api-stability.md`: Updated entry point references to `ExecutionPlanService`
  - `exceptions.md`: Updated all example code to use `ExecutionPlanService`
  - `decimal-strategy.md`: Updated service and DTO references
  - `releases-and-support.md`: Updated BC break examples

### Removed

#### Public API Removals

- **`PathSearchService` class**: Removed deprecated service class
  - **Replacement**: Use `ExecutionPlanService::findBestPlans()` instead
  - **Migration**: See [UPGRADING.md](UPGRADING.md#step-1-update-service-instantiation)

- **`ExecutionPlanSearchOutcome::hasPlan()` method**: Removed deprecated method
  - **Replacement**: Use `hasRawFills()` instead
  - **Migration**: See [UPGRADING.md](UPGRADING.md#step-4-update-executionplansearchoutcome-usage)

- **`ExecutionPlanSearchOutcome::plan()` method**: Removed deprecated method
  - **Replacement**: Use `rawFills()` + `ExecutionPlanMaterializer::materialize()` instead
  - **Migration**: See [UPGRADING.md](UPGRADING.md#step-4-update-executionplansearchoutcome-usage)

#### Internal API Removals

- **`PathSearchEngine` class** (~1128 lines): Removed legacy best-first search engine
  - Replaced by `ExecutionPlanSearchEngine` with successive augmenting paths algorithm
  - Internal class, not part of public API
  
- **`CandidateSearchOutcome` class**: Removed internal DTO (no longer needed)

- **Legacy State classes** (in `Engine/State/`):
  - `SearchState`, `SearchStateRecord`, `SearchStateRecordCollection`
  - `SearchStatePriority`, `SearchStatePriorityQueue`
  - `SearchStateRegistry`, `SearchStateSignature`, `SearchStateSignatureFormatter`
  - `SearchQueueEntry`, `SearchBootstrap`, `SegmentPruner`, `InsertionOrderCounter`
  
- **Legacy Queue classes** (in `Engine/Queue/`):
  - `CandidateHeapEntry`, `CandidatePriority`, `CandidatePriorityQueue`
  - `CandidateResultHeap`, `StatePriorityQueue`

- **Legacy benchmarks**: Removed `PathFinderBench.php` and `LegacyComparisonBench.php`

- **Orphaned test helpers** (MUL-21):
  - `PathFinderScenarioGenerator` - generator used by removed legacy engine tests
  - `PathFinderScenarioGeneratorTest` - tests for the orphaned generator

### Breaking Changes

#### Removed Classes

- **`PathSearchService`**: Removed in favor of `ExecutionPlanService`
  - **Impact**: All code using `PathSearchService` must migrate
  - **Replacement**: `ExecutionPlanService::findBestPlans()`
  - **Migration**: See [UPGRADING.md](UPGRADING.md#step-1-update-service-instantiation)

#### Removed Methods

- **`ExecutionPlanSearchOutcome::hasPlan()`**: Removed in favor of `hasRawFills()`
  - **Impact**: Code checking for plan existence must update
  - **Replacement**: `hasRawFills()`
  - **Migration**: See [UPGRADING.md](UPGRADING.md#step-4-update-executionplansearchoutcome-usage)

- **`ExecutionPlanSearchOutcome::plan()`**: Removed in favor of materialization pattern
  - **Impact**: Code accessing plans directly must use materializer
  - **Replacement**: `rawFills()` + `ExecutionPlanMaterializer::materialize()`
  - **Migration**: See [UPGRADING.md](UPGRADING.md#step-4-update-executionplansearchoutcome-usage)

#### Changed Behavior

- **Execution plan return (2.0.0)**: In 2.0.0, `ExecutionPlanService::findBestPlans()` returns at most **one**
  execution plan; the `paths()` collection contains 0 or 1 entries. **Top-K support** (multiple ranked plans)
  is introduced in the Unreleased version: `findBestPlans()` returns multiple ranked `ExecutionPlan` entries
  (up to K) in the `paths()` collection when configured via `PathSearchConfig::withResultLimit(K)`.
  The return type remains `SearchOutcome<ExecutionPlan>` in both cases.
  - **Impact**: Code written for 2.0.0 single-plan behavior remains valid; use `bestPath()` or iterate `paths()`
  - **Migration**: For Top-K, set `withResultLimit(K)` and iterate `paths()` for alternatives

- **Result type change**: `findBestPlans()` returns `SearchOutcome<ExecutionPlan>` instead of `SearchOutcome<Path>`
  - **Impact**: Code iterating over results must update from `Path` to `ExecutionPlan`
  - **Migration**: Replace `hops()` with `steps()`, see [UPGRADING.md](UPGRADING.md#step-3-update-result-processing)

### Migration Guide

For comprehensive migration instructions, see [UPGRADING.md](UPGRADING.md#upgrading-from-1x-to-20).

**Quick Migration Checklist**:
1. Replace `PathSearchService` with `ExecutionPlanService`
2. Replace `findBestPaths()` with `findBestPlans()`
3. Replace `ExecutionPlanSearchOutcome::hasPlan()` with `hasRawFills()`
4. Replace `ExecutionPlanSearchOutcome::plan()` with `rawFills()` + `ExecutionPlanMaterializer`
5. Replace `Path` result handling with `ExecutionPlan`
6. Replace `hops()` iteration with `steps()` iteration
7. Use `bestPath()` instead of iterating over `paths()` (returns 0 or 1 plan)
8. Use `isLinear()` and `asLinearPath()` for backward compatibility if needed

### Test Suite Changes (MUL-12, MUL-21)
- **Final test count**: 1622 tests, 35683 assertions (reduced from 1625 tests after legacy cleanup)
  
- **Removed legacy PathSearchEngine tests**: Tests that relied on PathSearchEngine-specific behavior
  have been removed or updated since PathSearchService now delegates to ExecutionPlanService.
  
- **Removed multi-path tests** (intentional behavioral change):
  - `test_it_returns_multiple_paths_ordered_by_cost` - ExecutionPlanService returns single optimal plan
  - `test_it_preserves_result_insertion_order_when_costs_are_identical` - Same reason
  
- **Removed tolerance-specific tests** (engine behavior changed):
  - Tests for PathSearchEngine tolerance clamping, underspend calculation, order minimum scaling
  - Tolerance evaluation now handled by ToleranceEvaluator with different behavior
  
- **Removed fee materialization tests** (moved to unit tests):
  - PathSearchEngine-specific fee materialization tests removed from FeesPathSearchServiceTest
  - Fee handling tested at unit level (LegMaterializerTest) and integration level (ExecutionPlanServiceTest::test_fee_aggregation)
  
- **Removed edge case tests** (equivalent coverage exists):
  - PathSearchServiceEdgeCasesTest tests replaced by ExecutionPlanServiceTest guard limit tests
  
- **Updated hop limit tests** (behavioral clarification):
  - Documented that ExecutionPlanService finds optimal plans regardless of minimum hop config
  - Hop filtering is applied at PathSearchService level (backward compatibility layer)
  
- **Added equivalent coverage to ExecutionPlanServiceTest**:
  - `test_capacity_constrained_order_selection` - Capacity evaluation
  - `test_rate_selection_with_sufficient_capacity` - Rate preference
  - `test_tolerance_rejection_when_exceeded` - Tolerance enforcement
  - `test_tolerance_acceptance_within_bounds` - Tolerance acceptance
  
- **Kept with documentation**:
  - `test_plan_to_path_throws_for_non_linear` - API contract documented, will activate when split/merge produces non-linear plans

## [0.1.0] - TBD

**First tagged pre-release** - This version represents the initial BigDecimal migration and establishes the foundation for the 1.0.0 stable release.

### Added
- **Core Pathfinding Engine**:
  - `PathFinderService` for finding optimal currency conversion paths
  - `PathFinder` algorithm with Dijkstra-like search
  - `GraphBuilder` for order book graph construction
  - `SearchGuards` for resource limit enforcement (expansions, visited states, time budget)
  
- **Domain Model**:
  - `Money` value object with arbitrary precision (Brick\Math\BigDecimal)
  - `ExchangeRate` value object with deterministic decimal arithmetic
  - `Order` entity for buy/sell orders
  - `OrderBounds` for order amount constraints
  - `ToleranceWindow` for acceptable conversion slippage
  - `AssetPair` for currency pair representation
  
- **Configuration**:
  - `PathSearchConfig` with builder pattern
  - `PathSearchRequest` DTO for service requests
  - Configurable tolerance bounds, hop limits, result limits
  - Guard limits (expansion limit, visited state limit, time budget)
  
- **Extensibility**:
  - `OrderFilterInterface` for custom order filtering
  - `PathOrderStrategy` for custom path ranking
  - `FeePolicy` interface for custom fee calculations
  - Built-in filters: `MinimumAmountFilter`, `MaximumAmountFilter`, `ToleranceWindowFilter`, `CurrencyPairFilter`
  - Built-in fee policies: `NoFeePolicy`, `PercentageFeePolicy`, `FixedFeePolicy`, `TieredFeePolicy`, `MakerTakerFeePolicy`
  
- **Results**:
  - `SearchOutcome` with paths and guard reports
  - `PathResult` with detailed path information
  - `PathLeg` for individual hop details
  - `SearchGuardReport` for diagnostics
  - Object APIs for all result types
  
- **Exception Hierarchy**:
  - `InvalidInput` for domain validation errors
  - `GuardLimitExceeded` for resource exhaustion
  - `PrecisionViolation` for arithmetic precision issues
  - `InfeasiblePath` for impossible path constraints
  
- **Documentation**:
  - Comprehensive README with quick start and examples
  - Getting Started Guide (step-by-step tutorial)
  - Troubleshooting Guide (common issues and solutions)
  - Architecture Guide (7 Mermaid diagrams, 943 lines)
  - API Contracts (object API specification)
  - API Stability Guide (public API guarantees)
  - Domain Invariants (value object constraints)
  - Decimal Strategy (arbitrary precision policy)
  - Memory Characteristics (optimization guide)
  - Exception Handling Guide (catch strategies)
  - Versioning Policy (SemVer, BC breaks, deprecation)
  - Release Process (pre-release checklist, hotfix procedures)
  - Support Policy (PHP/library version support timelines)
  
- **Examples**:
  - `guarded-search-example.php` - Complete workflow demonstration
  - `custom-order-filter.php` - 4 filter implementations
  - `custom-ordering-strategy.php` - 3 ordering strategies
  - `custom-fee-policy.php` - 5 fee policy implementations
  - `error-handling.php` - Comprehensive error handling patterns
  - `performance-optimization.php` - 4 optimization techniques with benchmarks
  - `examples/README.md` - Complete examples documentation
  
- **Testing**:
  - Unit tests for all domain objects
  - Integration tests for PathFinderService
  - Property-based tests for Money and ExchangeRate
  - Algorithm correctness tests (ordering determinism, guard enforcement)
  - Edge case tests (tolerance, hop limits, mandatory segments)
  - Stress tests (large graphs, adversarial scenarios)
  - Test coverage > 90%
  
- **Quality Tools**:
  - PHPStan level max with custom rules
  - PHP CS Fixer for code style
  - Psalm for additional static analysis
  - Infection for mutation testing
  - Custom PHPStan rules for decimal arithmetic enforcement
  
- **CI/CD**:
  - Automated tests on PHP 8.2 and 8.3
  - Static analysis checks
  - Code style checks
  - Example validation
  - Custom PHPStan rules verification
  
- **Community**:
  - Contributing guide
  - Code of conduct
  - Security policy
  - Issue templates
  - Pull request templates
  
- **Composer**:
  - Example runner scripts (`composer examples`)
  - Test runner script (`composer phpunit`)
  - Quality check scripts (`composer check`, `composer check:full`)
  - Individual tool scripts (phpstan, psalm, php-cs-fixer, etc.)

### Changed
- **BigDecimal Migration**: All arithmetic migrated from BCMath to `Brick\Math\BigDecimal` for deterministic precision
- **Value Object API**: Value objects now expose decimal accessors for internal precision control
- **PathFinder API**: `findBestPaths()` now uses `SpendConstraints` and returns `CandidatePath` instances (replacing associative arrays)
- **Service API**: `PathFinderService::findBestPaths()` accepts `PathSearchRequest` DTO (encapsulating order book, config, target asset)
- **Constructor Simplification**: `PathFinderService` constructor simplified to only require `GraphBuilder` and optional `PathOrderStrategy`
- **SpendConstraints API**: `bounds()` method replaces `SpendRange` exposure; `internalRange()` for implementation details
- **Documentation**: README clarifies supported extension points (removed custom `pathFinderFactory` recommendation)

### Deprecated
- Nothing (this is the first release)

### Removed
- BCMath arithmetic functions (replaced by BigDecimal)
- Legacy associative array payloads in `PathFinder` (replaced by value objects)
- Multi-argument `PathFinderService` constructor (simplified to 1-2 arguments)

### Fixed
- Floating-point precision issues (eliminated by BigDecimal migration)
- Rounding inconsistencies in Money and ExchangeRate calculations
- Edge cases in tolerance amplifier calculations

### Security
- No security issues to report (this is the first release)

---

## Version History Format

Each version should follow this structure:

```markdown
## [X.Y.Z] - YYYY-MM-DD

### Added
- New features, functionality, or capabilities

### Changed
- Changes to existing functionality (backward compatible in MINOR versions)

### Deprecated
- Features marked for removal in future versions

### Removed
- Features removed in this version (requires MAJOR version bump)

### Fixed
- Bug fixes

### Security
- Security vulnerability fixes
```

### Category Guidelines

- **Added**: New features, classes, methods, or configuration options
- **Changed**: Modifications to existing functionality (BC breaks require MAJOR version)
- **Deprecated**: Features that will be removed in a future version (minimum 1 MINOR version warning)
- **Removed**: Features that have been deleted (always requires MAJOR version bump)
- **Fixed**: Bug fixes that restore intended behavior
- **Security**: Security vulnerability fixes (always released immediately as hotfix)

### Version Comparison Links

[Unreleased]: https://github.com/somework/p2p-path-finder/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/somework/p2p-path-finder/compare/v0.1.0...v2.0.0
[0.1.0]: https://github.com/somework/p2p-path-finder/releases/tag/v0.1.0
