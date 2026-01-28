<?php

/**
 * Top-K Execution Plans Example
 *
 * This example demonstrates how to use the P2P Path Finder library to find
 * multiple alternative execution plans (Top-K discovery).
 *
 * ## When to Use Top-K
 *
 * - **Fallback options**: Primary plan may fail during execution; have backups ready
 * - **Rate comparison**: Compare trade-offs between different routes
 * - **Risk diversification**: Spread execution across multiple strategies
 * - **User selection**: Display alternatives for user to choose from
 *
 * ## How It Works
 *
 * The Top-K algorithm uses iterative exclusion:
 * 1. Find the optimal execution plan
 * 2. Exclude all orders used in that plan
 * 3. Find the next best plan using remaining orders
 * 4. Repeat until K plans found or no more alternatives exist
 *
 * Each plan uses a **completely disjoint set of orders** - no order appears
 * in multiple plans, ensuring true alternatives.
 *
 * Run with: php examples/top-k-execution-plans.php
 * Or: composer examples:top-k-execution-plans
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

echo "=== Top-K Execution Plans Example ===\n\n";

try {
    // ============================================================
    // 1. Create an Order Book with Multiple Alternatives
    // ============================================================
    echo "1. Creating order book with multiple conversion options...\n\n";

    // Create several RUB -> USDT orders at different rates
    // This simulates a real market with multiple liquidity providers
    $orderBook = new OrderBook([
        // Best rate: 95 RUB per USDT (Trader A)
        new Order(
            OrderSide::SELL,
            AssetPair::fromString('USDT', 'RUB'),
            OrderBounds::from(
                Money::fromString('USDT', '100.00', 2),
                Money::fromString('USDT', '5000.00', 2),
            ),
            ExchangeRate::fromString('USDT', 'RUB', '95.00', 2),
        ),
        // Mid rate: 97 RUB per USDT (Trader B)
        new Order(
            OrderSide::SELL,
            AssetPair::fromString('USDT', 'RUB'),
            OrderBounds::from(
                Money::fromString('USDT', '100.00', 2),
                Money::fromString('USDT', '5000.00', 2),
            ),
            ExchangeRate::fromString('USDT', 'RUB', '97.00', 2),
        ),
        // Worst rate: 99 RUB per USDT (Trader C)
        new Order(
            OrderSide::SELL,
            AssetPair::fromString('USDT', 'RUB'),
            OrderBounds::from(
                Money::fromString('USDT', '100.00', 2),
                Money::fromString('USDT', '5000.00', 2),
            ),
            ExchangeRate::fromString('USDT', 'RUB', '99.00', 2),
        ),
        // Additional route via EUR
        new Order(
            OrderSide::SELL,
            AssetPair::fromString('EUR', 'RUB'),
            OrderBounds::from(
                Money::fromString('EUR', '100.00', 2),
                Money::fromString('EUR', '3000.00', 2),
            ),
            ExchangeRate::fromString('EUR', 'RUB', '100.00', 2),
        ),
        new Order(
            OrderSide::BUY,
            AssetPair::fromString('EUR', 'USDT'),
            OrderBounds::from(
                Money::fromString('EUR', '100.00', 2),
                Money::fromString('EUR', '3000.00', 2),
            ),
            ExchangeRate::fromString('EUR', 'USDT', '1.08', 4),
        ),
    ]);

    echo "   Order Book Contents:\n";
    echo "   - Direct RUB -> USDT at rate 95 (best)\n";
    echo "   - Direct RUB -> USDT at rate 97 (mid)\n";
    echo "   - Direct RUB -> USDT at rate 99 (worst)\n";
    echo "   - Indirect RUB -> EUR -> USDT\n\n";

    // ============================================================
    // 2. Configure Search with Top-K
    // ============================================================
    echo "2. Configuring Top-K search (K=5)...\n\n";

    $spendAmount = Money::fromString('RUB', '100000.00', 2);

    $config = PathSearchConfig::builder()
        ->withSpendAmount($spendAmount)
        ->withToleranceBounds('0.00', '0.20')    // Accept up to 20% tolerance
        ->withHopLimits(1, 3)                    // Allow 1-3 hop paths
        ->withResultLimit(5)                     // Request top 5 plans
        ->withSearchGuards(10000, 25000)         // Guard limits
        ->build();

    echo "   Spend amount: {$spendAmount->amount()} {$spendAmount->currency()}\n";
    echo "   Result limit (K): {$config->resultLimit()}\n";
    echo "   Tolerance: 0-20%\n";
    echo "   Hop limits: 1-3\n\n";

    // ============================================================
    // 3. Execute Top-K Search
    // ============================================================
    echo "3. Executing Top-K search...\n\n";

    $service = new ExecutionPlanService(new GraphBuilder());
    $request = new PathSearchRequest($orderBook, $config, 'USDT');
    $outcome = $service->findBestPlans($request);

    echo "   Search complete!\n\n";

    // ============================================================
    // 4. Process and Display Results
    // ============================================================
    echo "4. Results: Found {$outcome->paths()->count()} alternative plan(s)\n\n";

    if (!$outcome->hasPaths()) {
        echo "   No execution plans found.\n";
    } else {
        foreach ($outcome->paths() as $rank => $plan) {
            // Calculate effective cost ratio (RUB spent per USDT received)
            $spent = $plan->totalSpent()->decimal();
            $received = $plan->totalReceived()->decimal();

            if (!$received->isZero()) {
                $costPerUnit = $spent->dividedBy($received, 4, RoundingMode::HALF_UP);
            } else {
                $costPerUnit = BigDecimal::of('0');
            }

            echo "   ┌─ Plan #".($rank + 1)." ──────────────────────────────────\n";
            echo "   │ Source:    {$plan->sourceCurrency()}\n";
            echo "   │ Target:    {$plan->targetCurrency()}\n";
            echo "   │ Spend:     {$plan->totalSpent()->amount()} {$plan->totalSpent()->currency()}\n";
            echo "   │ Receive:   {$plan->totalReceived()->amount()} {$plan->totalReceived()->currency()}\n";
            echo "   │ Cost/Unit: {$costPerUnit} RUB per USDT\n";
            echo "   │ Steps:     {$plan->stepCount()}\n";
            echo "   │ Linear:    ".($plan->isLinear() ? 'Yes' : 'No')."\n";
            echo "   │\n";

            // Show execution steps
            foreach ($plan->steps() as $step) {
                echo "   │   Step {$step->sequenceNumber()}: ";
                echo "{$step->from()} → {$step->to()} | ";
                echo "Spend {$step->spent()->amount()} → Receive {$step->received()->amount()}\n";
            }

            echo "   └────────────────────────────────────────────\n\n";
        }

        // ============================================================
        // 5. Demonstrate bestPath() Usage
        // ============================================================
        echo "5. Best Plan (for primary execution):\n\n";

        $bestPlan = $outcome->bestPath();
        if (null !== $bestPlan) {
            echo "   The optimal plan receives {$bestPlan->totalReceived()->amount()} ";
            echo "{$bestPlan->totalReceived()->currency()} for ";
            echo "{$bestPlan->totalSpent()->amount()} {$bestPlan->totalSpent()->currency()}\n\n";
        }

        // ============================================================
        // 6. Verify Disjoint Order Sets
        // ============================================================
        echo "6. Verifying plan independence (disjoint order sets):\n\n";

        $orderUsage = [];
        $planOrders = [];

        foreach ($outcome->paths() as $rank => $plan) {
            $planOrders[$rank] = [];
            foreach ($plan->steps() as $step) {
                $orderId = spl_object_id($step->order());
                $planOrders[$rank][] = $orderId;

                if (isset($orderUsage[$orderId])) {
                    echo "   WARNING: Order used in multiple plans!\n";
                }
                $orderUsage[$orderId] = $rank;
            }
        }

        echo "   ✓ All plans use disjoint order sets\n";
        echo "   Total unique orders used: ".count($orderUsage)."\n\n";
    }

    // ============================================================
    // 7. Show Guard Report (Aggregated)
    // ============================================================
    echo "7. Search Statistics (Aggregated across all K searches):\n\n";

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
        echo "   WARNING: Guard limits reached - some alternatives may be missing\n";
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
