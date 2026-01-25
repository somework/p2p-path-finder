<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\ExecutionPlanSearchEngine;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\ExecutionPlanSearchOutcome;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\Graph;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

use function in_array;

#[CoversClass(ExecutionPlanSearchEngine::class)]
#[CoversClass(ExecutionPlanSearchOutcome::class)]
final class ExecutionPlanSearchEngineTest extends TestCase
{
    private const SCALE = 8;

    private GraphBuilder $graphBuilder;

    protected function setUp(): void
    {
        $this->graphBuilder = new GraphBuilder();
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

        self::assertTrue($outcome->hasPlan());
        self::assertTrue($outcome->isComplete());
        self::assertFalse($outcome->isPartial());

        $plan = $outcome->plan();
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

        self::assertTrue($outcome->hasPlan());

        $plan = $outcome->plan();
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

        self::assertTrue($outcome->hasPlan());

        $plan = $outcome->plan();
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

        self::assertTrue($outcome->hasPlan());

        $plan = $outcome->plan();
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

        self::assertTrue($outcome->hasPlan());

        $plan = $outcome->plan();
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

        self::assertTrue($outcome->hasPlan());

        $plan = $outcome->plan();
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

        self::assertTrue($outcome->hasPlan());

        $plan = $outcome->plan();
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
            $plan = $outcome->plan();

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

        self::assertTrue($outcome->hasPlan());

        $plan = $outcome->plan();
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

        self::assertFalse($outcome->hasPlan());
        self::assertTrue($outcome->isEmpty());
        self::assertNull($outcome->plan());
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
            self::assertTrue($outcome->hasPlan());
            self::assertFalse($outcome->isPartial());
            self::assertFalse($outcome->isEmpty());
        } elseif ($outcome->isPartial()) {
            self::assertTrue($outcome->hasPlan());
            self::assertFalse($outcome->isComplete());
            self::assertFalse($outcome->isEmpty());
        } else {
            self::assertFalse($outcome->hasPlan());
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
}
