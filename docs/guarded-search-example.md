# Guarded search integration

This quick example shows how to configure search guard limits when integrating the library
into an application. The full flow fits in a single script and can be executed in under 15
minutes during onboarding.

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

$orderBook = new OrderBook([
    new Order(
        OrderSide::SELL,
        AssetPair::fromString('USD', 'USDT'),
        OrderBounds::from(
            Money::fromString('USD', '10.00', 2),
            Money::fromString('USD', '2500.00', 2),
        ),
        ExchangeRate::fromString('USD', 'USDT', '1.0001', 6),
    ),
    new Order(
        OrderSide::SELL,
        AssetPair::fromString('USDT', 'BTC'),
        OrderBounds::from(
            Money::fromString('USDT', '100.00', 2),
            Money::fromString('USDT', '10000.00', 2),
        ),
        ExchangeRate::fromString('USDT', 'BTC', '0.000031', 8),
    ),
]);

$config = PathSearchConfig::builder()
    ->withSpendAmount(Money::fromString('USD', '100.00', 2))
    ->withToleranceBounds('0.01', '0.05')
    ->withHopLimits(1, 3)
    ->withSearchGuards(20000, 50000) // visited states, expansions
    ->build();

$service = new PathFinderService(new GraphBuilder());
$result = $service->findBestPaths($orderBook, $config, 'BTC');

foreach ($result->paths() as $path) {
    printf("Found path with residual tolerance %s and %d segments\n", $path->residualTolerance(), count($path->legs()));
}
```

The `withSearchGuards()` call ensures the traversal halts if either the visited-state or
expansion thresholds are exceeded, providing predictable runtime characteristics even on
dense graphs.
