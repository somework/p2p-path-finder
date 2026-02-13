<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

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

    $service = new ExecutionPlanService(new GraphBuilder());
    $request = new PathSearchRequest($orderBook, $config, 'BTC');
    $result = $service->findBestPlans($request);

    foreach ($result->paths() as $plan) {
        printf(
            "Found execution plan with residual tolerance %s%% and %d steps\n",
            $plan->residualTolerance()->percentage(),
            $plan->stepCount(),
        );
    }

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
} catch (Throwable $e) {
    fwrite(\STDERR, "✗ Example failed with unexpected error:\n");
    fwrite(\STDERR, '  '.$e::class.': '.$e->getMessage()."\n");
    fwrite(\STDERR, '  at '.$e->getFile().':'.$e->getLine()."\n");
    exit(1); // Failure
}
