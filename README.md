# p2p-path-finder

[![Tests](https://img.shields.io/github/actions/workflow/status/somework/p2p-path-finder/tests.yml?branch=main&label=Tests)](https://github.com/somework/p2p-path-finder/actions/workflows/tests.yml)
[![Quality](https://img.shields.io/github/actions/workflow/status/somework/p2p-path-finder/quality.yml?branch=main&label=Quality)](https://github.com/somework/p2p-path-finder/actions/workflows/quality.yml)

A small toolkit for discovering optimal peer-to-peer conversion paths across a set of
orders. The package focuses on deterministic arithmetic, declarative configuration and
clear separation between the domain model and application services.

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Extension Points](#extension-points)
- [Common Patterns](#common-patterns)
- [Architecture Overview](#architecture-overview)
- [API Documentation](#api-documentation)
- [Configuring a Path Search](#configuring-a-path-search)
- [Memory Usage](#memory-usage)
- [Exception Handling](#exception-handling)
- [Performance Benchmarks](#performance-benchmarks)
- [Testing and Quality](#testing-and-quality)
- [Documentation](#documentation)
- [Contributing](#contributing)

---

## Requirements

* **PHP 8.2 or newer** with the standard extensions flagged by `composer check-platform-reqs`
  enabled: `ext-ctype`, `ext-date`, `ext-dom`, `ext-filter`, `ext-hash`, `ext-iconv`,
  `ext-json`, `ext-libxml`, `ext-mbstring` (or `symfony/polyfill-mbstring`), `ext-openssl`,
  `ext-pcre`, `ext-phar`, `ext-reflection`, `ext-simplexml`, `ext-spl`, `ext-tokenizer`,
  `ext-xml`, and `ext-xmlwriter`. Decimal math is handled entirely by
  [`brick/math`](https://github.com/brick/math), so `ext-bcmath` is no longer required.
* **[Composer](https://getcomposer.org/) 2.x** to install dependencies.

See [docs/local-development.md](docs/local-development.md) for platform validation tips.

---

## Installation

```bash
composer require somework/p2p-path-finder
```

---

## Quick Start

### Your First Path Search

Here's a minimal example to find the best path from USD to BTC:

```php
<?php

require 'vendor/autoload.php';

use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Application\Service\PathFinderService;
use SomeWork\P2PPathFinder\Application\Service\PathSearchRequest;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\AssetPair;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Domain\ValueObject\OrderBounds;

// 1. Create an order book
$order = new Order(
    OrderSide::SELL,
    AssetPair::fromString('USD', 'USDT'),
    OrderBounds::from(
        Money::fromString('USD', '10.00', 2),
        Money::fromString('USD', '1000.00', 2),
    ),
    ExchangeRate::fromString('USD', 'USDT', '1.0000', 4),
);

$orderBook = new OrderBook([$order]);

// 2. Configure the search
$config = PathSearchConfig::builder()
    ->withSpendAmount(Money::fromString('USD', '100.00', 2))
    ->withToleranceBounds('0.00', '0.01')
    ->withHopLimits(1, 2)
    ->withResultLimit(3)
    ->build();

// 3. Run the search
$service = new PathFinderService(new GraphBuilder());
$request = new PathSearchRequest($orderBook, $config, "USDT");
$outcome = $service->findBestPaths($request);

// 4. Use the results
foreach ($outcome->paths() as $path) {
    echo "Route: {$path->route()}\n";
    echo "Spend: {$path->totalSpent()->amount()} {$path->totalSpent()->currency()}\n";
    echo "Receive: {$path->totalReceived()->amount()} {$path->totalReceived()->currency()}\n";
}
```

### Multi-Hop Path Example

Finding paths through intermediary currencies:

```php
// Create order book with bridge currency
$orderBook = new OrderBook([
    // USD → USDT
    new Order(
        OrderSide::SELL,
        AssetPair::fromString('BTC', 'USDT'),
        OrderBounds::from(
            Money::fromString('BTC', '0.01000000', 8),
            Money::fromString('BTC', '1.00000000', 8),
        ),
        ExchangeRate::fromString('BTC', 'USDT', '63000.00', 2),
    ),
    // USDT → EUR
    new Order(
        OrderSide::BUY,
        AssetPair::fromString('USDT', 'EUR'),
        OrderBounds::from(
            Money::fromString('USDT', '100.00', 2),
            Money::fromString('USDT', '100000.00', 2),
        ),
        ExchangeRate::fromString('USDT', 'EUR', '0.92', 2),
    ),
]);

$config = PathSearchConfig::builder()
    ->withSpendAmount(Money::fromString('BTC', '0.10000000', 8))
    ->withToleranceBounds('0.00', '0.02')
    ->withHopLimits(2, 3)  // Allow 2-3 hop paths
    ->build();

$request = new PathSearchRequest($orderBook, $config, 'EUR');
$outcome = $service->findBestPaths($request);

// Get top 2 paths
$topTwo = $outcome->paths()->slice(0, 2);
```

**Next Steps:**
- **New to the library?** Read the [Getting Started Guide](docs/getting-started.md)
- **Having issues?** Check the [Troubleshooting Guide](docs/troubleshooting.md)
- **Need API details?** See [API Documentation](#api-documentation)

**Explore More Examples:**

All examples in the [examples/](examples/) directory are runnable and production-ready:

```bash
# Run all examples
composer examples

# Run specific examples
composer examples:custom-order-filter
composer examples:error-handling
composer examples:performance-optimization
```

See [examples/README.md](examples/README.md) for complete documentation of all examples.

---

## Extension Points

The library provides several extension points for customization:

### Custom Order Filters

Filter orders before searching to improve performance:

```php
use SomeWork\P2PPathFinder\Application\Filter\OrderFilterInterface;
use SomeWork\P2PPathFinder\Domain\Order\Order;

class MyCustomFilter implements OrderFilterInterface
{
    public function accepts(Order $order): bool
    {
        // Custom filtering logic
        return $order->effectiveRate()->rate() >= '1.0';
    }
}

// Use the filter
$filtered = $orderBook->filter(new MyCustomFilter());
```

**See**: [`examples/custom-order-filter.php`](examples/custom-order-filter.php) for complete example

### Custom Path Ordering Strategies

Control how paths are ranked and ordered:

```php
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathOrderStrategy;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathOrderKey;

class MinimizeHopsStrategy implements PathOrderStrategy
{
    public function compare(PathOrderKey $left, PathOrderKey $right): int
    {
        // Prioritize fewer hops over cost
        $hopComparison = $left->hops() <=> $right->hops();
        if (0 !== $hopComparison) {
            return $hopComparison;
        }
        
        // Then by cost
        return $left->cost()->compare($right->cost(), 18);
    }
}

// Use the custom strategy
$service = new PathFinderService(
    new GraphBuilder(),
    orderingStrategy: new MinimizeHopsStrategy()
);
```

**See**: [`examples/custom-ordering-strategy.php`](examples/custom-ordering-strategy.php) for complete example

### Custom Fee Policies

Implement complex fee structures:

```php
use SomeWork\P2PPathFinder\Domain\Order\FeePolicy;
use SomeWork\P2PPathFinder\Domain\Order\FeeBreakdown;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

class TieredFeePolicy implements FeePolicy
{
    public function calculate(
        OrderSide $side,
        Money $baseAmount,
        Money $quoteAmount
    ): FeeBreakdown {
        // Tiered fee: 0.5% for < $1000, 0.25% for >= $1000
        $amount = $quoteAmount->toDecimal();
        $rate = $amount->compareTo('1000') >= 0 ? '0.0025' : '0.005';
        
        $fee = $quoteAmount->multiply($rate, $quoteAmount->scale());
        return FeeBreakdown::forQuote($fee);
    }
    
    public function fingerprint(): string
    {
        return 'tiered:0.5%<1000:0.25%>=1000';
    }
}

// Add to order
$order = new Order(
    OrderSide::SELL,
    $assetPair,
    $bounds,
    $rate,
    new TieredFeePolicy()
);
```

**See**: [`examples/custom-fee-policy.php`](examples/custom-fee-policy.php) for complete example

---

## Common Patterns

### Pattern 1: Pre-Filtering for Performance

**Problem**: Large order books (1000+ orders) slow down search.

**Solution**: Pre-filter orders before searching:

```php
use SomeWork\P2PPathFinder\Application\Filter\MinimumAmountFilter;
use SomeWork\P2PPathFinder\Application\Filter\MaximumAmountFilter;
use SomeWork\P2PPathFinder\Application\Filter\ToleranceWindowFilter;

// 1. Define filters
$minFilter = new MinimumAmountFilter(
    Money::fromString('BTC', '0.01', 8)
);
$maxFilter = new MaximumAmountFilter(
    Money::fromString('BTC', '10.0', 8)
);
$referenceRate = ExchangeRate::fromString('BTC', 'USD', '30000', 2);
$toleranceFilter = new ToleranceWindowFilter($referenceRate, '0.10');

// 2. Apply filters
$filteredOrders = $orderBook->filter($minFilter, $maxFilter, $toleranceFilter);
$filteredBook = new OrderBook(iterator_to_array($filteredOrders));

// 3. Search on filtered book
$request = new PathSearchRequest($filteredBook, $config, 'BTC');
$outcome = $service->findBestPaths($request);
```

**Impact**: 30-70% reduction in search time and memory usage.

---

### Pattern 2: Setting Appropriate Guard Limits

**Problem**: Need to balance search thoroughness with performance.

**Solution**: Choose guard limits based on workload:

```php
use SomeWork\P2PPathFinder\Application\Config\SearchGuardConfig;

// Latency-sensitive (APIs, < 50ms target)
$strictGuards = SearchGuardConfig::strict()
    ->withMaxExpansions(5000)
    ->withMaxVisitedStates(10000)
    ->withTimeBudget(50);  // 50ms

// Background processing (< 500ms tolerance)
$relaxedGuards = SearchGuardConfig::strict()
    ->withMaxExpansions(100000)
    ->withMaxVisitedStates(100000)
    ->withTimeBudget(500);  // 500ms

$config = PathSearchConfig::builder()
    ->withSpendAmount($amount)
    ->withToleranceBounds('0.0', '0.05')
    ->withHopLimits(1, 4)
    ->withSearchGuardConfig($strictGuards)
    ->build();
```

**Guidelines**:

| Use Case | Max Expansions | Max States | Time Budget |
|----------|----------------|------------|-------------|
| Real-time API | 5k | 10k | 50ms |
| User-facing | 25k | 25k | 200ms |
| Background | 100k | 100k | 500ms |
| Batch analytics | 250k | 250k | 2000ms |

---

### Pattern 3: Interpreting Search Outcomes

**Problem**: Need to understand why search found few/no paths or hit limits.

**Solution**: Inspect guard reports for diagnostics:

```php
$outcome = $service->findBestPaths($request);

// 1. Check if paths were found
if ($outcome->paths()->isEmpty()) {
    // No paths found - check guard report
    $report = $outcome->guardLimits();
    
    if ($report->expansions() < 100) {
        // Very few expansions - likely order book issue
        error_log("Order book may not support this request");
    } elseif ($report->anyLimitReached()) {
        // Search was limited - consider relaxing guards
        error_log("Search hit guard limits - try widening constraints");
    } else {
        // No viable paths exist
        error_log("No paths satisfy the given constraints");
    }
}

// 2. Monitor search performance
$report = $outcome->guardLimits();
echo "Expansions: {$report->expansions()} / {$report->expansionLimit()}\n";
echo "Visited states: {$report->visitedStates()} / {$report->visitedStateLimit()}\n";
echo "Time: {$report->elapsedMilliseconds()}ms";

if ($report->timeBudgetReached()) {
    echo " (time budget exceeded)\n";
}
```

---

### Pattern 4: Error Handling

**Problem**: Need to handle various error scenarios gracefully.

**Solution**: Use structured exception handling:

```php
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\GuardLimitExceeded;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;
use SomeWork\P2PPathFinder\Exception\ExceptionInterface;

try {
    $outcome = $service->findBestPaths($request);
    
    // Check for guard limits (metadata mode)
    if ($outcome->guardLimits()->anyLimitReached()) {
        // Log but continue with partial results
        error_log("Search hit guard limits, results may be incomplete");
    }
    
    // Process results...
    
} catch (InvalidInput $e) {
    // Invalid configuration or input data
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input: ' . $e->getMessage()]);
    
} catch (GuardLimitExceeded $e) {
    // Guard limit hit (exception mode)
    $report = $e->getReport();
    http_response_code(503);
    echo json_encode([
        'error' => 'Search limit exceeded',
        'report' => $report->jsonSerialize()
    ]);
    
} catch (PrecisionViolation $e) {
    // Precision error (rare)
    http_response_code(400);
    echo json_encode(['error' => 'Precision error: ' . $e->getMessage()]);
    
} catch (ExceptionInterface $e) {
    // Any other library exception
    http_response_code(500);
    echo json_encode(['error' => 'Search failed: ' . $e->getMessage()]);
}
```

**See**: [Exception Handling Guide](docs/exceptions.md) for comprehensive error handling strategies

---

### Pattern 5: Monitoring Search Performance

**Problem**: Need to track and optimize search performance.

**Solution**: Use guard reports for metrics:

```php
$outcome = $service->findBestPaths($request);
$report = $outcome->guardLimits();

// Log metrics for monitoring
$metrics = [
    'paths_found' => $outcome->paths()->count(),
    'expansions' => $report->expansions(),
    'visited_states' => $report->visitedStates(),
    'elapsed_ms' => $report->elapsedMilliseconds(),
    'limits_reached' => $report->anyLimitReached(),
];

// Send to monitoring system (e.g., Prometheus, DataDog, CloudWatch)
Logger::info('path_search_completed', $metrics);

// Set alerts
if ($report->elapsedMilliseconds() > 100) {
    Logger::warning('slow_path_search', $metrics);
}

if ($report->anyLimitReached()) {
    Logger::warning('guard_limit_reached', $metrics);
}
```

---

## Architecture Overview

> **📖 For a comprehensive architectural guide with diagrams and design patterns, see [docs/architecture.md](docs/architecture.md)**

The codebase is intentionally split into two layers:

* **Domain layer** – Contains value objects such as `Money`, `ExchangeRate`, `OrderBounds`
  and domain entities like `Order`. These classes are immutable, validate their input and
  store their normalized amounts as `Brick\Math\BigDecimal` instances, only converting
  back to numeric strings when serializing so that every public API continues to expose
  canonical `numeric-string` payloads.
* **Application layer** – Hosts services that orchestrate the domain model. Notable
  components include:
  * `OrderBook` and a small set of reusable `OrderFilterInterface` implementations used to
    prune irrelevant liquidity.
  * `GraphBuilder`, which converts domain orders into a weighted graph representation.
  * `PathFinderService`, a facade that applies filters, builds the search graph and returns
    `PathResult` aggregates complete with `PathLeg` breakdowns. It is backed by an internal
    search engine that implements the tolerance-aware route discovery logic without being
    part of the supported surface.

The internal path finder accepts tolerance values exclusively as decimal strings. Supplying
numeric-string tolerances (for example `'0.9999999999999999'`) preserves the full
precision of the input without depending on floating-point formatting. Internally those
strings are converted to `BigDecimal` objects and normalized to 18 decimal places before
calculating the amplifier used by the search heuristic, ensuring the tolerance stays
lossless throughout the search.

The separation allows you to extend or replace either layer (e.g. load orders from an API
or swap in a different search algorithm) without leaking implementation details.

### Public API Surface

The package intentionally keeps its entry points compact:

* `PathSearchRequest` is the mandatory DTO passed to
  `PathFinderService::findBestPaths()`. It normalises the target asset, derives spend
  constraints and ensures callers supply all dependencies required to launch a search.
* `PathFinderService` orchestrates filtering, graph construction and search. It is the
  primary facade exposed to consumers integrating the library into their own
  applications.
* `PathSearchConfig` represents the declarative inputs accepted by the search engine. The
  builder surfaced via `PathSearchConfig::builder()` is part of the supported API and
  allows consumers to construct validated configurations fluently.
* `SearchOutcome::paths()` returns a `PathResultSet`, an immutable collection that
  provides iteration, slicing and `jsonSerialize()`/`toArray()` helpers so you can pipe the
  results straight into response DTOs or JSON encoders.

Support services that exist only to back the facade (e.g. `OrderSpendAnalyzer`, `LegMaterializer`, 
`ToleranceEvaluator`) are marked with `@internal` annotations and should not be depended upon 
directly by userland code.

---

## API Documentation

Complete API documentation is available in the following guides:

* **[API Stability Guide](docs/api-stability.md)** – Comprehensive reference documenting
  the stable public API surface, including all classes, interfaces, methods, and their
  contracts.
* **[API Contracts (JSON Serialization)](docs/api-contracts.md)** – Detailed
  specification of the JSON serialization format for all public result objects.
* **[Domain Model Invariants](docs/domain-invariants.md)** – Complete specification of
  all domain invariants enforced by value objects and entities.

The `@api` annotations in the source code mark the definitive public API surface that
will follow semantic versioning guarantees in 1.0+. Generated API documentation is also
available in [`docs/api/index.md`](docs/api/index.md), created by running
`php bin/generate-phpdoc.php`.

---

## Configuring a Path Search

`PathSearchConfig` captures the parameters used during graph exploration. Build the
configuration fluently, wrap it in a `PathSearchRequest` and iterate the resulting
`PathResultSet` and guard report:

```php
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

$config = PathSearchConfig::builder()
    ->withSpendAmount(Money::fromString('USD', '100.00', 2))
    ->withToleranceBounds('0.05', '0.10')  // 5-10% tolerance
    ->withHopLimits(1, 3)  // 1-3 hops
    ->withSearchGuards(10000, 25000)  // visited states, expansions
    ->withSearchTimeBudget(50)  // 50ms max
    ->build();

$request = new PathSearchRequest($orderBook, $config, 'BTC');
$outcome = $service->findBestPaths($request);
```

**Key Configuration Options**:

| Method | Description | Example |
|--------|-------------|---------|
| `withSpendAmount()` | Amount to spend | `Money::fromString('USD', '100.00', 2)` |
| `withToleranceBounds()` | Min/max tolerance (0-1) | `'0.05', '0.10'` (5-10%) |
| `withHopLimits()` | Min/max path length | `1, 3` (1-3 hops) |
| `withResultLimit()` | Max paths to return (topK) | `5` (default) |
| `withSearchGuards()` | Visited states, expansions | `10000, 25000` |
| `withSearchTimeBudget()` | Max time in ms | `50` |
| `withGuardLimitException()` | Throw on guard breach | (no args) |

See [docs/guarded-search-example.md](docs/guarded-search-example.md) for complete examples.

---

## Memory Usage

Path search memory scales predictably with order book size and hop depth. 

**Quick Reference**:

| Order Book Size | Typical Peak Memory | Recommended Guards |
|-----------------|---------------------|-------------------|
| 100 orders      | 8-15 MB            | 10k states, 25k expansions |
| 1,000 orders    | 12-30 MB           | 50k states, 100k expansions |
| 10,000 orders   | 50-150 MB          | 100k states, 200k expansions |

**Memory Optimization**:

1. **Pre-filter order books** (30-70% reduction)
2. **Use conservative guard limits**
3. **Keep resultLimit low** (1-10)
4. **Limit hop depth** (4-6 hops)
5. **Monitor metrics** via `SearchGuardReport`

See [docs/memory-characteristics.md](docs/memory-characteristics.md) for comprehensive analysis.

---

## Exception Handling

The library ships with domain-specific exceptions:

* **`ExceptionInterface`** – Marker implemented by all library exceptions (catch-all)
* **`InvalidInput`** – Configuration or input validation failure
* **`PrecisionViolation`** – Arithmetic precision cannot be maintained
* **`GuardLimitExceeded`** – Search guard threshold reached (opt-in exception mode)
* **`InfeasiblePath`** – No route satisfies constraints

**Example**:

```php
use SomeWork\P2PPathFinder\Exception\ExceptionInterface;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\GuardLimitExceeded;

try {
    $outcome = $service->findBestPaths($request);
    
    // Check guard limits (metadata mode)
    if ($outcome->guardLimits()->anyLimitReached()) {
        // Handle partial results
    }
    
} catch (InvalidInput $e) {
    // Invalid configuration
} catch (GuardLimitExceeded $e) {
    // Guard limit exceeded (exception mode)
    $report = $e->getReport();
} catch (ExceptionInterface $e) {
    // Any other library exception
}
```

See [docs/exceptions.md](docs/exceptions.md) for comprehensive exception handling guide.

---

## Performance Benchmarks

Path search performance is tracked with [PhpBench](https://phpbench.readthedocs.io/).

**Latest reference numbers** (PHP 8.3, Ubuntu 22.04, Xeon vCPU):

| Scenario (orders)      | Mean (ms) | Peak memory | KPI target (mean) |
|------------------------|-----------|-------------|-------------------|
| k-best-n1e2 (100)      | 25.5      | 8.3 MB      | ≤ 210 ms          |
| k-best-n1e3 (1,000)    | 216.3     | 12.8 MB     | ≤ 2.0 s           |
| k-best-n1e4 (10,000)   | 2,154.7   | 59.1 MB     | ≤ 20 s            |

> ✅  **Performance Update (2025-11-21):** The BigDecimal migration delivered exceptional
> performance improvements: **85-87% faster runtime** compared to the BCMath baseline.

**Run benchmarks locally**:

```bash
php -d memory_limit=-1 -d xdebug.mode=off vendor/bin/phpbench run \
    --config=phpbench.json \
    --ref=baseline \
    --progress=plain
```

---

## Testing and Quality

**Run all tests**:

```bash
composer phpunit
```

**Run static analysis**:

```bash
composer phpstan  # Includes custom decimal arithmetic rules
composer psalm
```

**Run code style checks**:

```bash
composer php-cs-fixer
```

**Full quality check**:

```bash
composer check  # PHPStan + Psalm + CS Fixer (dry-run)
```

**Mutation testing**:

```bash
INFECTION=1 XDEBUG_MODE=coverage vendor/bin/infection
```

All commands rely on development dependencies declared in `composer.json`.

---

## Documentation

### Getting Started

- **[Getting Started Guide](docs/getting-started.md)** – Step-by-step tutorial with complete working examples
- **[Troubleshooting Guide](docs/troubleshooting.md)** – Common issues and solutions

### API Reference

- **[API Stability Guide](docs/api-stability.md)** – Public API surface and stability guarantees
- **[API Contracts](docs/api-contracts.md)** – JSON serialization format specification
- **[Generated API Docs](docs/api/index.md)** – Complete PHPDoc reference

### Domain and Architecture

- **[Architecture Guide](docs/architecture.md)** – Comprehensive architectural overview with diagrams
- **[Domain Invariants](docs/domain-invariants.md)** – Value object constraints and validation rules
- **[Decimal Strategy](docs/decimal-strategy.md)** – Arbitrary precision arithmetic policy
- **[Memory Characteristics](docs/memory-characteristics.md)** – Memory usage and optimization

### Error Handling

- **[Exception Handling Guide](docs/exceptions.md)** – Exception hierarchy and catch strategies

### Examples

- **[Guarded Search Example](docs/guarded-search-example.md)** – Complete walkthrough with guard rails
- **[Custom Fee Policy](examples/custom-fee-policy.php)** – Implementing custom fee structures
- **[Custom Order Filter](examples/custom-order-filter.php)** – Custom filtering logic
- **[Custom Ordering Strategy](examples/custom-ordering-strategy.php)** – Custom path ranking

### Development

- **[Local Development](docs/local-development.md)** – Setup and platform validation
- **[Contributing Guide](CONTRIBUTING.md)** – How to contribute
- **[Changelog](CHANGELOG.md)** – Version history

### Versioning and Releases

- **[Versioning Policy](docs/versioning.md)** – Semantic versioning, BC breaks, and deprecation policies
- **[Release Process](docs/release-process.md)** – How releases are created and published
- **[Support Policy](docs/support.md)** – PHP/library version support and EOL timelines
- **[Upgrading Guide](UPGRADING.md)** – Step-by-step migration guides for major versions
- **[Changelog](CHANGELOG.md)** – Detailed version history and changes

---

## Contributing

We welcome contributions! Please read:

- **[Contributing Guide](CONTRIBUTING.md)** – Guidelines for issues and pull requests
- **[Code of Conduct](CODE_OF_CONDUCT.md)** – Community expectations
- **[Security Policy](SECURITY.md)** – Responsible vulnerability disclosure

Track progress toward the `1.0.0-rc` milestone in the [Changelog](CHANGELOG.md).

---

## License

This project is licensed under the terms specified in [LICENSE](LICENSE).

