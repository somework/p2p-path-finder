# API Stability Guide

This document defines the public API surface that remains stable across minor and patch releases in the 1.0+ series. Classes and methods marked as `@internal` may change without notice.

## Table of Contents

- [Stability Guarantees](#stability-guarantees)
- [Public API Summary](#public-api-summary)
- [Internal API](#internal-api)
- [Extension Points](#extension-points)
- [Deprecation Policy](#deprecation-policy)

---

## Stability Guarantees

### What is Stable

**Stable** (follows semver):
- Classes, methods, and interfaces marked with `@api` tag
- Public API namespaces (see table below)
- Method signatures and return types
- Object APIs and domain object behavior
- Exception types and hierarchy

**Changes requiring MAJOR version**:
- Removing public classes, methods, or interfaces
- Changing method signatures
- Changing exception types
- Breaking behavioral changes to public APIs

### What Can Change

**May change in MINOR versions**:
- Classes marked with `@internal` tag
- Internal implementation details
- Performance characteristics
- Internal namespaces (Graph, PathFinder internals)

**May change in PATCH versions**:
- Bug fixes (even if they change behavior to match intended design)
- Internal refactoring
- Documentation
- Performance optimizations

---

## Public API Summary

### Namespace Stability Matrix

| Namespace                         | Stability   | Description                  |
|-----------------------------------|-------------|------------------------------|
| `Application\PathSearch\Service\*` | ✅ Public    | Entry point services         |
| `Application\PathSearch\Api\*`     | ✅ Public    | API request/response DTOs    |
| `Application\PathSearch\Config\*`  | ✅ Public    | Configuration builders       |
| `Application\PathSearch\Result\*`  | ✅ Public    | Search results and DTOs      |
| `Domain\Money\*`                   | ✅ Public    | Money and currency objects   |
| `Domain\Order\*`                   | ✅ Public    | Order and order book objects |
| `Domain\Tolerance\*`               | ✅ Public    | Tolerance and precision objects |
| `Exception\**`                     | ✅ Public    | All exceptions               |
| `Application\PathSearch\*`         | ⚠️ Internal | Search algorithm internals   |

### Core Public API Classes

| Category             | Class                     | Purpose                              |
|----------------------|---------------------------|--------------------------------------|
| **Entry Point**      | `PathSearchService`       | Main facade for path finding         |
|                      | `PathSearchRequest`       | Request DTO with order book + config |
| **Configuration**    | `PathSearchConfig`        | Immutable search configuration       |
|                      | `PathSearchConfigBuilder` | Fluent configuration builder         |
|                      | `SearchGuardConfig`       | Guard limit configuration            |
| **Results**          | `SearchOutcome`           | Search results + guard report        |
|                      | `PathResultSet`           | Immutable collection of paths        |
|                      | `PathResult`              | Single path with legs and fees       |
|                      | `PathLeg`                 | Single conversion hop                |
|                      | `SearchGuardReport`       | Guard metrics and breach status      |
| **Order Management** | `OrderBook`               | Order collection with filtering      |
| **Domain**           | `Money`                   | Monetary amount with currency        |
|                      | `ExchangeRate`            | Conversion rate between currencies   |
|                      | `Order`                   | Buy/sell order with bounds           |
|                      | `OrderBounds`             | Min/max amount constraints           |
|                      | `AssetPair`               | Base/quote currency pair             |
| **Exceptions**       | `ExceptionInterface`      | Marker for all library exceptions    |
|                      | `InvalidInput`            | Input validation failures            |
|                      | `GuardLimitExceeded`      | Resource limit reached (opt-in)      |
|                      | `PrecisionViolation`      | Arithmetic precision errors          |

### Extension Points (Interfaces)

| Interface              | Purpose                     | Implement to...              |
|------------------------|-----------------------------|------------------------------|
| `OrderFilterInterface` | Filter orders before search | Create custom order filters  |
| `PathOrderStrategy`    | Control path ranking        | Customize result ordering    |
| `FeePolicy`            | Calculate transaction fees  | Model complex fee structures |

---

## Usage Patterns

### Basic Usage (Always Stable)

```php
use SomeWork\P2PPathFinder\Application\PathSearch\Api\Request\PathSearchRequest;use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;use SomeWork\P2PPathFinder\Application\PathSearch\Service\PathSearchService;use SomeWork\P2PPathFinder\Domain\Money\Money;

// Configuration
$config = PathSearchConfig::builder()
    ->withSpendAmount(Money::fromString('USD', '100.00', 2))
    ->withToleranceBounds('0.00', '0.05')
    ->withHopLimits(1, 4)
    ->build();

// Request
$request = new PathSearchRequest($orderBook, $config, 'BTC');

// Execute
$service = new PathSearchService($graphBuilder);
$outcome = $service->findBestPaths($request);

// Access results
foreach ($outcome->paths() as $path) {
    echo $path->route();
    echo $path->totalSpent()->amount();
    echo $path->totalReceived()->amount();
}

// Check guard limits
if ($outcome->guardLimits()->anyLimitReached()) {
    // Handle partial results
}
```

**Guarantee**: This pattern will work across all 1.x versions.

### Domain Objects (Always Stable)

```php
use SomeWork\P2PPathFinder\Domain\Money\ExchangeRate;use SomeWork\P2PPathFinder\Domain\Money\Money;use SomeWork\P2PPathFinder\Domain\Order\Order;

// Money
$money = Money::fromString('USD', '100.00', 2);
$doubled = $money->multipliedBy('2.0', 2);
$sum = $money->add($other);

// ExchangeRate
$rate = ExchangeRate::fromString('USD', 'EUR', '0.92', 4);
$euros = $rate->convert($usd);

// Order
$order = new Order($side, $assetPair, $bounds, $rate, $feePolicy);
```

**Guarantee**: All domain object constructors and methods are stable.

### Custom Extensions (Interface Stable)

```php
use SomeWork\P2PPathFinder\Domain\Order\Filter\OrderFilterInterface;use SomeWork\P2PPathFinder\Domain\Order\Order;

// Custom filter implementation
class MyCustomFilter implements OrderFilterInterface
{
    public function accepts(Order $order): bool
    {
        return $order->effectiveRate()->rate() >= '1.0';
    }
}

// Use the filter
$filtered = $orderBook->filter(new MyCustomFilter());
```

**Guarantee**: `OrderFilterInterface`, `PathOrderStrategy`, and `FeePolicy` interfaces will not change in 1.x.

---

## Internal API

### Do NOT Depend On These

Classes and namespaces marked `@internal` or in these packages:

**Internal Namespaces**:
- `Application\PathSearch\Model\Graph\*` - Graph construction internals
- `Application\PathSearch\*` (except public API namespaces) - Search algorithm internals
- `Application\Support\*` - Internal utilities

**Why Internal**:
- Implementation details that may change
- Performance optimizations may require API changes
- Not intended for direct consumer use

**Example Internal Classes** (may change without notice):
- `GraphBuilder` - Implementation detail (use `PathFinderService` instead)
- `PathFinder` - Search algorithm (use `PathFinderService` instead)
- `SearchState` - Internal state representation
- `EdgeSegment` - Graph representation detail

### Safe vs Unsafe Dependencies

**✅ Safe** (Public API):

```php


```

**❌ Unsafe** (Internal API):

```php
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\SearchState;

```

---

## Extension Points

### 1. Custom Order Filters

**Interface**: `Application\Filter\OrderFilterInterface`

**Stability**: Public, stable in 1.x

```php
interface OrderFilterInterface
{
    public function accepts(Order $order): bool;
}
```

**Example**:
```php
class MinimumLiquidityFilter implements OrderFilterInterface
{
    public function __construct(private Money $minimumAmount) {}
    
    public function accepts(Order $order): bool
    {
        return $order->bounds()->maximum()->compareTo($this->minimumAmount) >= 0;
    }
}
```

### 2. Custom Path Ordering

**Interface**: `Application\PathSearch\Engine\Ordering\PathOrderStrategy`

**Stability**: Public, stable in 1.x

```php
interface PathOrderStrategy
{
    public function compare(PathOrderKey $left, PathOrderKey $right): int;
}
```

**Example**:
```php
class MinimizeHopsStrategy implements PathOrderStrategy
{
    public function compare(PathOrderKey $left, PathOrderKey $right): int
    {
        return $left->hops() <=> $right->hops();
    }
}
```

### 3. Custom Fee Policies

**Interface**: `Domain\Order\FeePolicy`

**Stability**: Public, stable in 1.x

```php
interface FeePolicy
{
    public function calculate(
        OrderSide $side,
        Money $baseAmount,
        Money $quoteAmount
    ): FeeBreakdown;
    
    public function fingerprint(): string;
}
```

**Example**:
```php
class TieredFeePolicy implements FeePolicy
{
    public function calculate(
        OrderSide $side,
        Money $baseAmount,
        Money $quoteAmount
    ): FeeBreakdown {
        $rate = $quoteAmount->toDecimal()->compareTo('1000') >= 0 
            ? '0.0025'  // 0.25% for >= $1000
            : '0.005';  // 0.5% for < $1000
        
        $fee = $quoteAmount->multiply($rate, $quoteAmount->scale());
        return FeeBreakdown::forQuote($fee);
    }
    
    public function fingerprint(): string
    {
        return 'tiered:0.5%<1k:0.25%>=1k';
    }
}
```

---

## Deprecation Policy

### How Deprecations Work

1. **Announced**: Feature marked `@deprecated` in PHPDoc
2. **Warning**: `E_USER_DEPRECATED` triggered when used
3. **Timeline**: Minimum 1 MINOR version before removal
4. **Removal**: Removed in next MAJOR version

### Deprecation Annotation

```php
/**
 * @deprecated since 1.5.0, use newMethod() instead. Will be removed in 2.0.0.
 */
public function oldMethod(): void
{
    trigger_error(
        'oldMethod() is deprecated since 1.5.0, use newMethod() instead.',
        E_USER_DEPRECATED
    );
    
    // Still works, but shows warning
    $this->newMethod();
}
```

### Detecting Deprecated Usage

**PHPStan**:
```bash
vendor/bin/phpstan analyse  # Reports deprecated usage
```

**PHPUnit**:
```xml
<phpunit failOnDeprecation="true"></phpunit>
```

**Runtime**:
```php
set_error_handler(function ($errno, $errstr) {
    if ($errno === E_USER_DEPRECATED) {
        error_log("Deprecation: $errstr");
    }
});
```

---

---

## Version Compatibility Matrix

| Your Code Uses       | Compatible Library Versions | Notes                         |
|----------------------|-----------------------------|-------------------------------|
| Public API only      | Any 1.x version             | ✅ Fully compatible            |
| Extension interfaces | Any 1.x version             | ✅ Interfaces stable           |
| Internal classes     | Same MINOR version only     | ⚠️ May break in MINOR updates |
| `@internal` classes  | Exact version only          | ❌ No compatibility guarantee  |

### Upgrade Safety

**PATCH upgrades** (1.5.2 → 1.5.3):
- ✅ Always safe for public API
- ⚠️ May affect internal API

**MINOR upgrades** (1.5.x → 1.6.0):
- ✅ Safe for public API
- ✅ New features may be added
- ⚠️ Internal API may change
- ⚠️ Check for deprecation warnings

**MAJOR upgrades** (1.x → 2.0):
- ⚠️ May have breaking changes
- ⚠️ Read UPGRADING.md before upgrading
- ⚠️ Deprecated features removed
- ⚠️ Test thoroughly before deploying

---

## Checking API Stability

### In Your Code

**Safe usage** (public API only):

```php
// ✅ These imports are safe

// ✅ Extension interfaces are safe

```

**Risky usage** (internal API):

```php
// ❌ These imports may break in MINOR versions
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\SearchState;

```

### With PHPStan

PHPStan can detect internal API usage (requires configuration):

```neon
# phpstan.neon
parameters:
    reportMaybesInMethodSignatures: true
    reportMaybesInPropertyPhpDocTypes: true
```

### In Composer

Lock to MAJOR version to avoid breaking changes:

```json
{
    "require": {
        "somework/p2p-path-finder": "^1.0"
    }
}
```

This allows MINOR and PATCH updates (1.0.0 → 1.9.9) but prevents MAJOR updates (2.0.0).

---

## Summary

### Quick Reference

**✅ Safe to use** (public API):
- `PathFinderService`
- `PathSearchConfig` / `PathSearchConfigBuilder`
- `PathSearchRequest`
- `SearchOutcome` / `PathResult` / `PathLeg`
- All domain classes (`Money`, `ExchangeRate`, `Order`, etc.)
- All exceptions (`InvalidInput`, `GuardLimitExceeded`, etc.)
- Extension interfaces (`OrderFilterInterface`, `PathOrderStrategy`, `FeePolicy`)

**⚠️ Avoid** (internal API):
- `GraphBuilder`
- `PathFinder`
- `SearchState`
- Classes marked `@internal`
- Anything in `Application\PathSearch\*` (except public API namespaces)

**📋 Best Practices**:
1. Only import from public API namespaces
2. Use extension interfaces for customization
3. Check for `@deprecated` warnings
4. Lock to `^1.0` in composer.json
5. Read CHANGELOG.md before upgrading
6. Test after MINOR version upgrades

---

## Related Documentation

- [Versioning Policy](releases-and-support.md#semantic-versioning) - SemVer rules and BC breaks
- [API Contracts](api-contracts.md) - Object API specification and usage examples
- [Getting Started Guide](getting-started.md) - Quick start tutorial
- [Architecture Guide](architecture.md) - System design overview
- [Generated API Docs](api/index.md) - Complete PHPDoc reference

---

*For detailed method signatures and parameter documentation, see the [generated API documentation](api/index.md).*
