<?php

/**
 * Basic ExecutionPlanService Example
 *
 * This example demonstrates the recommended way to use the P2P Path Finder library
 * with ExecutionPlanService, which supports split/merge execution plans.
 *
 * Key concepts demonstrated:
 * - Creating an order book
 * - Configuring the search
 * - Using ExecutionPlanService (recommended over deprecated PathSearchService)
 * - Processing ExecutionPlan results
 * - Understanding ExecutionStep sequence numbers
 *
 * Run with: php examples/execution-plan-basic.php
 * Or: composer examples:execution-plan-basic
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

echo "=== Basic ExecutionPlanService Example ===\n\n";

try {
    // ============================================================
    // 1. Create an Order Book
    // ============================================================
    echo "1. Creating order book...\n";

    $orderBook = new OrderBook([
        // Direct USD -> USDT conversion
        new Order(
            OrderSide::BUY,
            AssetPair::fromString('USD', 'USDT'),
            OrderBounds::from(
                Money::fromString('USD', '10.00', 2),
                Money::fromString('USD', '5000.00', 2),
            ),
            ExchangeRate::fromString('USD', 'USDT', '1.0001', 6),
        ),
        // USDT -> BTC conversion
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

    echo "   Created order book with 2 orders\n\n";

    // ============================================================
    // 2. Configure the Search
    // ============================================================
    echo "2. Configuring search...\n";

    $spendAmount = Money::fromString('USD', '1000.00', 2);

    $config = PathSearchConfig::builder()
        ->withSpendAmount($spendAmount)
        ->withToleranceBounds('0.00', '0.10')  // Accept 0-10% tolerance
        ->withHopLimits(1, 3)                  // Allow 1-3 hop paths
        ->withSearchGuards(10000, 25000)       // Guard limits
        ->build();

    echo "   Spend amount: {$spendAmount->amount()} {$spendAmount->currency()}\n";
    echo "   Tolerance: 0-10%\n";
    echo "   Hop limits: 1-3\n\n";

    // ============================================================
    // 3. Run the Search with ExecutionPlanService
    // ============================================================
    echo "3. Running search with ExecutionPlanService...\n";

    $service = new ExecutionPlanService(new GraphBuilder());
    $request = new PathSearchRequest($orderBook, $config, 'BTC');
    $outcome = $service->findBestPlans($request);

    echo "   Search complete!\n\n";

    // ============================================================
    // 4. Process Results
    // ============================================================
    echo "4. Processing results...\n\n";

    if (!$outcome->hasPaths()) {
        echo "   No execution plans found.\n";
    } else {
        echo "   Found {$outcome->paths()->count()} execution plan(s)\n\n";

        foreach ($outcome->paths() as $index => $plan) {
            echo "   --- Plan ".($index + 1)." ---\n";
            echo "   Source: {$plan->sourceCurrency()}\n";
            echo "   Target: {$plan->targetCurrency()}\n";
            echo "   Total Spent: {$plan->totalSpent()->amount()} {$plan->totalSpent()->currency()}\n";
            echo "   Total Received: {$plan->totalReceived()->amount()} {$plan->totalReceived()->currency()}\n";
            echo "   Residual Tolerance: {$plan->residualTolerance()->percentage()}%\n";
            echo "   Is Linear: ".($plan->isLinear() ? 'Yes' : 'No')."\n";
            echo "   Steps: {$plan->stepCount()}\n\n";

            // Process each execution step
            foreach ($plan->steps() as $step) {
                echo "   Step {$step->sequenceNumber()}:\n";
                echo "     {$step->from()} -> {$step->to()}\n";
                echo "     Spent: {$step->spent()->amount()} {$step->spent()->currency()}\n";
                echo "     Received: {$step->received()->amount()} {$step->received()->currency()}\n";

                // Show order details
                $order = $step->order();
                echo "     Order: {$order->assetPair()->base()}/{$order->assetPair()->quote()} ({$order->side()->value})\n";

                // Show fees if any
                $fees = $step->fees();
                if (!$fees->isEmpty()) {
                    echo "     Fees: ";
                    $feeStrings = [];
                    foreach ($fees as $currency => $fee) {
                        $feeStrings[] = "{$fee->amount()} {$currency}";
                    }
                    echo implode(', ', $feeStrings)."\n";
                }
                echo "\n";
            }

            // If plan is linear, show conversion to Path
            if ($plan->isLinear()) {
                $path = $plan->asLinearPath();
                if (null !== $path) {
                    echo "   (Can convert to legacy Path with {$path->hops()->count()} hops)\n\n";
                }
            }
        }
    }

    // ============================================================
    // 5. Show Guard Report
    // ============================================================
    echo "5. Search Statistics:\n";

    $report = $outcome->guardLimits();
    printf(
        "   Visited States: %d / %d\n",
        $report->visitedStates(),
        $report->visitedStateLimit(),
    );
    printf(
        "   Expansions: %d / %d\n",
        $report->expansions(),
        $report->expansionLimit(),
    );
    printf(
        "   Elapsed Time: %.3f ms\n",
        $report->elapsedMilliseconds(),
    );

    if ($report->anyLimitReached()) {
        echo "   WARNING: Guard limits reached - results may be incomplete\n";
    } else {
        echo "   All guard limits OK\n";
    }

    echo "\n=== Example Complete ===\n";

    exit(0); // Success
} catch (Throwable $e) {
    fwrite(\STDERR, "\n✗ Example failed with unexpected error:\n");
    fwrite(\STDERR, '  '.$e::class.': '.$e->getMessage()."\n");
    fwrite(\STDERR, '  at '.$e->getFile().':'.$e->getLine()."\n");
    exit(1); // Failure
}
