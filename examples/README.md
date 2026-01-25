# Examples

This directory contains comprehensive, runnable examples demonstrating how to use and extend the P2P Path Finder library. All examples are production-ready and can serve as templates for your own implementations.

## Table of Contents

- [Overview](#overview)
- [Running Examples](#running-examples)
- [Examples by Topic](#examples-by-topic)
  - [Customization & Extension](#customization--extension)
  - [Error Handling & Production Patterns](#error-handling--production-patterns)
  - [Performance Optimization](#performance-optimization)
  - [Complete Workflows](#complete-workflows)
- [Example Details](#example-details)
- [Related Documentation](#related-documentation)

## Overview

All examples in this directory are:
- ✅ **Runnable** - Execute directly with `php examples/{filename}`
- ✅ **Production-ready** - Demonstrate best practices and real-world patterns
- ✅ **Well-documented** - Extensive inline comments explaining design decisions
- ✅ **Comprehensive** - Cover multiple scenarios and edge cases
- ✅ **Tested** - Syntax validated and integration tested

## Running Examples

### Prerequisites

- PHP 8.2 or newer
- Composer dependencies installed (`composer install`)

### Execution

#### Option 1: Direct PHP Execution

Run any example directly from the project root:

```bash
# From project root
php examples/custom-order-filter.php
php examples/error-handling.php
php examples/performance-optimization.php
php examples/bybit-p2p-integration.php
# ... etc
```

#### Option 2: Using Composer Scripts (Recommended)

Run examples using composer scripts for convenience:

```bash
# Run all examples (in sequence)
composer examples

# Run individual examples
composer examples:custom-order-filter
composer examples:custom-ordering-strategy
composer examples:custom-fee-policy
composer examples:error-handling
composer examples:performance-optimization
composer examples:guarded-search
composer examples:bybit-p2p-integration
composer examples:advanced-search-strategies
```

**Benefits of using composer scripts**:
- ✅ Consistent execution environment
- ✅ Easier to remember (named scripts)
- ✅ Can be integrated into CI/CD
- ✅ Validates examples work with your installation

#### Option 3: Docker Execution

If using Docker:

```bash
# From parent directory containing docker-compose.yml
docker compose exec php php /var/www/html/p2p-path-finder/examples/custom-order-filter.php

# Or with composer
docker compose exec php composer examples
```

## Examples by Topic

### ExecutionPlanService (Recommended)

These examples demonstrate the recommended `ExecutionPlanService` API for path finding.

#### 1. Basic ExecutionPlanService Usage
**File**: [`execution-plan-basic.php`](execution-plan-basic.php)

Demonstrates the basic usage of `ExecutionPlanService` for finding optimal execution plans.

**What you'll learn**:
- Creating an order book
- Configuring search parameters
- Using `ExecutionPlanService::findBestPlans()`
- Processing `ExecutionPlan` results
- Understanding `ExecutionStep` sequence numbers
- Converting linear plans to legacy `Path` format

**When to use**:
- Starting a new project with the library
- Learning the recommended API
- Migrating from `PathSearchService`

**Related docs**:
- [Getting Started Guide](../docs/getting-started.md#executionplanservice-recommended)
- [API Contracts](../docs/api-contracts.md#executionplan)

---

#### 2. Split/Merge Execution Patterns
**File**: [`execution-plan-split-merge.php`](execution-plan-split-merge.php)

Demonstrates advanced execution plan capabilities exclusive to `ExecutionPlanService`.

**What you'll learn**:
- Multi-order same direction (multiple orders for USD→BTC)
- Split execution (input split across parallel routes)
- Merge execution (routes converging at target)
- Diamond patterns (combined split and merge)
- Using `isLinear()` and `asLinearPath()` for migration

**When to use**:
- P2P trading platforms with multiple market makers
- Multi-currency arbitrage detection
- Optimal liquidity routing across fragmented markets
- Exchange aggregation requiring split/merge execution

**Related docs**:
- [Architecture Guide](../docs/architecture.md#executionplansearchengine-algorithm-recommended)
- [UPGRADING.md](../UPGRADING.md#upgrading-from-1x-to-20)

---

### Customization & Extension

These examples demonstrate the library's extension points and how to customize behavior for your specific needs.

#### 1. Custom Order Filters
**File**: [`custom-order-filter.php`](custom-order-filter.php) (397 lines)

Demonstrates how to implement custom `OrderFilterInterface` filters to pre-filter orders before path finding.

**What you'll learn**:
- Implementing single-responsibility filters (LiquidityDepth, FeeFree, ExchangeRateRange)
- Composing multiple filters with AND logic (CompositeAndFilter)
- Proper scale and currency handling in filters
- O(1) evaluation patterns with early returns
- Integration with PathFinderService

**When to use**:
- You need to filter orders by custom criteria (liquidity, fees, rates, etc.)
- You want to reduce search space for better performance
- You need to combine multiple filtering conditions

**Related docs**: 
- [Getting Started Guide](../docs/getting-started.md#customizing-the-search)
- [Architecture Guide](../docs/architecture.md#extension-points)

---

#### 2. Custom Path Ordering Strategies
**File**: [`custom-ordering-strategy.php`](custom-ordering-strategy.php) (527 lines)

Demonstrates how to implement custom `PathOrderStrategy` implementations to control path ranking.

**What you'll learn**:
- MinimizeHopsStrategy - prioritize simpler paths over cost
- WeightedScoringStrategy - balance multiple criteria with weights
- RoutePreferenceStrategy - prefer paths containing specific currencies
- Comparing multiple strategies with the same order book
- Verifying deterministic ordering across multiple runs

**When to use**:
- Default cost-first ordering doesn't match your business logic
- You need to prioritize path simplicity (fewer hops)
- You want to favor specific currencies or routes
- You need custom scoring logic (weighted factors)

**Related docs**:
- [Architecture Guide](../docs/architecture.md#custom-path-ordering)
- [Getting Started Guide](../docs/getting-started.md#customizing-the-search)

---

#### 3. Custom Fee Policies
**File**: [`custom-fee-policy.php`](custom-fee-policy.php) (570 lines)

Demonstrates how to implement custom `FeePolicy` implementations for realistic fee calculation.

**What you'll learn**:
- PercentageFeePolicy - most common model (0.5% of quote)
- FixedFeePolicy - flat fee per transaction
- TieredFeePolicy - volume-based rates (0.5% < $1k, 0.3% >= $1k)
- MakerTakerFeePolicy - different rates for makers vs takers
- CombinedFeePolicy - percentage with min/max constraints
- Fee impact on path costs and rankings

**When to use**:
- You need to model exchange fees accurately
- Your fee structure is more complex than simple percentages
- You have volume-based discounts or maker/taker models
- You need minimum or maximum fee constraints

**Related docs**:
- [Getting Started Guide](../docs/getting-started.md#working-with-orders)
- [Architecture Guide](../docs/architecture.md#custom-fee-policies)

---

### Error Handling & Production Patterns

These examples demonstrate proper error handling and production-ready patterns.

#### 4. Error Handling Patterns
**File**: [`error-handling.php`](error-handling.php) (473 lines)

Comprehensive guide to handling all error scenarios in production systems.

**What you'll learn**:
- **InvalidInput** - Domain invariant violations (4 examples)
- **GuardLimitExceeded** - Resource exhaustion (exception mode)
- **Guard Limits** - Metadata mode with partial results (default)
- **Empty Results** - Valid business outcome (not an error!)
- **PrecisionViolation** - Arithmetic precision loss (rare)
- Complete production error handling function
- HTTP status code recommendations for APIs
- Recovery strategies for each error type

**When to use**:
- You're integrating the library into a production system
- You need to handle errors gracefully and provide user-friendly messages
- You're building an API and need proper HTTP status codes
- You need to monitor and alert on specific error conditions

**Related docs**:
- [Exception Handling Guide](../docs/exceptions.md)
- [Troubleshooting Guide](../docs/troubleshooting.md)
- [API Contracts](../docs/api-contracts.md)

---

### Performance Optimization

These examples demonstrate performance optimization techniques with measurable improvements.

#### 5. Performance Optimization Techniques
**File**: [`performance-optimization.php`](performance-optimization.php) (507 lines)

Demonstrates practical optimization strategies with benchmarks showing measurable improvements.

**What you'll learn**:
- **Pre-filtering** (30-70% improvement) - Remove irrelevant orders
- **Guard limit tuning** (linear impact) - Balance thoroughness vs resources
- **Tolerance window tuning** (10-30% impact) - Narrow windows = faster search
- **Hop limit tuning** (EXPONENTIAL impact) - Each hop multiplies complexity
- 12+ benchmark comparisons with time/memory metrics
- 3 production configurations (latency-sensitive, balanced, comprehensive)
- Monitoring and tuning guidance

**When to use**:
- Your searches are too slow or using too much memory
- You need to optimize for specific latency targets (< 100ms, < 200ms)
- You're capacity planning for production deployment
- You need to tune guard limits based on actual usage

**Related docs**:
- [Memory Characteristics](../docs/memory-characteristics.md)
- [Performance Benchmarks](../README.md#performance-benchmarks)
- [Troubleshooting Guide](../docs/troubleshooting.md#performance-issues)

---

### Complete Workflows

These examples demonstrate complete end-to-end workflows.

#### 6. Guarded Search Example
**File**: [`guarded-search-example.php`](guarded-search-example.php)

Complete workflow demonstrating path search with guard rails and result interpretation.

**What you'll learn**:
- Complete PathSearchConfig setup
- Running searches with guard limits
- Interpreting SearchOutcome and guard reports
- Checking for guard limit breaches
- Understanding when results may be incomplete

**When to use**:
- You're new to the library and want a complete working example
- You need to understand the basic search flow
- You want to see guard rails in action

**Related docs**:
- [Getting Started Guide](../docs/getting-started.md)

---

#### 7. Bybit P2P API Integration
**File**: [`bybit-p2p-integration.php`](bybit-p2p-integration.php)

Real-world integration example showing how to fetch P2P trading data from Bybit's API and use it with the PathFinder library.

**What you'll learn**:
- Mock Bybit P2P API client implementation
- Mapping Bybit advertisement data to Order objects
- Handling BUY/SELL order side conversions
- Fetching data from multiple markets
- Complete production-ready integration pattern
- Error handling for API and library errors

**When to use**:
- You want to integrate with Bybit P2P trading platform
- You need to work with real exchange APIs
- You want to see a complete real-world integration example
- You're building a P2P trading aggregator or optimizer

**API Documentation**:
- [Bybit P2P Get Ads API](https://bybit-exchange.github.io/docs/p2p/ad/online-ad-list)

**Related docs**:
- [Getting Started Guide](../docs/getting-started.md)
- [Error Handling Guide](../docs/exceptions.md)

---

#### 8. Advanced Search Strategies
**File**: [`advanced-search-strategies.php`](advanced-search-strategies.php)

Demonstrates ExecutionPlanService's ability to handle complex path-finding scenarios including multi-order aggregation, split/merge patterns, and diamond topologies.

**What you'll learn**:
- Multi-Order Same Direction: Multiple A→B orders with automatic best-rate selection
- Split at Source: A→B and A→C patterns (source distributing to routes)
- Merge at Target: B→D and C→D patterns (routes converging at target)
- Diamond Pattern: A→B, A→C, B→D, C→D (combined split and merge)
- Complex Real-World Graphs: Multi-layer currency networks
- Step-by-step execution flow visualization
- Performance metrics and guard limit handling

**When to use**:
- P2P trading platforms with multiple market makers
- Multi-currency arbitrage detection
- Optimal liquidity routing across fragmented markets
- Exchange aggregation requiring split/merge execution
- Understanding how the search engine selects optimal paths

**Related docs**:
- [Getting Started Guide](../docs/getting-started.md)
- [Architecture Guide](../docs/architecture.md)

---

## Example Details

### File Statistics

| Example                            | Lines      | Status      | Scenarios | Key Features                        |
|------------------------------------|------------|-------------|-----------|-------------------------------------|
| **execution-plan-basic.php**       | 170+       | Production  | 1         | ExecutionPlanService basics         |
| **execution-plan-split-merge.php** | 260+       | Production  | 4         | Split/merge, diamond patterns       |
| **custom-order-filter.php**        | 397        | Production  | 5         | 4 filters, composition pattern      |
| **custom-ordering-strategy.php**   | 527        | Production  | 4         | 3 strategies, determinism test      |
| **custom-fee-policy.php**          | 570        | Production  | 6         | 5 policies, realistic models        |
| **error-handling.php**             | 473        | Production  | 7         | All exceptions, production pattern  |
| **performance-optimization.php**   | 507        | Production  | 12        | 4 techniques, benchmarks            |
| **guarded-search-example.php**     | Varies     | Production  | 1         | Complete workflow (legacy)          |
| **bybit-p2p-integration.php**      | 600+       | Production  | 5         | API integration, real-world example |
| **advanced-search-strategies.php** | 400+       | Production  | 8         | Multi-order, split/merge, diamond   |
| **TOTAL**                          | **~4,000** | **10 files** | **53+**  | **Comprehensive coverage**          |

### Example Categories

#### By Difficulty Level

**Beginner** (Start here):
1. `execution-plan-basic.php` - **Recommended** starting point with ExecutionPlanService
2. `guarded-search-example.php` - Complete basic workflow (legacy PathSearchService)
3. `custom-order-filter.php` - Simple filter implementations

**Intermediate**:
4. `execution-plan-split-merge.php` - Split/merge execution patterns
5. `custom-fee-policy.php` - Realistic fee modeling
6. `custom-ordering-strategy.php` - Custom ranking logic
7. `bybit-p2p-integration.php` - Real-world API integration
8. `advanced-search-strategies.php` - Multi-order, split, merge patterns

**Advanced**:
9. `error-handling.php` - Production error handling
10. `performance-optimization.php` - Performance tuning

#### By Use Case

**If you want to...**

| Goal                                 | Example                          |
|--------------------------------------|----------------------------------|
| Learn the recommended API            | `execution-plan-basic.php`       |
| Migrate from PathSearchService       | `execution-plan-basic.php`       |
| Handle split/merge execution         | `execution-plan-split-merge.php` |
| Multi-order liquidity aggregation    | `execution-plan-split-merge.php` |
| Filter orders before search          | `custom-order-filter.php`        |
| Change how paths are ranked          | `custom-ordering-strategy.php`   |
| Model realistic exchange fees        | `custom-fee-policy.php`          |
| Handle errors in production          | `error-handling.php`             |
| Optimize search performance          | `performance-optimization.php`   |
| See legacy workflow (deprecated)     | `guarded-search-example.php`     |
| Integrate with Bybit P2P API         | `bybit-p2p-integration.php`      |
| Build a real-world trading app       | `bybit-p2p-integration.php`      |
| Handle multi-order/split/merge paths | `advanced-search-strategies.php` |
| Understand complex path topologies   | `advanced-search-strategies.php` |

## Related Documentation

### Core Documentation
- **[Getting Started Guide](../docs/getting-started.md)** - Step-by-step tutorial for beginners
- **[Architecture Guide](../docs/architecture.md)** - Complete architectural overview with diagrams
- **[API Contracts](../docs/api-contracts.md)** - JSON serialization format specification

### Specialized Documentation
- **[Exception Handling](../docs/exceptions.md)** - Exception hierarchy and catch strategies
- **[Domain Invariants](../docs/domain-invariants.md)** - Value object constraints and rules
- **[Memory Characteristics](../docs/memory-characteristics.md)** - Memory usage and optimization
- **[Decimal Strategy](../docs/decimal-strategy.md)** - Precision arithmetic policy
- **[Troubleshooting](../docs/troubleshooting.md)** - Common issues and solutions

### Quick Reference
- **[README](../README.md)** - Library overview and quick start
- **[CONTRIBUTING](../CONTRIBUTING.md)** - Development guidelines
- **[CHANGELOG](../CHANGELOG.md)** - Version history

## Tips for Using Examples

### 1. Start with the Right Example

Choose based on your immediate need:
- **Learning**: Start with `guarded-search-example.php`
- **Customizing**: Check extension examples (filters, strategies, fees)
- **Production**: Review `error-handling.php` and `performance-optimization.php`

### 2. Read the Inline Documentation

All examples have extensive inline comments explaining:
- Why each approach is used
- When it's appropriate
- Common pitfalls to avoid
- Best practices to follow

### 3. Adapt to Your Needs

Examples are designed to be templates:
- Copy the patterns that fit your use case
- Modify the logic for your specific requirements
- Keep the structure and best practices intact

### 4. Combine Techniques

Many examples can be combined:
- Use custom filters from `custom-order-filter.php`
- Apply custom ordering from `custom-ordering-strategy.php`
- Add error handling from `error-handling.php`
- Optimize with techniques from `performance-optimization.php`

### 5. Run Examples Locally

The best way to understand examples is to run them:
```bash
# Run an example
php examples/custom-order-filter.php

# Modify and re-run to see effects
# Edit the file, save, and run again
```

## Contributing Examples

If you've developed a useful pattern or use case not covered here, consider contributing!

See [CONTRIBUTING.md](../CONTRIBUTING.md) for guidelines on:
- Code style and structure
- Documentation requirements
- Testing and validation
- Submission process

Good example contributions include:
- Real-world integration patterns
- Performance optimization case studies
- Error recovery strategies
- Domain-specific customizations

## Need Help?

If examples don't answer your question:

1. **Check documentation**: [docs/](../docs/) directory has comprehensive guides
2. **Review troubleshooting**: [docs/troubleshooting.md](../docs/troubleshooting.md) covers common issues
3. **Search issues**: Check if someone else had the same question
4. **Ask for help**: Open an issue with your use case and what you've tried

## Summary

This examples directory provides:
- ✅ **10 comprehensive examples** covering all major use cases
- ✅ **4,000+ lines** of production-ready code
- ✅ **53+ demonstration scenarios** showing different patterns
- ✅ **ExecutionPlanService examples** for the recommended API
- ✅ **Split/merge patterns** demonstrating advanced execution topologies
- ✅ **Complete workflows** from configuration to result handling
- ✅ **Real-world integrations** with external APIs (Bybit P2P)
- ✅ **Measurable improvements** via benchmarks and comparisons
- ✅ **Migration guidance** from PathSearchService to ExecutionPlanService

**Start with `execution-plan-basic.php`** for the recommended API, then explore `execution-plan-split-merge.php` for advanced execution patterns. For legacy code or simple linear paths, see `guarded-search-example.php`. Check out `bybit-p2p-integration.php` for a complete real-world API integration example.

**Happy coding!** 🚀

