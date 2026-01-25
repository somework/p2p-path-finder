<?php

/**
 * ExecutionPlan Split/Merge Example
 *
 * This example demonstrates advanced execution plan capabilities including:
 * - Multi-order same direction (multiple orders for same conversion)
 * - Split execution (input split across parallel routes)
 * - Merge execution (routes converging at target)
 * - Diamond patterns (combined split and merge)
 *
 * These patterns are only available with ExecutionPlanService (not PathSearchService).
 *
 * Run with: php examples/execution-plan-split-merge.php
 * Or: composer examples:execution-plan-split-merge
 */

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

echo "=== ExecutionPlan Split/Merge Example ===\n\n";

try {
    // ============================================================
    // Scenario 1: Multi-Order Same Direction
    // ============================================================
    echo "--- Scenario 1: Multi-Order Same Direction ---\n\n";
    echo "Multiple market makers offer USD->BTC at different rates.\n";
    echo "ExecutionPlanService selects the best rate.\n\n";

    $multiOrderBook = new OrderBook([
        // Market Maker 1: USD -> BTC at rate 0.000033
        new Order(
            OrderSide::BUY,
            AssetPair::fromString('USD', 'BTC'),
            OrderBounds::from(
                Money::fromString('USD', '100.00', 2),
                Money::fromString('USD', '5000.00', 2),
            ),
            ExchangeRate::fromString('USD', 'BTC', '0.000033', 8),
        ),
        // Market Maker 2: USD -> BTC at rate 0.000032 (slightly worse)
        new Order(
            OrderSide::BUY,
            AssetPair::fromString('USD', 'BTC'),
            OrderBounds::from(
                Money::fromString('USD', '100.00', 2),
                Money::fromString('USD', '5000.00', 2),
            ),
            ExchangeRate::fromString('USD', 'BTC', '0.000032', 8),
        ),
        // Market Maker 3: USD -> BTC at rate 0.000034 (best rate!)
        new Order(
            OrderSide::BUY,
            AssetPair::fromString('USD', 'BTC'),
            OrderBounds::from(
                Money::fromString('USD', '100.00', 2),
                Money::fromString('USD', '3000.00', 2),
            ),
            ExchangeRate::fromString('USD', 'BTC', '0.000034', 8),
        ),
    ]);

    runScenario($multiOrderBook, 'USD', 'BTC', '1000.00');

    // ============================================================
    // Scenario 2: Split at Source (Parallel Routes)
    // ============================================================
    echo "\n--- Scenario 2: Split at Source ---\n\n";
    echo "USD can be converted via EUR or GBP routes.\n";
    echo "The algorithm may split input across both paths.\n\n";

    $splitSourceBook = new OrderBook([
        // Route 1: USD -> EUR
        new Order(
            OrderSide::BUY,
            AssetPair::fromString('USD', 'EUR'),
            OrderBounds::from(
                Money::fromString('USD', '100.00', 2),
                Money::fromString('USD', '5000.00', 2),
            ),
            ExchangeRate::fromString('USD', 'EUR', '0.92', 4),
        ),
        // Route 2: USD -> GBP
        new Order(
            OrderSide::BUY,
            AssetPair::fromString('USD', 'GBP'),
            OrderBounds::from(
                Money::fromString('USD', '100.00', 2),
                Money::fromString('USD', '5000.00', 2),
            ),
            ExchangeRate::fromString('USD', 'GBP', '0.79', 4),
        ),
        // EUR -> BTC
        new Order(
            OrderSide::BUY,
            AssetPair::fromString('EUR', 'BTC'),
            OrderBounds::from(
                Money::fromString('EUR', '100.00', 2),
                Money::fromString('EUR', '5000.00', 2),
            ),
            ExchangeRate::fromString('EUR', 'BTC', '0.000036', 8),
        ),
        // GBP -> BTC
        new Order(
            OrderSide::BUY,
            AssetPair::fromString('GBP', 'BTC'),
            OrderBounds::from(
                Money::fromString('GBP', '100.00', 2),
                Money::fromString('GBP', '5000.00', 2),
            ),
            ExchangeRate::fromString('GBP', 'BTC', '0.000042', 8),
        ),
    ]);

    runScenario($splitSourceBook, 'USD', 'BTC', '2000.00');

    // ============================================================
    // Scenario 3: Diamond Pattern (Split + Merge)
    // ============================================================
    echo "\n--- Scenario 3: Diamond Pattern ---\n\n";
    echo "Complete diamond: USD splits to EUR/GBP, both merge to BTC.\n\n";
    echo "Topology:\n";
    echo "          ┌───────┐\n";
    echo "     ┌───>│  EUR  │───┐\n";
    echo "     │    └───────┘   │\n";
    echo "┌────┴───┐         ┌──▼───┐\n";
    echo "│  USD   │         │  BTC │\n";
    echo "└────┬───┘         └──▲───┘\n";
    echo "     │    ┌───────┐   │\n";
    echo "     └───>│  GBP  │───┘\n";
    echo "          └───────┘\n\n";

    // Same order book as Scenario 2 demonstrates diamond pattern
    runScenario($splitSourceBook, 'USD', 'BTC', '3000.00');

    // ============================================================
    // Scenario 4: Checking Linearity
    // ============================================================
    echo "\n--- Scenario 4: Checking Plan Linearity ---\n\n";
    echo "Demonstrating isLinear() and asLinearPath() for migration.\n\n";

    $linearBook = new OrderBook([
        // Simple linear: USD -> USDT -> BTC
        new Order(
            OrderSide::BUY,
            AssetPair::fromString('USD', 'USDT'),
            OrderBounds::from(
                Money::fromString('USD', '10.00', 2),
                Money::fromString('USD', '10000.00', 2),
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
        ->withSpendAmount(Money::fromString('USD', '500.00', 2))
        ->withToleranceBounds('0.00', '0.10')
        ->withHopLimits(1, 4)
        ->withSearchGuards(10000, 25000)
        ->build();

    $service = new ExecutionPlanService(new GraphBuilder());
    $request = new PathSearchRequest($linearBook, $config, 'BTC');
    $outcome = $service->findBestPlans($request);

    if ($outcome->hasPaths()) {
        $plan = $outcome->paths()->first();

        echo "Plan Linearity: ".($plan->isLinear() ? 'LINEAR' : 'NON-LINEAR')."\n\n";

        if ($plan->isLinear()) {
            echo "This plan can be converted to legacy Path format:\n\n";

            $path = $plan->asLinearPath();
            if (null !== $path) {
                echo "Legacy Path:\n";
                echo "  Total Spent: {$path->totalSpent()->amount()} {$path->totalSpent()->currency()}\n";
                echo "  Total Received: {$path->totalReceived()->amount()} {$path->totalReceived()->currency()}\n";
                echo "  Hops: {$path->hops()->count()}\n\n";

                foreach ($path->hops() as $index => $hop) {
                    echo "  Hop ".($index + 1).": {$hop->from()} -> {$hop->to()}\n";
                    echo "    Spent: {$hop->spent()->amount()} {$hop->spent()->currency()}\n";
                    echo "    Received: {$hop->received()->amount()} {$hop->received()->currency()}\n";
                }
            }
        } else {
            echo "This plan has splits/merges and cannot be converted to Path.\n";
            echo "Use ExecutionPlan::steps() to process execution steps.\n";
        }
    }

    echo "\n=== Example Complete ===\n";

    exit(0); // Success
} catch (Throwable $e) {
    fwrite(\STDERR, "\n✗ Example failed with unexpected error:\n");
    fwrite(\STDERR, '  '.$e::class.': '.$e->getMessage()."\n");
    fwrite(\STDERR, '  at '.$e->getFile().':'.$e->getLine()."\n");
    exit(1); // Failure
}

/**
 * Helper function to run a scenario and display results.
 */
function runScenario(OrderBook $orderBook, string $source, string $target, string $amount): void
{
    $config = PathSearchConfig::builder()
        ->withSpendAmount(Money::fromString($source, $amount, 2))
        ->withToleranceBounds('0.00', '0.10')
        ->withHopLimits(1, 4)
        ->withSearchGuards(10000, 25000)
        ->build();

    $service = new ExecutionPlanService(new GraphBuilder());
    $request = new PathSearchRequest($orderBook, $config, $target);
    $outcome = $service->findBestPlans($request);

    if (!$outcome->hasPaths()) {
        echo "No execution plan found.\n";

        return;
    }

    $plan = $outcome->paths()->first();

    echo "Execution Plan:\n";
    echo "  Source: {$plan->sourceCurrency()}\n";
    echo "  Target: {$plan->targetCurrency()}\n";
    echo "  Spend: {$plan->totalSpent()->amount()} {$plan->totalSpent()->currency()}\n";
    echo "  Receive: {$plan->totalReceived()->amount()} {$plan->totalReceived()->currency()}\n";
    echo "  Is Linear: ".($plan->isLinear() ? 'Yes' : 'No')."\n";
    echo "  Steps: {$plan->stepCount()}\n\n";

    echo "Execution Steps:\n";
    foreach ($plan->steps() as $step) {
        printf(
            "  [%d] %s -> %s: Spend %s %s, Receive %s %s\n",
            $step->sequenceNumber(),
            $step->from(),
            $step->to(),
            $step->spent()->amount(),
            $step->spent()->currency(),
            $step->received()->amount(),
            $step->received()->currency(),
        );
    }

    // Show guard report
    $report = $outcome->guardLimits();
    printf(
        "\nSearch Stats: %d expansions, %.2fms\n",
        $report->expansions(),
        $report->elapsedMilliseconds(),
    );
}
