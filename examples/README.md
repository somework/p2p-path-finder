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

Run any example directly from the project root:

```bash
# From project root
php examples/custom-order-filter.php
php examples/error-handling.php
php examples/performance-optimization.php
# ... etc
```

### Docker Execution

If using Docker:

```bash
# From parent directory containing docker-compose.yml
docker compose exec php php /var/www/html/p2p-path-finder/examples/custom-order-filter.php
```

## Examples by Topic

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
- [Guarded Search Example Doc](../docs/guarded-search-example.md)

---

## Example Details

### File Statistics

| Example | Lines | Status | Scenarios | Key Features |
|---------|-------|--------|-----------|--------------|
| **custom-order-filter.php** | 397 | Production | 5 | 4 filters, composition pattern |
| **custom-ordering-strategy.php** | 527 | Production | 4 | 3 strategies, determinism test |
| **custom-fee-policy.php** | 570 | Production | 6 | 5 policies, realistic models |
| **error-handling.php** | 473 | Production | 7 | All exceptions, production pattern |
| **performance-optimization.php** | 507 | Production | 12 | 4 techniques, benchmarks |
| **guarded-search-example.php** | Varies | Production | 1 | Complete workflow |
| **TOTAL** | **~2,500** | **6 files** | **35+** | **Comprehensive coverage** |

### Example Categories

#### By Difficulty Level

**Beginner** (Start here):
1. `guarded-search-example.php` - Complete basic workflow
2. `custom-order-filter.php` - Simple filter implementations

**Intermediate**:
3. `custom-fee-policy.php` - Realistic fee modeling
4. `custom-ordering-strategy.php` - Custom ranking logic

**Advanced**:
5. `error-handling.php` - Production error handling
6. `performance-optimization.php` - Performance tuning

#### By Use Case

**If you want to...**

| Goal | Example |
|------|---------|
| Filter orders before search | `custom-order-filter.php` |
| Change how paths are ranked | `custom-ordering-strategy.php` |
| Model realistic exchange fees | `custom-fee-policy.php` |
| Handle errors in production | `error-handling.php` |
| Optimize search performance | `performance-optimization.php` |
| See a complete basic workflow | `guarded-search-example.php` |

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
- ✅ **6 comprehensive examples** covering all major use cases
- ✅ **2,500+ lines** of production-ready code
- ✅ **35+ demonstration scenarios** showing different patterns
- ✅ **Complete workflows** from configuration to result handling
- ✅ **Measurable improvements** via benchmarks and comparisons

Start with `guarded-search-example.php` for a basic workflow, then explore customization and optimization examples based on your needs.

**Happy coding!** 🚀

