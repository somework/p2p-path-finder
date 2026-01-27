# p2p-path-finder

[![Tests](https://img.shields.io/github/actions/workflow/status/somework/p2p-path-finder/tests.yml?branch=main&label=Tests)](https://github.com/somework/p2p-path-finder/actions/workflows/tests.yml)
[![Quality](https://img.shields.io/github/actions/workflow/status/somework/p2p-path-finder/quality.yml?branch=main&label=Quality)](https://github.com/somework/p2p-path-finder/actions/workflows/quality.yml)
[![PHP Version](https://img.shields.io/packagist/php-v/somework/p2p-path-finder)](https://packagist.org/packages/somework/p2p-path-finder)
[![Latest Release](https://img.shields.io/github/v/release/somework/p2p-path-finder)](https://github.com/somework/p2p-path-finder/releases)
[![License](https://img.shields.io/github/license/somework/p2p-path-finder)](LICENSE)

A small toolkit for discovering optimal peer-to-peer conversion paths across a set of orders. The package focuses on deterministic arithmetic, declarative configuration, and clear separation between the domain model and application services.

## Table of Contents

- [Installation](#installation)
- [Quick Start](#quick-start)
- [Core Features](#core-features)
- [Documentation](#documentation)
- [Performance](#performance)
- [Testing and Quality](#testing-and-quality)
- [Contributing](#contributing)

---

## Installation

**Requirements**: PHP 8.2+ and [Composer 2.x](https://getcomposer.org/)

```bash
composer require somework/p2p-path-finder
```

Decimal math is handled by [`brick/math`](https://github.com/brick/math), so `ext-bcmath` is no longer required.

---

## Quick Start

Find the best execution plan from USD to BTC:

```php
<?php

require 'vendor/autoload.php';

use SomeWork\P2PPathFinder\Application\PathSearch\Api\Request\PathSearchRequest;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\ExecutionPlanService;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;
use SomeWork\P2PPathFinder\Domain\Money\AssetPair;
use SomeWork\P2PPathFinder\Domain\Money\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderBook;
use SomeWork\P2PPathFinder\Domain\Order\OrderBounds;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;

// 1. Create an order book
$order = new Order(
    OrderSide::SELL,
    AssetPair::fromString('USD', 'BTC'),
    OrderBounds::from(
        Money::fromString('USD', '10.00', 2),
        Money::fromString('USD', '10000.00', 2),
    ),
    ExchangeRate::fromString('USD', 'BTC', '0.000033', 8),
);

$orderBook = new OrderBook([$order]);

// 2. Configure the search
$config = PathSearchConfig::builder()
    ->withSpendAmount(Money::fromString('USD', '100.00', 2))
    ->withToleranceBounds('0.00', '0.05')  // 0-5% tolerance
    ->withHopLimits(1, 3)  // Allow 1-3 hop paths
    ->build();

// 3. Run the search
$service = new ExecutionPlanService(new GraphBuilder());
$request = new PathSearchRequest($orderBook, $config, 'BTC');
$outcome = $service->findBestPlans($request);

// 4. Use the results (single optimal plan returned)
$plan = $outcome->bestPath();  // Returns ExecutionPlan or null
if (null !== $plan) {
    echo "Spend: {$plan->totalSpent()->amount()} {$plan->totalSpent()->currency()}\n";
    echo "Receive: {$plan->totalReceived()->amount()} {$plan->totalReceived()->currency()}\n";
    echo "Residual tolerance: {$plan->residualTolerance()->percentage()}%\n";

    foreach ($plan->steps() as $step) {
        echo "Step {$step->sequenceNumber()}: {$step->from()} -> {$step->to()}\n";
        echo "  Spent: {$step->spent()->amount()} {$step->spent()->currency()}\n";
        echo "  Received: {$step->received()->amount()} {$step->received()->currency()}\n";
    }
}
```

### ExecutionPlan

The library uses `ExecutionPlanService` which returns `ExecutionPlan` objects supporting:

| Feature | Description |
|---------|-------------|
| Service | `ExecutionPlanService` |
| Method | `findBestPlans()` |
| Results | 0 or 1 (single optimal plan) |
| Splits | Yes |
| Merges | Yes |
| Linear paths | Yes (via `isLinear()`) |

> **Important**: `ExecutionPlanService` returns at most **ONE** optimal execution plan. The `paths()` collection will contain 0 or 1 entries. For alternative routes, run separate searches with different constraints.

**Supported execution patterns:**
- Multiple orders for same direction (USD→BTC via two market makers)
- Split execution (USD→EUR and USD→GBP simultaneously)
- Merge execution (EUR→BTC and GBP→BTC converge)
- Linear paths (use `plan->asLinearPath()` for simple Path format)

### Next Steps

- **New to the library?** Read the **[Getting Started Guide](docs/getting-started.md)** for a comprehensive tutorial
- **Having issues?** Check the **[Troubleshooting Guide](docs/troubleshooting.md)** for solutions to common problems
- **Need examples?** Browse **[examples/](examples/)** for runnable production-ready code

**Run all examples**:

```bash
composer examples
```

See [examples/README.md](examples/README.md) for complete documentation.

---

## Core Features

### Tolerance-Aware Path Finding

Find the k-best paths within configurable tolerance bounds (0-100%):

```php
$config = PathSearchConfig::builder()
    ->withSpendAmount(Money::fromString('USD', '100.00', 2))
    ->withToleranceBounds('0.00', '0.10')  // Accept paths 0-10% worse than optimal
    ->withHopLimits(1, 4)                  // Allow 1-4 hop paths
    ->withResultLimit(5)                   // Return top 5 paths
    ->build();
```

### Multi-Hop Routing

Automatically discover paths through intermediate currencies:

```text
USD → USDT → BTC       (2 hops)
USD → EUR → BTC        (2 hops)
USD → USDT → ETH → BTC (3 hops)
```

### Split/Merge Execution (New in 2.0)

`ExecutionPlanService` can find optimal execution plans that go beyond linear paths:

```text
Multi-order same direction:
  USD → BTC (order1: rate 0.000033)
  USD → BTC (order2: rate 0.000032)  ← selects best rate

Split at source:
  USD → EUR (order1)
  USD → GBP (order2)  ← splits input across routes

Merge at target:
  EUR → BTC (order1)
  GBP → BTC (order2)  ← routes converge at target

Diamond pattern (split + merge):
  USD → EUR → BTC
  USD → GBP → BTC  ← parallel paths through different currencies
```

Use `ExecutionPlan::isLinear()` to check if a plan is a simple linear path:

```php
$plan = $outcome->bestPath();
if ($plan->isLinear()) {
    // Simple linear path - can convert to legacy Path if needed
    $path = $plan->asLinearPath();
} else {
    // Complex execution with splits/merges
    foreach ($plan->steps() as $step) {
        // Each step has sequenceNumber() for execution order
    }
}
```

### Execution-Level Transparency

Search results return `ExecutionPlan` objects backed by ordered `ExecutionStepCollection`. Totals (`totalSpent()`, `totalReceived()`), fee breakdowns, and residual tolerance are aggregated from step data. Each step exposes:

- **`from()` / `to()`**: Source and destination currencies
- **`spent()` / `received()`**: Monetary amounts for the step
- **`order()`**: Originating `Order` for reconciliation or ID lookup
- **`fees()`**: Step-level fee breakdown
- **`sequenceNumber()`**: Execution order (1-based)

For linear plans, `asLinearPath()` converts to `Path` with `PathHop` collections for backward compatibility.

### Guard Rails and Performance

Configure guard limits to balance thoroughness with performance:

```php
use SomeWork\P2PPathFinder\Application\PathSearch\Config\SearchGuardConfig;

// Latency-sensitive (< 50ms target)
$guards = SearchGuardConfig::strict()
    ->withMaxExpansions(5000)
    ->withMaxVisitedStates(10000)
    ->withTimeBudget(50);  // 50ms

$config = PathSearchConfig::builder()
    ->withSpendAmount($amount)
    ->withToleranceBounds('0.0', '0.05')
    ->withSearchGuardConfig($guards)
    ->build();
```

### Extension Points

The library provides several extension points:

- **Custom Order Filters** - Filter orders before searching ([example](examples/custom-order-filter.php))
- **Custom Path Ordering** - Control path ranking logic ([example](examples/custom-ordering-strategy.php))
- **Custom Fee Policies** - Implement complex fee structures ([example](examples/custom-fee-policy.php))

See the [Getting Started Guide](docs/getting-started.md) for detailed examples.

### Decimal Precision

All arithmetic uses arbitrary precision decimals via `brick/math`:

- **No floating-point errors**
- **Scale-aware operations** (preserve precision)
- **Deterministic results** (same input → same output)

See [Decimal Strategy Guide](docs/decimal-strategy.md) for details.

---

## Documentation

### Getting Started

- **[Getting Started Guide](docs/getting-started.md)** – Complete tutorial with working examples
- **[Troubleshooting Guide](docs/troubleshooting.md)** – Common issues and solutions

### API Reference

- **[API Stability Guide](docs/api-stability.md)** – Public API surface and stability guarantees
- **[API Contracts](docs/api-contracts.md)** – Object API specification and usage examples
- **[Domain Invariants](docs/domain-invariants.md)** – Value object constraints and validation

### Architecture and Internals

- **[Architecture Guide](docs/architecture.md)** – System design and component interactions
- **[Decimal Strategy](docs/decimal-strategy.md)** – Arbitrary precision arithmetic policy
- **[Memory Characteristics](docs/memory-characteristics.md)** – Memory usage and optimization

### Error Handling

- **[Exception Handling Guide](docs/exceptions.md)** – Exception hierarchy and catch strategies

### Releases and Support

- **[Releases and Support Policy](docs/releases-and-support.md)** – Versioning, BC policy, PHP/library support
- **[Changelog](CHANGELOG.md)** – Version history and changes
- **[Upgrading Guide](UPGRADING.md)** – Migration guides for major versions

### Examples

All examples in [examples/](examples/) are runnable:

```bash
composer examples                          # Run all
composer examples:custom-order-filter      # Specific example
composer examples:error-handling
composer examples:performance-optimization
```

---

## Performance

**Latest benchmarks** (PHP 8.3, Ubuntu 22.04, Xeon vCPU):

| Scenario    | Orders | Mean Time | Peak Memory |
|-------------|--------|-----------|-------------|
| k-best-n1e2 | 100    | 25.5ms    | 8.3 MB      |
| k-best-n1e3 | 1,000  | 216.3ms   | 12.8 MB     |
| k-best-n1e4 | 10,000 | 2,154.7ms | 59.1 MB     |

> ✅  **Performance Update (2025-11-21):** The BigDecimal migration delivered **85-87% faster runtime** compared to BCMath baseline.

**Memory scales predictably**:

| Order Book Size | Peak Memory | Recommended Guards           |
|-----------------|-------------|------------------------------|
| 100 orders      | 8-15 MB     | 10k states, 25k expansions   |
| 1,000 orders    | 12-30 MB    | 50k states, 100k expansions  |
| 10,000 orders   | 50-150 MB   | 100k states, 200k expansions |

**Optimization strategies**:

1. **Pre-filter order books** (30-70% reduction)
2. **Use conservative guard limits**
3. **Keep resultLimit low** (1-10 paths)
4. **Limit hop depth** (1-4 hops)

See [Memory Characteristics Guide](docs/memory-characteristics.md) for comprehensive analysis.

**Run benchmarks locally**:

```bash
php -d memory_limit=-1 -d xdebug.mode=off vendor/bin/phpbench run \
    --config=phpbench.json \
    --ref=baseline \
    --progress=plain
```

---

## Testing and Quality

**Run tests**:

```bash
composer phpunit
```

**Static analysis**:

```bash
composer phpstan  # Includes custom decimal arithmetic rules
composer psalm
```

**Code style**:

```bash
composer php-cs-fixer
```

**Full quality check**:

```bash
composer check  # PHPStan + Psalm + CS Fixer
```

**Mutation testing**:

```bash
INFECTION=1 XDEBUG_MODE=coverage vendor/bin/infection
```

---

## Contributing

We welcome contributions! Please read:

- **[Contributing Guide](CONTRIBUTING.md)** – Guidelines for issues and pull requests
- **[Code of Conduct](CODE_OF_CONDUCT.md)** – Community expectations
- **[Security Policy](SECURITY.md)** – Responsible vulnerability disclosure

Track progress toward `1.0.0-rc` in the [Changelog](CHANGELOG.md).

---

## License

This project is licensed under the terms specified in [LICENSE](LICENSE).
