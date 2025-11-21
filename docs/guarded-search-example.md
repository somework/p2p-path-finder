# Guarded search integration

This quick example shows how to configure search guard limits when integrating the library
into an application. The full flow fits in a single script and can be executed in under 15
minutes during onboarding. Run `php examples/guarded-search-example.php` to reproduce the
same scenario locally—the latest deterministic output is recorded in
[`docs/audits/bigdecimal-verification.md`](audits/bigdecimal-verification.md).

> ℹ️  Tolerance inputs and spend calculations follow the canonical policy described in
> [docs/decimal-strategy.md](decimal-strategy.md#canonical-scale-and-rounding-policy). Keep
> residual reporting at the documented scale (18 decimal places) so benchmarking and CI
> comparisons remain reproducible. The domain value objects (`Money`, `ExchangeRate`,
> `DecimalTolerance`, etc.) convert their inputs to `Brick\Math\BigDecimal` immediately, so
> no BCMath extension or manual string math is required in your integration.

```php
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

$orderBook = new OrderBook([
    new Order(
        OrderSide::BUY,
        AssetPair::fromString('USD', 'USDT'),
        OrderBounds::from(
            Money::fromString('USD', '10.00', 2),
            Money::fromString('USD', '2500.00', 2),
        ),
        ExchangeRate::fromString('USD', 'USDT', '1.0001', 6),
    ),
    new Order(
        OrderSide::BUY,
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
$request = new PathSearchRequest($orderBook, $config, 'BTC');
$result = $service->findBestPaths($request);

foreach ($result->paths() as $path) {
    printf(
        "Found path with residual tolerance %s%% and %d segments\n",
        $path->residualTolerancePercentage(),
        count($path->legs()),
    );
}

// The PathResultSet returned by SearchOutcome::paths() is iterable and also exposes
// helper methods like toArray() or jsonSerialize() when you need a plain list.

// When integrating with APIs you can serialise the whole outcome. The structure matches
// ['paths' => $result->paths()->jsonSerialize(), 'guards' => $result->guardLimits()->jsonSerialize()].
$payload = $result->jsonSerialize();

assert(isset($payload['paths'], $payload['guards']));
assert($payload['guards']['limits']['expansions'] >= $payload['guards']['metrics']['expansions']);

$report = $result->guardLimits();
printf(
    "Explored %d/%d states across %d/%d expansions in %.3fms\n",
    $report->visitedStates(),
    $report->visitedStateLimit(),
    $report->expansions(),
    $report->expansionLimit(),
    $report->elapsedMilliseconds(),
);
```

If traversal exhausts any configured guard before returning control to your callback, the
service raises a `GuardLimitExceeded` exception. Catch it at the integration boundary when
you need to downgrade to cached quotes or emit partial telemetry while preserving uptime.

Example output (values will vary by machine):

```
Found path with residual tolerance 0.00% and 2 segments
Explored 3/20000 states across 3/50000 expansions in 5.124ms
```

The `withSearchGuards()` call ensures the traversal halts if either the visited-state or
expansion thresholds are exceeded, providing predictable runtime characteristics even on
dense graphs. The limits are wrapped in an immutable `SearchGuardConfig`, so you can also
pass an optional third argument to impose a millisecond time budget when needed.

## Regression edge cases

The PHPUnit suite exercises a set of deterministic fixtures that capture the sharpest guard
scenarios we have encountered in production. They live in
`tests/Fixture/PathFinderEdgeCaseFixtures.php` and are consumed by
`PathFinderServiceEdgeCaseTest`. The fixtures cover:

- `PathFinderEdgeCaseFixtures::emptyOrderBook()` – asserts that empty books propagate a
  pristine `SearchGuardReport` (all counters zeroed) without leaking partial paths.
- `PathFinderEdgeCaseFixtures::incompatibleBounds()` – models min/max conflicts between hops
  so the service returns an empty result set with untouched guard metadata.
- `PathFinderEdgeCaseFixtures::longGuardLimitedChain()` – forces expansion guard breaches and
  validates both the emitted metadata and the `GuardLimitExceeded` exception pathway.

They run automatically in CI through data providers, ensuring the regression coverage remains
representative as the search heuristics evolve.
