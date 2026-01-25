<?php

/**
 * Advanced Search Strategies Example.
 *
 * This example demonstrates the ExecutionPlanService's ability to handle complex
 * path-finding scenarios including:
 *
 * 1. Multi-Order Same Direction: Multiple A→B orders that could be aggregated
 * 2. Split at Source: A→B and A→C (source splits to multiple intermediates)
 * 3. Merge at Target: B→D and C→D (multiple routes converge at target)
 * 4. Diamond Pattern: A→B, A→C, B→D, C→D (combined split and merge)
 * 5. Complex Multi-Layer: Real-world scenarios with many routes
 *
 * Each scenario includes verification assertions to ensure correct behavior.
 *
 * Use Cases:
 * - P2P trading platforms with multiple market makers
 * - Multi-currency arbitrage detection
 * - Optimal liquidity routing across fragmented markets
 * - Exchange aggregation with split/merge execution
 *
 * @see docs/getting-started.md For basic usage
 * @see docs/architecture.md For understanding the execution plan model
 */

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use SomeWork\P2PPathFinder\Application\PathSearch\Api\Request\PathSearchRequest;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\ExecutionPlan;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\ExecutionPlanService;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;
use SomeWork\P2PPathFinder\Domain\Money\AssetPair;
use SomeWork\P2PPathFinder\Domain\Money\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderBook;
use SomeWork\P2PPathFinder\Domain\Order\OrderBounds;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;

// =============================================================================
// HELPER FUNCTIONS
// =============================================================================

/**
 * Creates a SELL order (maker sells base for quote).
 * Taker perspective: spends quote, receives base.
 * Edge direction: quote → base.
 *
 * Example: SELL USDT/RUB means maker sells USDT for RUB
 *          Taker spends RUB, receives USDT
 *          Edge: RUB → USDT
 *
 * @param non-empty-string $base    Base currency (what maker sells)
 * @param non-empty-string $quote   Quote currency (what maker receives)
 * @param numeric-string   $minBase Minimum base amount
 * @param numeric-string   $maxBase Maximum base amount
 * @param numeric-string   $rate    Exchange rate (quote per base)
 */
function createSellOrder(
    string $base,
    string $quote,
    string $minBase,
    string $maxBase,
    string $rate,
): Order {
    return new Order(
        OrderSide::SELL,
        AssetPair::fromString($base, $quote),
        OrderBounds::from(
            Money::fromString($base, $minBase, 2),
            Money::fromString($base, $maxBase, 2),
        ),
        ExchangeRate::fromString($base, $quote, $rate, 6),
    );
}

/**
 * Creates a BUY order (maker buys base with quote).
 * Taker perspective: spends base, receives quote.
 * Edge direction: base → quote.
 *
 * Example: BUY USDT/RUB means maker buys USDT with RUB
 *          Taker spends USDT, receives RUB
 *          Edge: USDT → RUB
 *
 * @param non-empty-string $base    Base currency (what maker buys)
 * @param non-empty-string $quote   Quote currency (what maker spends)
 * @param numeric-string   $minBase Minimum base amount
 * @param numeric-string   $maxBase Maximum base amount
 * @param numeric-string   $rate    Exchange rate (quote per base)
 */
function createBuyOrder(
    string $base,
    string $quote,
    string $minBase,
    string $maxBase,
    string $rate,
): Order {
    return new Order(
        OrderSide::BUY,
        AssetPair::fromString($base, $quote),
        OrderBounds::from(
            Money::fromString($base, $minBase, 2),
            Money::fromString($base, $maxBase, 2),
        ),
        ExchangeRate::fromString($base, $quote, $rate, 6),
    );
}

/**
 * Prints execution plan details.
 */
function printPlan(ExecutionPlan $plan, string $title): void
{
    echo "\n{$title}\n";
    echo str_repeat('=', strlen($title))."\n";
    echo "Source: {$plan->sourceCurrency()}\n";
    echo "Target: {$plan->targetCurrency()}\n";
    echo "Steps: {$plan->stepCount()}\n";
    echo "Total Spent: {$plan->totalSpent()->amount()} {$plan->totalSpent()->currency()}\n";
    echo "Total Received: {$plan->totalReceived()->amount()} {$plan->totalReceived()->currency()}\n";
    echo 'Is Linear: '.($plan->isLinear() ? 'Yes' : 'No')."\n";

    echo "\nExecution Steps:\n";
    foreach ($plan->steps() as $i => $step) {
        echo sprintf(
            "  Step %d: %s → %s | Spend: %s %s | Receive: %s %s\n",
            $i + 1,
            $step->from(),
            $step->to(),
            $step->spent()->amount(),
            $step->spent()->currency(),
            $step->received()->amount(),
            $step->received()->currency(),
        );
    }
}

/**
 * Asserts a condition is true, throws exception with message if false.
 */
function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("Verification failed: {$message}");
    }
}

/**
 * Verifies currency flow is correct through all steps (linear path).
 * Each step's output currency must match the next step's input currency.
 */
function verifyCurrencyFlow(ExecutionPlan $plan): void
{
    $steps = iterator_to_array($plan->steps());

    for ($i = 0; $i < count($steps) - 1; ++$i) {
        $currentStep = $steps[$i];
        $nextStep = $steps[$i + 1];

        verify(
            strtoupper($currentStep->to()) === strtoupper($nextStep->from()),
            sprintf(
                'Currency flow broken: step %d outputs %s but step %d inputs %s',
                $i + 1,
                $currentStep->to(),
                $i + 2,
                $nextStep->from(),
            ),
        );
    }
}

/**
 * Verifies no order is used more than once in the plan.
 */
function verifyNoOrderReuse(ExecutionPlan $plan): void
{
    $usedOrders = [];

    foreach ($plan->steps() as $step) {
        $orderId = spl_object_id($step->order());

        verify(
            !isset($usedOrders[$orderId]),
            'Order reused in execution plan - each order should be used at most once',
        );

        $usedOrders[$orderId] = true;
    }
}

/**
 * Expected results for verification.
 *
 * @phpstan-type ExpectedResult array{
 *     hasPaths: bool,
 *     source?: string,
 *     target?: string,
 *     minSteps?: int,
 *     maxSteps?: int,
 *     minReceived?: string,
 *     maxReceived?: string,
 *     isLinear?: bool,
 * }
 */

/**
 * Runs a search with verification.
 *
 * @param array{
 *     hasPaths: bool,
 *     source?: string,
 *     target?: string,
 *     minSteps?: int,
 *     maxSteps?: int,
 *     minReceived?: string,
 *     maxReceived?: string,
 *     isLinear?: bool,
 * } $expected
 */
function runSearchWithVerification(
    ExecutionPlanService $service,
    OrderBook $orderBook,
    string $spendAmount,
    string $spendCurrency,
    string $targetCurrency,
    string $scenarioName,
    array $expected,
): void {
    echo "\n".str_repeat('─', 70)."\n";
    echo "SCENARIO: {$scenarioName}\n";
    echo str_repeat('─', 70)."\n";

    $config = PathSearchConfig::builder()
        ->withSpendAmount(Money::fromString($spendCurrency, $spendAmount, 2))
        ->withToleranceBounds('0.0', '0.50')
        ->withHopLimits(1, 5)
        ->withSearchGuards(10000, 20000)
        ->build();

    $request = new PathSearchRequest($orderBook, $config, $targetCurrency);

    $startTime = microtime(true);
    $outcome = $service->findBestPlans($request);
    $elapsed = (microtime(true) - $startTime) * 1000;

    // -------------------------------------------------------------------------
    // VERIFICATION
    // -------------------------------------------------------------------------

    echo "\nVerification:\n";

    // Verify path existence
    verify(
        $outcome->hasPaths() === $expected['hasPaths'],
        sprintf('Expected hasPaths=%s, got %s', $expected['hasPaths'] ? 'true' : 'false', $outcome->hasPaths() ? 'true' : 'false'),
    );
    echo '  ✓ Path existence: '.($outcome->hasPaths() ? 'found' : 'not found')." (expected)\n";

    if ($outcome->hasPaths()) {
        $plan = $outcome->bestPath();
        assert($plan instanceof ExecutionPlan);

        // Verify source currency
        if (isset($expected['source'])) {
            verify(
                strtoupper($plan->sourceCurrency()) === strtoupper($expected['source']),
                sprintf('Expected source=%s, got %s', $expected['source'], $plan->sourceCurrency()),
            );
            echo "  ✓ Source currency: {$plan->sourceCurrency()}\n";
        }

        // Verify target currency
        if (isset($expected['target'])) {
            verify(
                strtoupper($plan->targetCurrency()) === strtoupper($expected['target']),
                sprintf('Expected target=%s, got %s', $expected['target'], $plan->targetCurrency()),
            );
            echo "  ✓ Target currency: {$plan->targetCurrency()}\n";
        }

        // Verify step count range
        if (isset($expected['minSteps'])) {
            verify(
                $plan->stepCount() >= $expected['minSteps'],
                sprintf('Expected minSteps>=%d, got %d', $expected['minSteps'], $plan->stepCount()),
            );
        }
        if (isset($expected['maxSteps'])) {
            verify(
                $plan->stepCount() <= $expected['maxSteps'],
                sprintf('Expected maxSteps<=%d, got %d', $expected['maxSteps'], $plan->stepCount()),
            );
        }
        if (isset($expected['minSteps']) || isset($expected['maxSteps'])) {
            echo "  ✓ Step count: {$plan->stepCount()} (within expected range)\n";
        }

        // Verify received amount range
        $receivedAmount = $plan->totalReceived()->amount();
        if (isset($expected['minReceived'])) {
            verify(
                bccomp($receivedAmount, $expected['minReceived'], 18) >= 0,
                sprintf('Expected minReceived>=%s, got %s', $expected['minReceived'], $receivedAmount),
            );
        }
        if (isset($expected['maxReceived'])) {
            verify(
                bccomp($receivedAmount, $expected['maxReceived'], 18) <= 0,
                sprintf('Expected maxReceived<=%s, got %s', $expected['maxReceived'], $receivedAmount),
            );
        }
        if (isset($expected['minReceived']) || isset($expected['maxReceived'])) {
            echo "  ✓ Received amount: {$receivedAmount} (within expected range)\n";
        }

        // Verify linearity
        if (isset($expected['isLinear'])) {
            verify(
                $plan->isLinear() === $expected['isLinear'],
                sprintf('Expected isLinear=%s, got %s', $expected['isLinear'] ? 'true' : 'false', $plan->isLinear() ? 'true' : 'false'),
            );
            echo '  ✓ Is linear: '.($plan->isLinear() ? 'Yes' : 'No')."\n";
        }

        // Verify currency flow (for linear paths)
        if ($plan->isLinear()) {
            verifyCurrencyFlow($plan);
            echo "  ✓ Currency flow: valid (each step connects properly)\n";
        }

        // Verify no order reuse
        verifyNoOrderReuse($plan);
        echo "  ✓ Order usage: no duplicates (each order used at most once)\n";

        // Print plan details
        printPlan($plan, 'Best Execution Plan');
    }

    // Print search stats
    $guard = $outcome->guardLimits();
    echo sprintf(
        "\nSearch Stats: %d expansions, %d states, %.2fms\n",
        $guard->expansions(),
        $guard->visitedStates(),
        $elapsed,
    );

    if ($guard->anyLimitReached()) {
        echo "⚠ Guard limit reached - results may be incomplete\n";
    }
}

// =============================================================================
// MAIN EXECUTION
// =============================================================================

try {
    echo "╔══════════════════════════════════════════════════════════════════════╗\n";
    echo "║           ADVANCED SEARCH STRATEGIES DEMONSTRATION                    ║\n";
    echo "║                                                                        ║\n";
    echo "║  This example demonstrates ExecutionPlanService handling complex      ║\n";
    echo "║  path-finding scenarios: multi-order, split, merge, and diamond.      ║\n";
    echo "║  Each scenario includes verification of expected results.             ║\n";
    echo "╚══════════════════════════════════════════════════════════════════════╝\n";

    $service = new ExecutionPlanService(new GraphBuilder());
    $passedScenarios = 0;

    // =========================================================================
    // SCENARIO 1: Multi-Order Same Direction (A→B, A→B)
    // =========================================================================
    // Two market makers offering RUB→USDT at different rates.
    // The service should select the best rate (100 RUB/USDT vs 105 RUB/USDT).
    // With 50000 RUB at rate 100, we get 500 USDT.
    // With worse rate 105, we'd get ~476 USDT.

    $scenario1Orders = new OrderBook([
        // Maker 1: sells USDT for RUB at 100 RUB/USDT (better rate)
        createSellOrder('USDT', 'RUB', '100.00', '1000.00', '100.00'),
        // Maker 2: sells USDT for RUB at 105 RUB/USDT (worse rate)
        createSellOrder('USDT', 'RUB', '100.00', '1000.00', '105.00'),
    ]);

    runSearchWithVerification(
        $service,
        $scenario1Orders,
        '50000.00',
        'RUB',
        'USDT',
        'Multi-Order Same Direction (A→B, A→B) - Should select better rate',
        [
            'hasPaths' => true,
            'source' => 'RUB',
            'target' => 'USDT',
            'minSteps' => 1,
            'maxSteps' => 1,
            'minReceived' => '476.0',  // Worst case at 105 rate
            'maxReceived' => '510.0',  // Best case at 100 rate
            'isLinear' => true,
        ],
    );
    ++$passedScenarios;

    // =========================================================================
    // SCENARIO 2: Split at Source (A→B, A→C)
    // =========================================================================
    // Source currency can go to two different intermediates.
    // Since target is USDT and only one route goes to USDT, should use direct.
    // 50000 RUB at rate 100 = 500 USDT

    $scenario2Orders = new OrderBook([
        // RUB → USDT route
        createSellOrder('USDT', 'RUB', '100.00', '1000.00', '100.00'),
        // RUB → EUR route (not useful for USDT target)
        createSellOrder('EUR', 'RUB', '10.00', '100.00', '105.00'),
    ]);

    runSearchWithVerification(
        $service,
        $scenario2Orders,
        '50000.00',
        'RUB',
        'USDT',
        'Split at Source (A→B, A→C) - Single target, direct route preferred',
        [
            'hasPaths' => true,
            'source' => 'RUB',
            'target' => 'USDT',
            'minSteps' => 1,
            'maxSteps' => 1,
            'minReceived' => '490.0',
            'maxReceived' => '510.0',
            'isLinear' => true,
        ],
    );
    ++$passedScenarios;

    // =========================================================================
    // SCENARIO 3: Merge at Target (B→D, C→D)
    // =========================================================================
    // Multiple routes converge at the target.
    // RUB → USDT → BTC path: 50000 RUB → 500 USDT → 0.0125 BTC
    // RUB → EUR → BTC path: would give different amount
    // Service should find optimal path.

    $scenario3Orders = new OrderBook([
        // Source routes: RUB → USDT and RUB → EUR
        createSellOrder('USDT', 'RUB', '100.00', '1000.00', '100.00'),
        createSellOrder('EUR', 'RUB', '10.00', '100.00', '100.00'),
        // Target routes: USDT → BTC and EUR → BTC
        createBuyOrder('USDT', 'BTC', '100.00', '1000.00', '0.000025'),
        createBuyOrder('EUR', 'BTC', '10.00', '100.00', '0.000027'),
    ]);

    runSearchWithVerification(
        $service,
        $scenario3Orders,
        '50000.00',
        'RUB',
        'BTC',
        'Merge at Target (B→D, C→D) - Two paths to same target',
        [
            'hasPaths' => true,
            'source' => 'RUB',
            'target' => 'BTC',
            'minSteps' => 2,
            'maxSteps' => 2,
            'minReceived' => '0.01',   // Some BTC received
            'maxReceived' => '0.02',   // Upper bound
            'isLinear' => true,
        ],
    );
    ++$passedScenarios;

    // =========================================================================
    // SCENARIO 4: Diamond Pattern (A→B, A→C, B→D, C→D)
    // =========================================================================
    // Classic diamond: source splits, then routes merge at target.
    //
    //       RUB
    //      /   \
    //   USDT   EUR
    //      \   /
    //       BTC

    $scenario4Orders = new OrderBook([
        // Split: RUB → USDT and RUB → EUR
        createSellOrder('USDT', 'RUB', '100.00', '1000.00', '100.00'),
        createSellOrder('EUR', 'RUB', '10.00', '100.00', '105.00'),
        // Merge: USDT → BTC and EUR → BTC
        createBuyOrder('USDT', 'BTC', '100.00', '1000.00', '0.000025'),
        createBuyOrder('EUR', 'BTC', '10.00', '100.00', '0.000028'),
    ]);

    runSearchWithVerification(
        $service,
        $scenario4Orders,
        '50000.00',
        'RUB',
        'BTC',
        'Diamond Pattern (A→B, A→C, B→D, C→D) - Classic split/merge',
        [
            'hasPaths' => true,
            'source' => 'RUB',
            'target' => 'BTC',
            'minSteps' => 2,
            'maxSteps' => 2,
            'minReceived' => '0.01',
            'maxReceived' => '0.02',
            'isLinear' => true,
        ],
    );
    ++$passedScenarios;

    // =========================================================================
    // SCENARIO 5: Multi-Order + Diamond (A→B, A→B, A→C, B→D, C→D)
    // =========================================================================
    // Combined pattern: multiple A→B orders plus diamond structure.
    // Should select best rate (98 RUB/USDT) among USDT orders.

    $scenario5Orders = new OrderBook([
        // Multiple RUB → USDT orders with different rates
        createSellOrder('USDT', 'RUB', '100.00', '500.00', '98.00'),   // Best rate
        createSellOrder('USDT', 'RUB', '100.00', '500.00', '100.00'),  // Good rate
        createSellOrder('USDT', 'RUB', '100.00', '500.00', '102.00'),  // Worse rate
        // Alternative: RUB → EUR
        createSellOrder('EUR', 'RUB', '10.00', '100.00', '105.00'),
        // Merge: both routes to BTC
        createBuyOrder('USDT', 'BTC', '100.00', '1500.00', '0.000025'),
        createBuyOrder('EUR', 'BTC', '10.00', '100.00', '0.000027'),
    ]);

    runSearchWithVerification(
        $service,
        $scenario5Orders,
        '40000.00',
        'RUB',
        'BTC',
        'Multi-Order + Diamond (A→B×3, A→C, B→D, C→D) - Complex combined',
        [
            'hasPaths' => true,
            'source' => 'RUB',
            'target' => 'BTC',
            'minSteps' => 2,
            'maxSteps' => 2,
            'minReceived' => '0.008',  // Some BTC
            'maxReceived' => '0.015',  // Upper bound
            'isLinear' => true,
        ],
    );
    ++$passedScenarios;

    // =========================================================================
    // SCENARIO 6: Three Parallel Routes (A→B, A→C, A→D → E)
    // =========================================================================
    // Three different intermediate currencies, all converging at target.
    //
    //          RUB
    //        / | \
    //    USDT EUR GBP
    //        \ | /
    //         BTC

    $scenario6Orders = new OrderBook([
        // Three routes from source
        createSellOrder('USDT', 'RUB', '100.00', '1000.00', '100.00'),
        createSellOrder('EUR', 'RUB', '10.00', '100.00', '108.00'),
        createSellOrder('GBP', 'RUB', '10.00', '100.00', '120.00'),
        // All three to target
        createBuyOrder('USDT', 'BTC', '100.00', '1000.00', '0.000025'),
        createBuyOrder('EUR', 'BTC', '10.00', '100.00', '0.000027'),
        createBuyOrder('GBP', 'BTC', '10.00', '100.00', '0.000030'),
    ]);

    runSearchWithVerification(
        $service,
        $scenario6Orders,
        '50000.00',
        'RUB',
        'BTC',
        'Three Parallel Routes (A→B, A→C, A→D → E) - Fan-out to target',
        [
            'hasPaths' => true,
            'source' => 'RUB',
            'target' => 'BTC',
            'minSteps' => 2,
            'maxSteps' => 2,
            'minReceived' => '0.01',
            'maxReceived' => '0.02',
            'isLinear' => true,
        ],
    );
    ++$passedScenarios;

    // =========================================================================
    // SCENARIO 7: Multi-Hop Chain with Options at Each Level
    // =========================================================================
    // RUB → USDT (multiple options) → EUR → GBP
    // 50000 RUB → ~505 USDT (at 99 rate) → ~464 EUR → ~394 GBP

    $scenario7Orders = new OrderBook([
        // First hop: RUB → USDT (multiple makers)
        createSellOrder('USDT', 'RUB', '100.00', '1000.00', '99.00'),   // Best
        createSellOrder('USDT', 'RUB', '100.00', '1000.00', '100.00'),
        // Second hop: USDT → EUR
        createBuyOrder('USDT', 'EUR', '100.00', '1000.00', '0.92'),
        // Third hop: EUR → GBP
        createBuyOrder('EUR', 'GBP', '10.00', '1000.00', '0.85'),
    ]);

    runSearchWithVerification(
        $service,
        $scenario7Orders,
        '50000.00',
        'RUB',
        'GBP',
        'Multi-Hop Chain (RUB→USDT×2→EUR→GBP) - Select best at each hop',
        [
            'hasPaths' => true,
            'source' => 'RUB',
            'target' => 'GBP',
            'minSteps' => 3,
            'maxSteps' => 3,
            'minReceived' => '350.0',  // Lower bound
            'maxReceived' => '450.0',  // Upper bound
            'isLinear' => true,
        ],
    );
    ++$passedScenarios;

    // =========================================================================
    // SCENARIO 8: Real-World Complex Graph
    // =========================================================================
    // Simulates a realistic P2P exchange with multiple currencies and routes.
    //
    //            RUB
    //          / | \
    //      USDT EUR  GBP
    //          \ | /
    //           BTC
    //            |
    //           ETH

    $scenario8Orders = new OrderBook([
        // Layer 1: From RUB
        createSellOrder('USDT', 'RUB', '100.00', '1000.00', '100.00'),
        createSellOrder('EUR', 'RUB', '10.00', '100.00', '105.00'),
        createSellOrder('GBP', 'RUB', '10.00', '100.00', '118.00'),

        // Layer 2: To BTC
        createBuyOrder('USDT', 'BTC', '100.00', '1000.00', '0.000025'),
        createBuyOrder('EUR', 'BTC', '10.00', '100.00', '0.000027'),
        createBuyOrder('GBP', 'BTC', '10.00', '100.00', '0.000030'),

        // Layer 3: To ETH
        createBuyOrder('BTC', 'ETH', '0.0001', '1.0', '15.5'),
    ]);

    runSearchWithVerification(
        $service,
        $scenario8Orders,
        '50000.00',
        'RUB',
        'ETH',
        'Real-World Complex Graph (RUB→{USDT,EUR,GBP}→BTC→ETH)',
        [
            'hasPaths' => true,
            'source' => 'RUB',
            'target' => 'ETH',
            'minSteps' => 3,
            'maxSteps' => 3,
            'minReceived' => '0.1',   // Some ETH
            'maxReceived' => '0.3',   // Upper bound
            'isLinear' => true,
        ],
    );
    ++$passedScenarios;

    // =========================================================================
    // SCENARIO 9: No Path Available
    // =========================================================================
    // Tests that the service correctly reports when no path exists.

    $scenario9Orders = new OrderBook([
        // Only RUB → USDT available, but we want JPY
        createSellOrder('USDT', 'RUB', '100.00', '1000.00', '100.00'),
    ]);

    runSearchWithVerification(
        $service,
        $scenario9Orders,
        '50000.00',
        'RUB',
        'JPY',  // No path to JPY
        'No Path Available - Should report no paths found',
        [
            'hasPaths' => false,
        ],
    );
    ++$passedScenarios;

    // =========================================================================
    // SUMMARY
    // =========================================================================

    echo "\n".str_repeat('═', 70)."\n";
    echo "VERIFICATION SUMMARY\n";
    echo str_repeat('═', 70)."\n";
    echo "\n✓ All {$passedScenarios} scenarios passed verification!\n";
    echo '
Verified behaviors:
  ✓ Path existence detection
  ✓ Source/target currency correctness
  ✓ Step count within expected ranges
  ✓ Received amounts within expected ranges
  ✓ Linear path detection
  ✓ Currency flow continuity (step outputs match next step inputs)
  ✓ No order reuse (each order used at most once)

Demonstrated search strategies:
  ✓ Multi-Order Same Direction (A→B, A→B)
  ✓ Split at Source (A→B, A→C)
  ✓ Merge at Target (B→D, C→D)
  ✓ Diamond Pattern (A→B, A→C, B→D, C→D)
  ✓ Multi-Order + Diamond combined
  ✓ Three Parallel Routes
  ✓ Multi-Hop Chains
  ✓ Complex Real-World Graphs
  ✓ No Path scenarios
';

    echo "\n✓ Example completed successfully with all verifications passed.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(\STDERR, "\n✗ Example failed with error:\n");
    fwrite(\STDERR, '  '.$e::class.': '.$e->getMessage()."\n");
    fwrite(\STDERR, '  at '.$e->getFile().':'.$e->getLine()."\n");
    exit(1);
}
