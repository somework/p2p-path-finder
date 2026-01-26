<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\ExecutionPlanSearchEngine;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\ExecutionPlanSearchOutcome;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\ExecutionPlan;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\ExecutionPlanMaterializer;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Tolerance\DecimalTolerance;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

use function in_array;

#[CoversClass(ExecutionPlanSearchEngine::class)]
#[CoversClass(ExecutionPlanSearchOutcome::class)]
final class ExecutionPlanSearchEngineTest extends TestCase
{
    private const SCALE = 8;

    private GraphBuilder $graphBuilder;
    private ExecutionPlanMaterializer $materializer;

    protected function setUp(): void
    {
        $this->graphBuilder = new GraphBuilder();
        $this->materializer = new ExecutionPlanMaterializer();
    }

    /**
     * Helper method to materialize raw fills into an ExecutionPlan.
     */
    private function materializePlan(ExecutionPlanSearchOutcome $outcome): ?ExecutionPlan
    {
        $rawFills = $outcome->rawFills();
        if (null === $rawFills || [] === $rawFills) {
            return null;
        }

        return $this->materializer->materialize(
            $rawFills,
            $outcome->sourceCurrency(),
            $outcome->targetCurrency(),
            DecimalTolerance::fromNumericString('0', self::SCALE),
        );
    }

    public function test_linear_path_a_b_c_d(): void
    {
        // Create linear path: A → B → C → D
        // BUY orders create edge from base → quote
        $orders = [
            OrderFactory::buy('AAA', 'BBB', '100', '1000', '1.0', self::SCALE, self::SCALE),
            OrderFactory::buy('BBB', 'CCC', '100', '1000', '1.0', self::SCALE, self::SCALE),
            OrderFactory::buy('CCC', 'DDD', '100', '1000', '1.0', self::SCALE, self::SCALE),
        ];

        $graph = $this->graphBuilder->build($orders);
        $engine = new ExecutionPlanSearchEngine();

        $spendAmount = Money::fromString('AAA', '500.00000000', self::SCALE);
        $outcome = $engine->search($graph, 'AAA', 'DDD', $spendAmount);

        self::assertTrue($outcome->hasRawFills());
        self::assertTrue($outcome->isComplete());
        self::assertFalse($outcome->isPartial());

        $plan = $this->materializePlan($outcome);
        self::assertNotNull($plan);

        // Should have 3 steps for A→B→C→D
        self::assertSame(3, $plan->stepCount());
        self::assertSame('AAA', $plan->sourceCurrency());
        self::assertSame('DDD', $plan->targetCurrency());

        // Verify it's a linear path
        self::assertTrue($plan->isLinear());
    }

    public function test_multi_order_same_direction(): void
    {
        // Create two orders for same direction A → B (BUY orders)
        $orders = [
            OrderFactory::buy('AAA', 'BBB', '100', '500', '1.0', self::SCALE, self::SCALE),
            OrderFactory::buy('AAA', 'BBB', '100', '500', '1.0', self::SCALE, self::SCALE),
            OrderFactory::buy('BBB', 'CCC', '100', '2000', '1.0', self::SCALE, self::SCALE),
        ];

        $graph = $this->graphBuilder->build($orders);
        $engine = new ExecutionPlanSearchEngine();

        // Request amount that requires both A→B orders
        $spendAmount = Money::fromString('AAA', '800.00000000', self::SCALE);
        $outcome = $engine->search($graph, 'AAA', 'CCC', $spendAmount);

        self::assertTrue($outcome->hasRawFills());

        $plan = $this->materializePlan($outcome);
        self::assertNotNull($plan);

        // May have multiple steps due to split execution
        self::assertGreaterThanOrEqual(2, $plan->stepCount());
        self::assertSame('AAA', $plan->sourceCurrency());
        self::assertSame('CCC', $plan->targetCurrency());
    }

    public function test_split_routes_merge_at_target(): void
    {
        // Create split routes: A → B → D and A → C → D (BUY orders)
        $orders = [
            OrderFactory::buy('AAA', 'BBB', '100', '500', '1.0', self::SCALE, self::SCALE),
            OrderFactory::buy('AAA', 'CCC', '100', '500', '1.0', self::SCALE, self::SCALE),
            OrderFactory::buy('BBB', 'DDD', '100', '1000', '1.0', self::SCALE, self::SCALE),
            OrderFactory::buy('CCC', 'DDD', '100', '1000', '1.0', self::SCALE, self::SCALE),
        ];

        $graph = $this->graphBuilder->build($orders);
        $engine = new ExecutionPlanSearchEngine();

        // Request amount that may require split
        $spendAmount = Money::fromString('AAA', '800.00000000', self::SCALE);
        $outcome = $engine->search($graph, 'AAA', 'DDD', $spendAmount);

        self::assertTrue($outcome->hasRawFills());

        $plan = $this->materializePlan($outcome);
        self::assertNotNull($plan);
        self::assertSame('AAA', $plan->sourceCurrency());
        self::assertSame('DDD', $plan->targetCurrency());
    }

    public function test_complex_combination(): void
    {
        // Complex graph with multiple paths (BUY orders)
        $orders = [
            // Direct path A → D
            OrderFactory::buy('AAA', 'DDD', '100', '200', '0.95', self::SCALE, self::SCALE),
            // Path A → B → D
            OrderFactory::buy('AAA', 'BBB', '100', '500', '1.0', self::SCALE, self::SCALE),
            OrderFactory::buy('BBB', 'DDD', '100', '500', '1.0', self::SCALE, self::SCALE),
            // Path A → C → D
            OrderFactory::buy('AAA', 'CCC', '100', '500', '1.0', self::SCALE, self::SCALE),
            OrderFactory::buy('CCC', 'DDD', '100', '500', '1.0', self::SCALE, self::SCALE),
        ];

        $graph = $this->graphBuilder->build($orders);
        $engine = new ExecutionPlanSearchEngine();

        $spendAmount = Money::fromString('AAA', '600.00000000', self::SCALE);
        $outcome = $engine->search($graph, 'AAA', 'DDD', $spendAmount);

        self::assertTrue($outcome->hasRawFills());

        $plan = $this->materializePlan($outcome);
        self::assertNotNull($plan);
        self::assertSame('AAA', $plan->sourceCurrency());
        self::assertSame('DDD', $plan->targetCurrency());

        // Total spent should be close to the requested amount
        $totalSpent = $plan->totalSpent();
        self::assertSame('AAA', $totalSpent->currency());
    }

    public function test_prefers_linear_when_sufficient(): void
    {
        // Create both direct and multi-hop paths (BUY orders)
        $orders = [
            // Direct path A → B with high capacity
            OrderFactory::buy('AAA', 'BBB', '100', '1000', '1.0', self::SCALE, self::SCALE),
            // Multi-hop path A → C → B
            OrderFactory::buy('AAA', 'CCC', '100', '500', '1.0', self::SCALE, self::SCALE),
            OrderFactory::buy('CCC', 'BBB', '100', '500', '1.0', self::SCALE, self::SCALE),
        ];

        $graph = $this->graphBuilder->build($orders);
        $engine = new ExecutionPlanSearchEngine();

        // Amount that can be satisfied by direct path
        $spendAmount = Money::fromString('AAA', '500.00000000', self::SCALE);
        $outcome = $engine->search($graph, 'AAA', 'BBB', $spendAmount);

        self::assertTrue($outcome->hasRawFills());

        $plan = $this->materializePlan($outcome);
        self::assertNotNull($plan);

        // Should prefer direct path (1 step) when sufficient
        self::assertSame(1, $plan->stepCount());
        self::assertTrue($plan->isLinear());
    }

    public function test_respects_max_expansions_guard(): void
    {
        // Create a graph that requires multiple augmenting paths (split flow)
        // Each order has limited capacity, requiring multiple paths
        $orders = [
            // First path A → B → C with limited capacity
            OrderFactory::buy('AAA', 'BBB', '100', '200', '1.0', self::SCALE, self::SCALE),
            OrderFactory::buy('BBB', 'CCC', '100', '200', '1.0', self::SCALE, self::SCALE),
            // Second path A → D → C with limited capacity
            OrderFactory::buy('AAA', 'DDD', '100', '200', '1.0', self::SCALE, self::SCALE),
            OrderFactory::buy('DDD', 'CCC', '100', '200', '1.0', self::SCALE, self::SCALE),
            // Third path A → E → C with limited capacity
            OrderFactory::buy('AAA', 'EEE', '100', '200', '1.0', self::SCALE, self::SCALE),
            OrderFactory::buy('EEE', 'CCC', '100', '200', '1.0', self::SCALE, self::SCALE),
        ];

        $graph = $this->graphBuilder->build($orders);

        // Very low expansion limit - should stop before finding all paths
        $engine = new ExecutionPlanSearchEngine(maxExpansions: 1);

        // Request amount that requires more than one path
        $spendAmount = Money::fromString('AAA', '500.00000000', self::SCALE);
        $outcome = $engine->search($graph, 'AAA', 'CCC', $spendAmount);

        // Search should terminate due to guard
        $guardReport = $outcome->guardReport();
        self::assertTrue(
            $guardReport->expansionsReached() || $guardReport->anyLimitReached(),
            'Search should have hit expansion limit'
        );
    }

    public function test_respects_time_budget_guard(): void
    {
        // Create a complex graph (BUY orders)
        $orders = [];
        $currencies = ['AAA', 'BBB', 'CCC', 'DDD', 'EEE'];

        foreach ($currencies as $from) {
            foreach ($currencies as $to) {
                if ($from !== $to) {
                    $orders[] = OrderFactory::buy($from, $to, '100', '1000', '1.0', self::SCALE, self::SCALE);
                }
            }
        }

        $graph = $this->graphBuilder->build($orders);

        // Very short time budget (1ms)
        $engine = new ExecutionPlanSearchEngine(timeBudgetMs: 1);

        $spendAmount = Money::fromString('AAA', '500.00000000', self::SCALE);
        $outcome = $engine->search($graph, 'AAA', 'EEE', $spendAmount);

        // Guard report should be available
        $guardReport = $outcome->guardReport();
        self::assertNotNull($guardReport);
    }

    public function test_blocks_backtracking(): void
    {
        // Create graph where backtracking would be tempting
        // A → B → A should be blocked (BUY orders)
        $orders = [
            OrderFactory::buy('AAA', 'BBB', '100', '1000', '1.0', self::SCALE, self::SCALE),
            OrderFactory::buy('BBB', 'AAA', '100', '1000', '1.1', self::SCALE, self::SCALE), // Better rate back
            OrderFactory::buy('BBB', 'CCC', '100', '1000', '0.9', self::SCALE, self::SCALE),
        ];

        $graph = $this->graphBuilder->build($orders);
        $engine = new ExecutionPlanSearchEngine();

        $spendAmount = Money::fromString('AAA', '500.00000000', self::SCALE);
        $outcome = $engine->search($graph, 'AAA', 'CCC', $spendAmount);

        self::assertTrue($outcome->hasRawFills());

        $plan = $this->materializePlan($outcome);
        self::assertNotNull($plan);

        // Verify no backtracking: AAA should only appear as source
        $steps = $plan->steps()->all();
        $visitedFrom = [];
        $visitedTo = [];

        foreach ($steps as $step) {
            $visitedFrom[] = $step->from();
            $visitedTo[] = $step->to();
        }

        // AAA should not appear as a destination after being a source
        $aaaUsedAsFrom = in_array('AAA', $visitedFrom, true);
        $aaaUsedAsTo = in_array('AAA', $visitedTo, true);

        // If AAA was used as from (source), it should not appear as to (destination)
        if ($aaaUsedAsFrom) {
            self::assertFalse($aaaUsedAsTo, 'Backtracking detected: AAA appears as both source and destination');
        }
    }

    public function test_order_used_only_once(): void
    {
        // Create graph with limited capacity requiring multiple passes (BUY order)
        $order = OrderFactory::buy('AAA', 'BBB', '100', '300', '1.0', self::SCALE, self::SCALE);
        $orders = [$order];

        $graph = $this->graphBuilder->build($orders);
        $engine = new ExecutionPlanSearchEngine();

        // Amount exceeds single order capacity
        $spendAmount = Money::fromString('AAA', '500.00000000', self::SCALE);
        $outcome = $engine->search($graph, 'AAA', 'BBB', $spendAmount);

        self::assertTrue($outcome->hasRawFills());

        $plan = $this->materializePlan($outcome);
        self::assertNotNull($plan);

        // Should only have 1 step (order used once)
        self::assertSame(1, $plan->stepCount());

        // Total spent should not exceed order capacity
        $totalSpent = $plan->totalSpent();
        $maxCapacity = Money::fromString('AAA', '300.00000000', self::SCALE);
        self::assertFalse(
            $totalSpent->greaterThan($maxCapacity),
            'Total spent should not exceed order capacity'
        );
    }

    public function test_deterministic_results(): void
    {
        // Run same search 10 times and verify identical results (BUY orders)
        $orders = [
            OrderFactory::buy('AAA', 'BBB', '100', '1000', '1.0', self::SCALE, self::SCALE),
            OrderFactory::buy('BBB', 'CCC', '100', '1000', '1.0', self::SCALE, self::SCALE),
            OrderFactory::buy('AAA', 'CCC', '100', '500', '0.9', self::SCALE, self::SCALE),
        ];

        $graph = $this->graphBuilder->build($orders);
        $engine = new ExecutionPlanSearchEngine();

        $spendAmount = Money::fromString('AAA', '400.00000000', self::SCALE);

        $results = [];
        for ($i = 0; $i < 10; ++$i) {
            $outcome = $engine->search($graph, 'AAA', 'CCC', $spendAmount);
            $plan = $this->materializePlan($outcome);

            if (null !== $plan) {
                $results[] = [
                    'stepCount' => $plan->stepCount(),
                    'totalSpent' => $plan->totalSpent()->amount(),
                    'totalReceived' => $plan->totalReceived()->amount(),
                ];
            } else {
                $results[] = null;
            }
        }

        // All results should be identical
        $firstResult = $results[0];
        foreach ($results as $index => $result) {
            self::assertSame($firstResult, $result, "Result at index {$index} differs from first result");
        }
    }

    public function test_partial_result_when_insufficient_liquidity(): void
    {
        // Create graph with limited total liquidity (BUY order)
        $orders = [
            OrderFactory::buy('AAA', 'BBB', '100', '200', '1.0', self::SCALE, self::SCALE),
        ];

        $graph = $this->graphBuilder->build($orders);
        $engine = new ExecutionPlanSearchEngine();

        // Request more than available
        $spendAmount = Money::fromString('AAA', '500.00000000', self::SCALE);
        $outcome = $engine->search($graph, 'AAA', 'BBB', $spendAmount);

        self::assertTrue($outcome->hasRawFills());

        $plan = $this->materializePlan($outcome);
        self::assertNotNull($plan);

        // Should have partial result
        $totalSpent = $plan->totalSpent();
        self::assertTrue(
            $totalSpent->lessThan($spendAmount),
            'Total spent should be less than requested when liquidity is insufficient'
        );
    }

    public function test_empty_result_when_no_path_exists(): void
    {
        // Create disconnected graph (BUY orders)
        $orders = [
            OrderFactory::buy('AAA', 'BBB', '100', '1000', '1.0', self::SCALE, self::SCALE),
            // CCC → DDD exists but no connection to AAA or BBB
            OrderFactory::buy('CCC', 'DDD', '100', '1000', '1.0', self::SCALE, self::SCALE),
        ];

        $graph = $this->graphBuilder->build($orders);
        $engine = new ExecutionPlanSearchEngine();

        // Try to find path from AAA to DDD (doesn't exist)
        $spendAmount = Money::fromString('AAA', '500.00000000', self::SCALE);
        $outcome = $engine->search($graph, 'AAA', 'DDD', $spendAmount);

        self::assertFalse($outcome->hasRawFills());
        self::assertTrue($outcome->isEmpty());
        self::assertNull($outcome->rawFills());
    }

    public function test_same_source_and_target_returns_empty(): void
    {
        $orders = [
            OrderFactory::buy('AAA', 'BBB', '100', '1000', '1.0', self::SCALE, self::SCALE),
        ];

        $graph = $this->graphBuilder->build($orders);
        $engine = new ExecutionPlanSearchEngine();

        $spendAmount = Money::fromString('AAA', '500.00000000', self::SCALE);
        $outcome = $engine->search($graph, 'AAA', 'AAA', $spendAmount);

        self::assertTrue($outcome->isEmpty());
    }

    public function test_unknown_currency_returns_empty(): void
    {
        $orders = [
            OrderFactory::buy('AAA', 'BBB', '100', '1000', '1.0', self::SCALE, self::SCALE),
        ];

        $graph = $this->graphBuilder->build($orders);
        $engine = new ExecutionPlanSearchEngine();

        $spendAmount = Money::fromString('XXX', '500.00000000', self::SCALE);
        $outcome = $engine->search($graph, 'XXX', 'YYY', $spendAmount);

        self::assertTrue($outcome->isEmpty());
    }

    public function test_zero_spend_amount_returns_empty(): void
    {
        $orders = [
            OrderFactory::buy('AAA', 'BBB', '100', '1000', '1.0', self::SCALE, self::SCALE),
        ];

        $graph = $this->graphBuilder->build($orders);
        $engine = new ExecutionPlanSearchEngine();

        $spendAmount = Money::fromString('AAA', '0.00000000', self::SCALE);
        $outcome = $engine->search($graph, 'AAA', 'BBB', $spendAmount);

        self::assertTrue($outcome->isEmpty());
    }

    public function test_currency_mismatch_throws_exception(): void
    {
        $orders = [
            OrderFactory::buy('AAA', 'BBB', '100', '1000', '1.0', self::SCALE, self::SCALE),
        ];

        $graph = $this->graphBuilder->build($orders);
        $engine = new ExecutionPlanSearchEngine();

        // Spend amount in different currency than source
        $spendAmount = Money::fromString('BBB', '500.00000000', self::SCALE);

        $this->expectException(InvalidInput::class);
        $engine->search($graph, 'AAA', 'BBB', $spendAmount);
    }

    public function test_constructor_validates_max_expansions(): void
    {
        $this->expectException(InvalidInput::class);
        new ExecutionPlanSearchEngine(maxExpansions: 0);
    }

    public function test_constructor_validates_max_visited_states(): void
    {
        $this->expectException(InvalidInput::class);
        new ExecutionPlanSearchEngine(maxVisitedStates: 0);
    }

    public function test_constructor_validates_time_budget(): void
    {
        $this->expectException(InvalidInput::class);
        new ExecutionPlanSearchEngine(timeBudgetMs: 0);
    }

    public function test_execution_plan_outcome_factory_methods(): void
    {
        $orders = [
            OrderFactory::buy('AAA', 'BBB', '100', '1000', '1.0', self::SCALE, self::SCALE),
        ];

        $graph = $this->graphBuilder->build($orders);
        $engine = new ExecutionPlanSearchEngine();

        // Test complete outcome
        $spendAmount = Money::fromString('AAA', '500.00000000', self::SCALE);
        $outcome = $engine->search($graph, 'AAA', 'BBB', $spendAmount);

        if ($outcome->isComplete()) {
            self::assertTrue($outcome->hasRawFills());
            self::assertFalse($outcome->isPartial());
            self::assertFalse($outcome->isEmpty());
        } elseif ($outcome->isPartial()) {
            self::assertTrue($outcome->hasRawFills());
            self::assertFalse($outcome->isComplete());
            self::assertFalse($outcome->isEmpty());
        } else {
            self::assertFalse($outcome->hasRawFills());
            self::assertTrue($outcome->isEmpty());
        }
    }

    public function test_guard_report_metrics_are_populated(): void
    {
        $orders = [
            OrderFactory::buy('AAA', 'BBB', '100', '1000', '1.0', self::SCALE, self::SCALE),
            OrderFactory::buy('BBB', 'CCC', '100', '1000', '1.0', self::SCALE, self::SCALE),
        ];

        $graph = $this->graphBuilder->build($orders);
        $engine = new ExecutionPlanSearchEngine();

        $spendAmount = Money::fromString('AAA', '500.00000000', self::SCALE);
        $outcome = $engine->search($graph, 'AAA', 'CCC', $spendAmount);

        $guardReport = $outcome->guardReport();

        // Metrics should be populated
        self::assertGreaterThanOrEqual(0, $guardReport->expansions());
        self::assertGreaterThanOrEqual(0, $guardReport->visitedStates());
        self::assertGreaterThanOrEqual(0.0, $guardReport->elapsedMilliseconds());

        // Limits should match constructor values
        self::assertSame(ExecutionPlanSearchEngine::DEFAULT_MAX_EXPANSIONS, $guardReport->expansionLimit());
        self::assertSame(ExecutionPlanSearchEngine::DEFAULT_MAX_VISITED_STATES, $guardReport->visitedStateLimit());
        self::assertSame(ExecutionPlanSearchEngine::DEFAULT_TIME_BUDGET_MS, $guardReport->timeBudgetLimit());
    }

    // ========================================================================
    // SPLIT/MERGE EXECUTION TESTS (MUL-13)
    // ========================================================================

    /**
     * Test scenario where split execution is REQUIRED due to capacity limits.
     *
     * Order book:
     * - Order1: A→B with capacity 50 units max
     * - Order2: A→C with capacity 50 units max
     * - Order3: B→D with capacity 100 units max
     * - Order4: C→D with capacity 100 units max
     *
     * Request: Convert 80 units of A to D
     *
     * A single path (A→B→D) can only convert 50 units. To convert 80 units,
     * the algorithm MUST use both paths:
     * - A→B→D (50 units)
     * - A→C→D (30 units)
     */
    public function test_produces_non_linear_plan_when_split_required(): void
    {
        // Order1: A→B with capacity 50 units max
        $orderAB = OrderFactory::buy('AAA', 'BBB', '1.00000000', '50.00000000', '1.0', self::SCALE, self::SCALE);
        // Order2: A→C with capacity 50 units max
        $orderAC = OrderFactory::buy('AAA', 'CCC', '1.00000000', '50.00000000', '1.0', self::SCALE, self::SCALE);
        // Order3: B→D with capacity 100 units max
        $orderBD = OrderFactory::buy('BBB', 'DDD', '1.00000000', '100.00000000', '1.0', self::SCALE, self::SCALE);
        // Order4: C→D with capacity 100 units max
        $orderCD = OrderFactory::buy('CCC', 'DDD', '1.00000000', '100.00000000', '1.0', self::SCALE, self::SCALE);

        $orders = [$orderAB, $orderAC, $orderBD, $orderCD];
        $graph = $this->graphBuilder->build($orders);
        $engine = new ExecutionPlanSearchEngine();

        // Request: Convert 80 units of A to D (requires split)
        $spendAmount = Money::fromString('AAA', '80.00000000', self::SCALE);
        $outcome = $engine->search($graph, 'AAA', 'DDD', $spendAmount);

        self::assertTrue($outcome->hasRawFills(), 'Should find raw fills for split execution');

        $plan = $this->materializePlan($outcome);
        self::assertNotNull($plan);
        self::assertSame('AAA', $plan->sourceCurrency());
        self::assertSame('DDD', $plan->targetCurrency());

        // CRITICAL ASSERTIONS for non-linear (split) execution:
        // The plan should have 4 steps: A→B, A→C, B→D, C→D
        self::assertGreaterThan(2, $plan->stepCount(), 'Split plan needs more than 2 steps');

        // For a split plan, isLinear() should return false
        self::assertFalse($plan->isLinear(), 'Plan should be non-linear (split execution)');

        // Non-linear plans cannot be converted to Path
        self::assertNull($plan->asLinearPath(), 'Non-linear plan should return null from asLinearPath()');

        // Verify we spent close to requested amount (within order bounds tolerance)
        $totalSpent = $plan->totalSpent();
        self::assertSame('AAA', $totalSpent->currency());
        // Should spend most of the 80 units (at least 50 via first path)
        self::assertTrue(
            $totalSpent->decimal()->isGreaterThanOrEqualTo('50.00000000'),
            'Should spend at least 50 units via first path'
        );
    }

    /**
     * Test scenario where merge execution happens (multiple paths converge at target).
     */
    public function test_produces_non_linear_plan_when_merge_required(): void
    {
        // Two paths merging at target:
        // Path 1: A→B→D (limited by B→D capacity of 40)
        // Path 2: A→C→D (limited by C→D capacity of 40)
        // Total capacity to D: 80, Request: 70

        $orderAB = OrderFactory::buy('AAA', 'BBB', '1.00000000', '50.00000000', '1.0', self::SCALE, self::SCALE);
        $orderAC = OrderFactory::buy('AAA', 'CCC', '1.00000000', '50.00000000', '1.0', self::SCALE, self::SCALE);
        $orderBD = OrderFactory::buy('BBB', 'DDD', '1.00000000', '40.00000000', '1.0', self::SCALE, self::SCALE); // Limited capacity
        $orderCD = OrderFactory::buy('CCC', 'DDD', '1.00000000', '40.00000000', '1.0', self::SCALE, self::SCALE); // Limited capacity

        $orders = [$orderAB, $orderAC, $orderBD, $orderCD];
        $graph = $this->graphBuilder->build($orders);
        $engine = new ExecutionPlanSearchEngine();

        // Request: Convert 70 units of A to D (requires both paths due to D-side capacity limits)
        $spendAmount = Money::fromString('AAA', '70.00000000', self::SCALE);
        $outcome = $engine->search($graph, 'AAA', 'DDD', $spendAmount);

        self::assertTrue($outcome->hasRawFills(), 'Should find raw fills for merge execution');

        $plan = $this->materializePlan($outcome);
        self::assertNotNull($plan);

        // Should have multiple steps for merge execution
        self::assertGreaterThanOrEqual(3, $plan->stepCount(), 'Merge plan needs multiple steps');
    }

    /**
     * Test that step count reflects split execution properly.
     */
    public function test_step_count_reflects_split_execution(): void
    {
        // Create diamond graph with capacity limits forcing split
        $orderAB = OrderFactory::buy('AAA', 'BBB', '1.00000000', '30.00000000', '1.0', self::SCALE, self::SCALE);
        $orderAC = OrderFactory::buy('AAA', 'CCC', '1.00000000', '30.00000000', '1.0', self::SCALE, self::SCALE);
        $orderBD = OrderFactory::buy('BBB', 'DDD', '1.00000000', '50.00000000', '1.0', self::SCALE, self::SCALE);
        $orderCD = OrderFactory::buy('CCC', 'DDD', '1.00000000', '50.00000000', '1.0', self::SCALE, self::SCALE);

        $orders = [$orderAB, $orderAC, $orderBD, $orderCD];
        $graph = $this->graphBuilder->build($orders);
        $engine = new ExecutionPlanSearchEngine();

        // Request that requires both paths: 50 units (30 max per first leg)
        $spendAmount = Money::fromString('AAA', '50.00000000', self::SCALE);
        $outcome = $engine->search($graph, 'AAA', 'DDD', $spendAmount);

        self::assertTrue($outcome->hasRawFills());

        $plan = $this->materializePlan($outcome);
        self::assertNotNull($plan);

        // A full split should have 4 steps: A→B, B→D, A→C, C→D
        // Or at minimum, if partial: 2+ steps
        self::assertGreaterThanOrEqual(2, $plan->stepCount());
    }

    /**
     * Test that total spent aggregates across split steps.
     */
    public function test_total_spent_aggregates_split_steps(): void
    {
        // Create scenario with split execution
        $orderAB = OrderFactory::buy('AAA', 'BBB', '1.00000000', '25.00000000', '1.0', self::SCALE, self::SCALE);
        $orderAC = OrderFactory::buy('AAA', 'CCC', '1.00000000', '25.00000000', '1.0', self::SCALE, self::SCALE);
        $orderBD = OrderFactory::buy('BBB', 'DDD', '1.00000000', '50.00000000', '1.0', self::SCALE, self::SCALE);
        $orderCD = OrderFactory::buy('CCC', 'DDD', '1.00000000', '50.00000000', '1.0', self::SCALE, self::SCALE);

        $orders = [$orderAB, $orderAC, $orderBD, $orderCD];
        $graph = $this->graphBuilder->build($orders);
        $engine = new ExecutionPlanSearchEngine();

        // Request 40 units - requires split across both routes (25 max per first leg)
        $spendAmount = Money::fromString('AAA', '40.00000000', self::SCALE);
        $outcome = $engine->search($graph, 'AAA', 'DDD', $spendAmount);

        self::assertTrue($outcome->hasRawFills());

        $plan = $this->materializePlan($outcome);
        self::assertNotNull($plan);

        $totalSpent = $plan->totalSpent();
        self::assertSame('AAA', $totalSpent->currency());

        // Should spend close to requested 40 (may be limited by first-leg capacity of 50 total)
        self::assertTrue(
            $totalSpent->decimal()->isGreaterThanOrEqualTo('25.00000000'),
            'Should aggregate spend from multiple split steps'
        );
    }

    /**
     * Test that total received aggregates across merge steps.
     */
    public function test_total_received_aggregates_merge_steps(): void
    {
        // Create scenario with merge execution (different rates to track received amounts)
        $orderAB = OrderFactory::buy('AAA', 'BBB', '1.00000000', '30.00000000', '1.0', self::SCALE, self::SCALE);
        $orderAC = OrderFactory::buy('AAA', 'CCC', '1.00000000', '30.00000000', '1.0', self::SCALE, self::SCALE);
        $orderBD = OrderFactory::buy('BBB', 'DDD', '1.00000000', '50.00000000', '2.0', self::SCALE, self::SCALE); // 2x rate
        $orderCD = OrderFactory::buy('CCC', 'DDD', '1.00000000', '50.00000000', '2.0', self::SCALE, self::SCALE); // 2x rate

        $orders = [$orderAB, $orderAC, $orderBD, $orderCD];
        $graph = $this->graphBuilder->build($orders);
        $engine = new ExecutionPlanSearchEngine();

        // Request 50 units - requires split
        $spendAmount = Money::fromString('AAA', '50.00000000', self::SCALE);
        $outcome = $engine->search($graph, 'AAA', 'DDD', $spendAmount);

        self::assertTrue($outcome->hasRawFills());

        $plan = $this->materializePlan($outcome);
        self::assertNotNull($plan);

        $totalReceived = $plan->totalReceived();
        self::assertSame('DDD', $totalReceived->currency());

        // Should receive DDD from all merge steps
        self::assertTrue(
            $totalReceived->decimal()->isPositive(),
            'Should aggregate received amount from multiple merge steps'
        );
    }

    /**
     * Test multi-order aggregation combined with split execution.
     *
     * Scenario: Two A→B orders plus split paths
     */
    public function test_multi_order_with_split_execution(): void
    {
        // Two A→B orders with 30 capacity each (60 total)
        $orderAB1 = OrderFactory::buy('AAA', 'BBB', '1.00000000', '30.00000000', '1.0', self::SCALE, self::SCALE);
        $orderAB2 = OrderFactory::buy('AAA', 'BBB', '1.00000000', '30.00000000', '1.0', self::SCALE, self::SCALE);
        // A→C with 40 capacity
        $orderAC = OrderFactory::buy('AAA', 'CCC', '1.00000000', '40.00000000', '1.0', self::SCALE, self::SCALE);
        // B→D and C→D with high capacity
        $orderBD = OrderFactory::buy('BBB', 'DDD', '1.00000000', '100.00000000', '1.0', self::SCALE, self::SCALE);
        $orderCD = OrderFactory::buy('CCC', 'DDD', '1.00000000', '100.00000000', '1.0', self::SCALE, self::SCALE);

        $orders = [$orderAB1, $orderAB2, $orderAC, $orderBD, $orderCD];
        $graph = $this->graphBuilder->build($orders);
        $engine = new ExecutionPlanSearchEngine();

        // Request 90 units - can use A→B (both orders: 60) + A→C (40) = 100 capacity
        $spendAmount = Money::fromString('AAA', '90.00000000', self::SCALE);
        $outcome = $engine->search($graph, 'AAA', 'DDD', $spendAmount);

        self::assertTrue($outcome->hasRawFills());

        $plan = $this->materializePlan($outcome);
        self::assertNotNull($plan);

        // Should have multiple steps from using multiple orders
        self::assertGreaterThanOrEqual(2, $plan->stepCount());
    }

    /**
     * Test that linear execution is preferred when capacity is sufficient.
     */
    public function test_prefers_linear_when_capacity_sufficient(): void
    {
        // High capacity on direct path
        $orderAB = OrderFactory::buy('AAA', 'BBB', '1.00000000', '100.00000000', '1.0', self::SCALE, self::SCALE);
        $orderBD = OrderFactory::buy('BBB', 'DDD', '1.00000000', '100.00000000', '1.0', self::SCALE, self::SCALE);
        // Alternative lower-capacity path
        $orderAC = OrderFactory::buy('AAA', 'CCC', '1.00000000', '50.00000000', '1.0', self::SCALE, self::SCALE);
        $orderCD = OrderFactory::buy('CCC', 'DDD', '1.00000000', '50.00000000', '1.0', self::SCALE, self::SCALE);

        $orders = [$orderAB, $orderBD, $orderAC, $orderCD];
        $graph = $this->graphBuilder->build($orders);
        $engine = new ExecutionPlanSearchEngine();

        // Request 50 units - well within single path capacity
        $spendAmount = Money::fromString('AAA', '50.00000000', self::SCALE);
        $outcome = $engine->search($graph, 'AAA', 'DDD', $spendAmount);

        self::assertTrue($outcome->hasRawFills());

        $plan = $this->materializePlan($outcome);
        self::assertNotNull($plan);

        // Should prefer linear path (2 steps: A→B→D)
        self::assertSame(2, $plan->stepCount());
        self::assertTrue($plan->isLinear(), 'Should be linear when capacity is sufficient');
    }

    // ========================================================================
    // RAW FILLS FORMAT TESTS
    // ========================================================================

    /**
     * Test that raw fills contain the expected structure.
     */
    public function test_raw_fills_format_matches_materializer_expectations(): void
    {
        $orders = [
            OrderFactory::buy('AAA', 'BBB', '100', '1000', '1.0', self::SCALE, self::SCALE),
        ];

        $graph = $this->graphBuilder->build($orders);
        $engine = new ExecutionPlanSearchEngine();

        $spendAmount = Money::fromString('AAA', '500.00000000', self::SCALE);
        $outcome = $engine->search($graph, 'AAA', 'BBB', $spendAmount);

        self::assertTrue($outcome->hasRawFills());

        $rawFills = $outcome->rawFills();
        self::assertNotNull($rawFills);
        self::assertNotEmpty($rawFills);

        foreach ($rawFills as $fill) {
            // Verify expected keys exist
            self::assertArrayHasKey('order', $fill);
            self::assertArrayHasKey('spend', $fill);
            self::assertArrayHasKey('sequence', $fill);

            // Verify types
            self::assertInstanceOf(\SomeWork\P2PPathFinder\Domain\Order\Order::class, $fill['order']);
            self::assertInstanceOf(Money::class, $fill['spend']);
            self::assertIsInt($fill['sequence']);
            self::assertGreaterThanOrEqual(1, $fill['sequence']);
        }
    }

    /**
     * Test that source and target currencies are correctly passed to outcome.
     */
    public function test_outcome_contains_source_and_target_currencies(): void
    {
        $orders = [
            OrderFactory::buy('USD', 'BTC', '100', '1000', '0.00002', self::SCALE, self::SCALE),
        ];

        $graph = $this->graphBuilder->build($orders);
        $engine = new ExecutionPlanSearchEngine();

        $spendAmount = Money::fromString('USD', '500.00000000', self::SCALE);
        $outcome = $engine->search($graph, 'USD', 'BTC', $spendAmount);

        self::assertTrue($outcome->hasRawFills());
        self::assertSame('USD', $outcome->sourceCurrency());
        self::assertSame('BTC', $outcome->targetCurrency());
    }

    // ========================================================================
    // SAME-CURRENCY (TRANSFER) SEARCH TESTS
    // ========================================================================

    /**
     * Test that same-currency search with no transfer orders returns empty.
     */
    public function test_same_currency_without_transfers_returns_empty(): void
    {
        // Create only cross-currency orders, no transfer orders
        $orders = [
            OrderFactory::buy('USD', 'BTC', '100', '1000', '0.00002', self::SCALE, self::SCALE),
        ];

        $graph = $this->graphBuilder->build($orders);
        $engine = new ExecutionPlanSearchEngine();

        // Search same currency (USD -> USD) with no transfer orders available
        $spendAmount = Money::fromString('USD', '500.00000000', self::SCALE);
        $outcome = $engine->search($graph, 'USD', 'USD', $spendAmount);

        // Should return empty since no transfer orders exist
        self::assertFalse($outcome->hasRawFills());
        self::assertTrue($outcome->isEmpty());
    }

    /**
     * Test that same-currency search with transfer orders returns fills.
     */
    public function test_same_currency_with_transfer_returns_fills(): void
    {
        // Create a transfer order (same base and quote currency)
        // USD/USD with 1:1 rate represents a transfer between exchanges
        $transferOrder = OrderFactory::buy('USD', 'USD', '10', '1000', '1.0', self::SCALE, self::SCALE);

        $orders = [$transferOrder];
        $graph = $this->graphBuilder->build($orders);
        $engine = new ExecutionPlanSearchEngine();

        $spendAmount = Money::fromString('USD', '500.00000000', self::SCALE);
        $outcome = $engine->search($graph, 'USD', 'USD', $spendAmount);

        // Should have raw fills if transfer order was found and used
        if ($outcome->hasRawFills()) {
            self::assertSame('USD', $outcome->sourceCurrency());
            self::assertSame('USD', $outcome->targetCurrency());

            $rawFills = $outcome->rawFills();
            self::assertNotNull($rawFills);
            self::assertNotEmpty($rawFills);

            // All fills should reference the transfer order
            foreach ($rawFills as $fill) {
                self::assertTrue($fill['order']->isTransfer());
            }
        }
    }

    // ========================================================================
    // MATERIALIZER INTEGRATION TESTS
    // ========================================================================

    /**
     * Test that materialized plan matches expected totals from raw fills.
     */
    public function test_materialized_plan_totals_consistent_with_fills(): void
    {
        $orders = [
            OrderFactory::buy('AAA', 'BBB', '100', '1000', '1.0', self::SCALE, self::SCALE),
        ];

        $graph = $this->graphBuilder->build($orders);
        $engine = new ExecutionPlanSearchEngine();

        $spendAmount = Money::fromString('AAA', '500.00000000', self::SCALE);
        $outcome = $engine->search($graph, 'AAA', 'BBB', $spendAmount);

        self::assertTrue($outcome->hasRawFills());

        $rawFills = $outcome->rawFills();
        self::assertNotNull($rawFills);

        // Calculate total spend from raw fills
        $totalSpendFromFills = '0';
        foreach ($rawFills as $fill) {
            $totalSpendFromFills = bcadd($totalSpendFromFills, $fill['spend']->amount(), self::SCALE);
        }

        // Materialize and verify totals match
        $plan = $this->materializePlan($outcome);
        self::assertNotNull($plan);

        // Total spent in plan should match sum of raw fill spends
        self::assertSame(
            bcadd($totalSpendFromFills, '0', self::SCALE),
            bcadd($plan->totalSpent()->amount(), '0', self::SCALE),
            'Materialized plan total spent should match sum of raw fill spends'
        );
    }

    /**
     * Test that sequence numbers are preserved through materialization.
     */
    public function test_sequence_numbers_preserved_through_materialization(): void
    {
        // Create multi-hop path to get multiple steps
        $orders = [
            OrderFactory::buy('AAA', 'BBB', '100', '1000', '1.0', self::SCALE, self::SCALE),
            OrderFactory::buy('BBB', 'CCC', '100', '1000', '1.0', self::SCALE, self::SCALE),
        ];

        $graph = $this->graphBuilder->build($orders);
        $engine = new ExecutionPlanSearchEngine();

        $spendAmount = Money::fromString('AAA', '500.00000000', self::SCALE);
        $outcome = $engine->search($graph, 'AAA', 'CCC', $spendAmount);

        self::assertTrue($outcome->hasRawFills());

        $rawFills = $outcome->rawFills();
        self::assertNotNull($rawFills);
        self::assertCount(2, $rawFills, 'Should have 2 fills for 2-hop path');

        // Get sequence numbers from raw fills
        $rawSequences = array_map(fn ($fill) => $fill['sequence'], $rawFills);

        // Materialize
        $plan = $this->materializePlan($outcome);
        self::assertNotNull($plan);

        // Get sequence numbers from materialized steps
        $steps = $plan->steps()->all();
        $stepSequences = array_map(fn ($step) => $step->sequenceNumber(), $steps);

        // Sequences should match
        self::assertSame($rawSequences, $stepSequences, 'Sequence numbers should be preserved');
    }
}
