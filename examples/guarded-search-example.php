<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

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

try {
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
        ->withSearchGuards(20000, 50000)
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

    $payload = $result->jsonSerialize();
    assert(isset($payload['guards']));

    $report = $result->guardLimits();
    printf(
        "Explored %d/%d states across %d/%d expansions in %.3fms\n",
        $report->visitedStates(),
        $report->visitedStateLimit(),
        $report->expansions(),
        $report->expansionLimit(),
        $report->elapsedMilliseconds(),
    );

    exit(0); // Success
} catch (\Throwable $e) {
    fwrite(STDERR, "✗ Example failed with unexpected error:\n");
    fwrite(STDERR, "  " . get_class($e) . ": " . $e->getMessage() . "\n");
    fwrite(STDERR, "  at " . $e->getFile() . ":" . $e->getLine() . "\n");
    exit(1); // Failure
}
