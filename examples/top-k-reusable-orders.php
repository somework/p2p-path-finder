<?php

/**
 * Top-K Reusable Orders Example
 *
 * This example demonstrates the order-reusable Top-K mode, where alternative
 * execution plans CAN share orders. This is useful for rate comparison scenarios
 * where only one plan will actually be executed.
 *
 * ## When to Use Reusable Mode
 *
 * - **Rate comparison**: See "what if I aggregate differently" scenarios
 * - **More alternatives**: Get more plan options from limited liquidity
 * - **Strategy exploration**: Explore different execution strategies using same orders
 * - **Single execution**: When you know only ONE plan will actually run
 *
 * ## How It Works
 *
 * The reusable mode uses penalty-based diversification:
 * 1. Find the optimal execution plan
 * 2. Apply capacity penalties to used orders (make them less attractive)
 * 3. Find the next best plan (orders CAN be reused)
 * 4. Detect and skip duplicate plans (same signature or cost)
 * 5. Repeat until K unique plans found
 *
 * Unlike disjoint mode, plans can share orders. Penalties encourage diversity
 * without completely excluding previously used orders.
 *
 * Run with: php examples/top-k-reusable-orders.php
 * Or: composer examples:top-k-reusable-orders
 */

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
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

echo "=== Top-K Reusable Orders Example ===\n\n";

try {
    // ============================================================
    // 1. Create an Order Book with Limited Orders
    // ============================================================
    echo "1. Creating order book with limited conversion options...\n\n";

    // Create only 2 orders - disjoint mode would find at most 2 plans
    // Reusable mode can find more alternatives using the same orders differently
    $orderBook = new OrderBook([
        // High capacity order at good rate
        new Order(
            OrderSide::SELL,
            AssetPair::fromString('USDT', 'RUB'),
            OrderBounds::from(
                Money::fromString('USDT', '100.00', 2),
                Money::fromString('USDT', '10000.00', 2),
            ),
            ExchangeRate::fromString('USDT', 'RUB', '95.00', 2),
        ),
        // Lower capacity order at slightly worse rate
        new Order(
            OrderSide::SELL,
            AssetPair::fromString('USDT', 'RUB'),
            OrderBounds::from(
                Money::fromString('USDT', '100.00', 2),
                Money::fromString('USDT', '5000.00', 2),
            ),
            ExchangeRate::fromString('USDT', 'RUB', '97.00', 2),
        ),
    ]);

    echo "   Order Book Contents:\n";
    echo "   - Order A: SELL USDT/RUB at rate 95 (capacity: 10,000 USDT)\n";
    echo "   - Order B: SELL USDT/RUB at rate 97 (capacity: 5,000 USDT)\n\n";

    $spendAmount = Money::fromString('RUB', '500000.00', 2);
    echo "   Spend amount: {$spendAmount->amount()} {$spendAmount->currency()}\n\n";

    // ============================================================
    // 2. Compare Disjoint vs Reusable Modes
    // ============================================================
    echo "2. Comparing Disjoint vs Reusable modes...\n\n";

    $service = new ExecutionPlanService(new GraphBuilder());

    // ----- Disjoint Mode (default) -----
    echo "   === DISJOINT MODE (default) ===\n\n";

    $disjointConfig = PathSearchConfig::builder()
        ->withSpendAmount($spendAmount)
        ->withToleranceBounds('0.00', '0.20')
        ->withHopLimits(1, 3)
        ->withResultLimit(5)              // Request 5 plans
        ->withDisjointPlans(true)         // Each plan uses different orders
        ->withSearchGuards(10000, 25000)
        ->build();

    $disjointOutcome = $service->findBestPlans(
        new PathSearchRequest($orderBook, $disjointConfig, 'USDT')
    );

    echo "   Plans found: {$disjointOutcome->paths()->count()}\n";
    echo "   (Limited by 2 available orders - each plan needs different orders)\n\n";

    $usedOrdersDisjoint = [];
    foreach ($disjointOutcome->paths() as $rank => $plan) {
        $orderIds = [];
        foreach ($plan->steps() as $step) {
            $orderId = spl_object_id($step->order());
            $orderIds[] = $orderId;
            $usedOrdersDisjoint[$orderId] = true;
        }
        printf(
            "   Plan #%d: Receive %s %s (uses order IDs: %s)\n",
            $rank + 1,
            $plan->totalReceived()->amount(),
            $plan->totalReceived()->currency(),
            implode(', ', $orderIds)
        );
    }
    echo "\n";

    // ----- Reusable Mode -----
    echo "   === REUSABLE MODE (disjointPlans=false) ===\n\n";

    $reusableConfig = PathSearchConfig::builder()
        ->withSpendAmount($spendAmount)
        ->withToleranceBounds('0.00', '0.20')
        ->withHopLimits(1, 3)
        ->withResultLimit(5)              // Request 5 plans
        ->withDisjointPlans(false)        // Plans CAN share orders
        ->withSearchGuards(10000, 25000)
        ->build();

    $reusableOutcome = $service->findBestPlans(
        new PathSearchRequest($orderBook, $reusableConfig, 'USDT')
    );

    echo "   Plans found: {$reusableOutcome->paths()->count()}\n";
    echo "   (Orders can be reused - duplicates are filtered out)\n\n";

    $usedOrdersReusable = [];
    foreach ($reusableOutcome->paths() as $rank => $plan) {
        $orderIds = [];
        foreach ($plan->steps() as $step) {
            $orderId = spl_object_id($step->order());
            $orderIds[] = $orderId;
            $usedOrdersReusable[$orderId] = ($usedOrdersReusable[$orderId] ?? 0) + 1;
        }
        printf(
            "   Plan #%d: Receive %s %s (uses order IDs: %s)\n",
            $rank + 1,
            $plan->totalReceived()->amount(),
            $plan->totalReceived()->currency(),
            implode(', ', $orderIds)
        );
    }
    echo "\n";

    // ============================================================
    // 3. Show Order Reuse Statistics
    // ============================================================
    echo "3. Order Reuse Statistics:\n\n";

    echo "   Disjoint mode: Each order used at most once\n";
    echo "   - Unique orders used: ".count($usedOrdersDisjoint)."\n\n";

    echo "   Reusable mode: Orders can appear in multiple plans\n";
    foreach ($usedOrdersReusable as $orderId => $count) {
        echo "   - Order ID {$orderId}: used in {$count} plan(s)\n";
    }
    echo "\n";

    // ============================================================
    // 4. Detailed Plan Comparison
    // ============================================================
    echo "4. Detailed Plan Analysis:\n\n";

    if ($reusableOutcome->hasPaths()) {
        foreach ($reusableOutcome->paths() as $rank => $plan) {
            $spent = $plan->totalSpent()->decimal();
            $received = $plan->totalReceived()->decimal();

            if (!$received->isZero()) {
                $costPerUnit = $spent->dividedBy($received, 4, RoundingMode::HALF_UP);
            } else {
                $costPerUnit = BigDecimal::of('0');
            }

            echo "   ┌─ Plan #".($rank + 1)." ──────────────────────────────────\n";
            echo "   │ Spend:     {$plan->totalSpent()->amount()} {$plan->totalSpent()->currency()}\n";
            echo "   │ Receive:   {$plan->totalReceived()->amount()} {$plan->totalReceived()->currency()}\n";
            echo "   │ Cost/Unit: {$costPerUnit} RUB per USDT\n";
            echo "   │ Signature: {$plan->signature()}\n";
            echo "   │ Steps:     {$plan->stepCount()}\n";
            echo "   │\n";

            foreach ($plan->steps() as $step) {
                echo "   │   Step {$step->sequenceNumber()}: ";
                echo "{$step->from()} -> {$step->to()} | ";
                echo "Spend {$step->spent()->amount()} -> Receive {$step->received()->amount()}\n";
            }

            echo "   └────────────────────────────────────────────\n\n";
        }
    }

    // ============================================================
    // 5. Use Case: Rate Comparison
    // ============================================================
    echo "5. Use Case Example - Rate Comparison:\n\n";

    echo "   When using reusable mode for rate comparison:\n";
    echo "   - Plans may share the same orders\n";
    echo "   - Each plan represents a different execution strategy\n";
    echo "   - Only ONE plan will actually be executed\n";
    echo "   - Compare rates to find the best option for your needs\n\n";

    $bestPlan = $reusableOutcome->bestPath();
    if (null !== $bestPlan) {
        $spent = $bestPlan->totalSpent()->decimal();
        $received = $bestPlan->totalReceived()->decimal();

        if (!$received->isZero()) {
            $effectiveRate = $spent->dividedBy($received, 4, RoundingMode::HALF_UP);
        } else {
            $effectiveRate = BigDecimal::of('0');
        }

        echo "   Best effective rate: {$effectiveRate} RUB per USDT\n";
        echo "   (Execute this plan to get the best conversion rate)\n\n";
    }

    // ============================================================
    // 6. Summary Table
    // ============================================================
    echo "6. Summary - Disjoint vs Reusable:\n\n";

    echo "   ┌───────────────────┬────────────────┬────────────────┐\n";
    echo "   │ Aspect            │ Disjoint Mode  │ Reusable Mode  │\n";
    echo "   ├───────────────────┼────────────────┼────────────────┤\n";
    printf("   │ Plans Found       │ %-14s │ %-14s │\n",
        $disjointOutcome->paths()->count(),
        $reusableOutcome->paths()->count()
    );
    echo "   │ Order Sharing     │ No (exclusive) │ Yes (allowed)  │\n";
    echo "   │ Independent       │ Yes            │ No             │\n";
    echo "   │ Best For          │ Fallbacks      │ Rate Compare   │\n";
    echo "   └───────────────────┴────────────────┴────────────────┘\n\n";

    // ============================================================
    // 7. Guard Report
    // ============================================================
    echo "7. Search Statistics:\n\n";

    $report = $reusableOutcome->guardLimits();
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

    echo "\n=== Example Complete ===\n";

    exit(0); // Success
} catch (Throwable $e) {
    fwrite(\STDERR, "\n✗ Example failed with unexpected error:\n");
    fwrite(\STDERR, '  '.$e::class.': '.$e->getMessage()."\n");
    fwrite(\STDERR, '  at '.$e->getFile().':'.$e->getLine()."\n");
    exit(1); // Failure
}
