# p2p-path-finder

A small toolkit for discovering optimal peer-to-peer conversion paths across a set of
orders. The package focuses on deterministic arithmetic, declarative configuration and
clear separation between the domain model and application services.

## Architecture overview

The codebase is intentionally split into two layers:

* **Domain layer** – Contains value objects such as `Money`, `ExchangeRate`, `OrderBounds`
  and domain entities like `Order`. These classes are immutable, validate their input and
  provide a thin abstraction around BCMath so that all monetary calculations have
  predictable precision.
* **Application layer** – Hosts services that orchestrate the domain model. Notable
  components include:
  * `OrderBook` and a small set of reusable `OrderFilterInterface` implementations used to
    prune irrelevant liquidity.
  * `GraphBuilder`, which converts domain orders into a weighted graph representation.
  * `PathFinder`, implementing a tolerance-aware search strategy to pick the best route.
  * `PathFinderService`, a façade that applies filters, builds the search graph and returns
    `PathResult` aggregates complete with `PathLeg` breakdowns.

The separation allows you to extend or replace either layer (e.g. load orders from an API
or swap in a different search algorithm) without leaking implementation details.

## Configuring a path search

`PathSearchConfig` captures the parameters used during graph exploration. You can build it
manually or use the fluent builder:

```php
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

$config = PathSearchConfig::builder()
    ->withSpendAmount(Money::fromString('USD', '100.00', 2))
    ->withToleranceBounds(0.05, 0.10) // -5%/+10% relative tolerance window
    ->withHopLimits(1, 3)             // allow between 1 and 3 conversions
    ->build();
```

The builder enforces presence and validity of each piece of configuration. Internally the
configuration pre-computes minimum/maximum spend amounts derived from the tolerance window,
which are then used when filtering the order book.

## BCMath-based precision

All arithmetic is delegated to `SomeWork\P2PPathFinder\Domain\ValueObject\BcMath`, a thin
wrapper around the BCMath extension. It provides:

* Input validation helpers that fail fast when encountering malformed numeric strings.
* Deterministic rounding that keeps scale under control without leaking trailing digits.
* Utility methods (`add`, `sub`, `mul`, `div`, `comp`) that normalise operands so that
  value objects such as `Money` can work with mixed scales.

By routing every calculation through this helper the library avoids floating-point
rounding drift and guarantees that two identical operations will always yield the same
string representation.

## Quick-start scenarios

Below are two end-to-end examples that showcase the typical workflow. In both snippets the
order book is pre-populated with synthetic orders, but you can plug in any data source.

### Scenario 1 – Buying a target asset directly

```php
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Application\Service\PathFinderService;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\AssetPair;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Domain\ValueObject\OrderBounds;

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
$config = PathSearchConfig::builder()
    ->withSpendAmount(Money::fromString('USD', '100.00', 2))
    ->withToleranceBounds(0.00, 0.01)
    ->withHopLimits(1, 2)
    ->build();

$service = new PathFinderService(new GraphBuilder());
$result = $service->findBestPath($orderBook, $config, 'USDT');
```

The resulting `PathResult` contains a single `PathLeg` reflecting the direct USD→USDT
conversion.

### Scenario 2 – Selling through an intermediate asset with tight tolerance

```php
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Application\Service\PathFinderService;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\AssetPair;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Domain\ValueObject\OrderBounds;

$orderBook = new OrderBook([
    new Order(
        OrderSide::SELL,
        AssetPair::fromString('BTC', 'USDT'),
        OrderBounds::from(
            Money::fromString('BTC', '0.01000000', 8),
            Money::fromString('BTC', '1.00000000', 8),
        ),
        ExchangeRate::fromString('BTC', 'USDT', '63000.00000000', 8),
    ),
    new Order(
        OrderSide::BUY,
        AssetPair::fromString('USDT', 'EUR'),
        OrderBounds::from(
            Money::fromString('USDT', '100.00', 2),
            Money::fromString('USDT', '100000.00', 2),
        ),
        ExchangeRate::fromString('USDT', 'EUR', '0.92', 8),
    ),
]);

$config = PathSearchConfig::builder()
    ->withSpendAmount(Money::fromString('BTC', '0.10000000', 8))
    ->withToleranceBounds(0.00, 0.02)
    ->withHopLimits(2, 3)
    ->build();

$result = (new PathFinderService(new GraphBuilder()))
    ->findBestPath($orderBook, $config, 'EUR');
```

Because the tolerance window is narrow the service will only accept paths that stay close
to the configured BTC spend amount while allowing up to three hops.

Use `PathResultFormatter` to turn the result into machine- or human-friendly output:

```php
$formatter = new PathResultFormatter();
$payload = $formatter->formatMachine($result);
echo $formatter->formatHuman($result);
```

## API documentation

Docblocks are available throughout the public API. To generate browseable documentation
run:

```bash
composer phpdoc
```

The command will populate HTML output under `docs/api/`.

## Running tests and quality checks

```bash
composer phpunit
composer phpstan
composer php-cs-fixer
```

All commands rely on the development dependencies declared in `composer.json`.
