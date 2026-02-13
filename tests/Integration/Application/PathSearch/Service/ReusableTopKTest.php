<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Integration\Application\PathSearch\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Api\Request\PathSearchRequest;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\ExecutionPlan;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\ExecutionPlanService;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\OrderBook;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

use function count;

/**
 * Integration tests for reusable Top-K execution plan discovery.
 *
 * These tests verify that ExecutionPlanService correctly returns multiple
 * execution plans in reusable mode (disjointPlans=false), allowing order
 * sharing across plans with duplicate detection.
 */
#[CoversClass(ExecutionPlanService::class)]
final class ReusableTopKTest extends TestCase
{
    private ExecutionPlanService $service;

    protected function setUp(): void
    {
        $this->service = new ExecutionPlanService(new GraphBuilder());
    }

    // ========================================================================
    // BASIC REUSABLE MODE BEHAVIOR TESTS
    // ========================================================================

    #[TestDox('Reusable mode allows order sharing across plans')]
    public function test_reusable_mode_allows_order_sharing_across_plans(): void
    {
        // Single high-capacity order that could be used in multiple plans
        $order = OrderFactory::sell('USDT', 'RUB', '10.00', '5000.00', '95.00', 2, 2);

        $orderBook = new OrderBook([$order]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '950.00', 2))
            ->withToleranceBounds('0.0', '0.90')
            ->withHopLimits(1, 3)
            ->withResultLimit(3)
            ->withDisjointPlans(false) // Reusable mode
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'USDT');
        $outcome = $this->service->findBestPlans($request);

        // With only one order, we might get just 1 plan (duplicates detected)
        self::assertTrue($outcome->hasPaths());
        self::assertGreaterThanOrEqual(1, $outcome->paths()->count());
    }

    #[TestDox('Reusable mode produces more alternatives than disjoint mode')]
    public function test_reusable_mode_produces_more_alternatives(): void
    {
        // Multi-hop order book: two intermediary currencies sharing a common first-hop order.
        // Disjoint mode cannot reuse the shared first-hop order across plans,
        // so it finds fewer alternatives than reusable mode.
        $rubToUsdt = OrderFactory::sell('USDT', 'RUB', '10.00', '5000.00', '95.00', 2, 2);
        $usdtToEur1 = OrderFactory::buy('USDT', 'EUR', '10.00', '5000.00', '0.92', 2, 2);
        $usdtToEur2 = OrderFactory::buy('USDT', 'EUR', '10.00', '5000.00', '0.90', 2, 2);

        $orderBook = new OrderBook([$rubToUsdt, $usdtToEur1, $usdtToEur2]);

        $spendAmount = Money::fromString('RUB', '950.00', 2);

        // Disjoint mode - each order can only appear in one plan
        $disjointConfig = PathSearchConfig::builder()
            ->withSpendAmount($spendAmount)
            ->withToleranceBounds('0.0', '0.90')
            ->withHopLimits(1, 4)
            ->withResultLimit(5)
            ->withDisjointPlans(true)
            ->build();

        $disjointOutcome = $this->service->findBestPlans(
            new PathSearchRequest($orderBook, $disjointConfig, 'EUR')
        );

        // Reusable mode - orders can be shared across plans
        $reusableConfig = PathSearchConfig::builder()
            ->withSpendAmount($spendAmount)
            ->withToleranceBounds('0.0', '0.90')
            ->withHopLimits(1, 4)
            ->withResultLimit(5)
            ->withDisjointPlans(false)
            ->build();

        $reusableOutcome = $this->service->findBestPlans(
            new PathSearchRequest($orderBook, $reusableConfig, 'EUR')
        );

        // Both should find plans
        self::assertTrue($disjointOutcome->hasPaths());
        self::assertTrue($reusableOutcome->hasPaths());

        // Reusable mode might find the same or more (with duplicates filtered)
        self::assertGreaterThanOrEqual(1, $reusableOutcome->paths()->count());

        // Verify reusable mode produces at least as many alternatives
        self::assertGreaterThanOrEqual(
            $disjointOutcome->paths()->count(),
            $reusableOutcome->paths()->count(),
            'Reusable mode should produce at least as many alternatives as disjoint mode'
        );
    }

    #[TestDox('Reusable mode avoids exact duplicates')]
    public function test_reusable_mode_avoids_exact_duplicates(): void
    {
        $order1 = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '95.00', 2, 2);
        $order2 = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '100.00', 2, 2);
        $order3 = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '105.00', 2, 2);

        $orderBook = new OrderBook([$order1, $order2, $order3]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '9500.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 3)
            ->withResultLimit(5)
            ->withDisjointPlans(false)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'USDT');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());

        // Verify no two plans have identical signatures
        $signatures = [];
        foreach ($outcome->paths() as $plan) {
            $sig = $plan->signature();
            self::assertArrayNotHasKey(
                $sig,
                $signatures,
                'Reusable mode should not produce duplicate signatures'
            );
            $signatures[$sig] = true;
        }
    }

    #[TestDox('Reusable mode plans are ordered by cost')]
    public function test_reusable_mode_plans_ordered_by_cost(): void
    {
        // Orders with clearly different rates
        $order1 = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '90.00', 2, 2);  // best
        $order2 = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '100.00', 2, 2); // mid
        $order3 = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '110.00', 2, 2); // worst

        $orderBook = new OrderBook([$order3, $order1, $order2]); // Shuffled order

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '9000.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 3)
            ->withResultLimit(3)
            ->withDisjointPlans(false)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'USDT');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $plans = $outcome->paths()->toArray();

        // Verify cost ordering: best plan first (most received)
        for ($i = 1; $i < count($plans); ++$i) {
            $prevReceived = $plans[$i - 1]->totalReceived()->decimal();
            $currReceived = $plans[$i]->totalReceived()->decimal();

            self::assertTrue(
                $prevReceived->isGreaterThanOrEqualTo($currReceived),
                "Plan {$i} should receive less than or equal to plan ".($i - 1)
            );
        }
    }

    // ========================================================================
    // DISJOINT MODE REGRESSION TESTS
    // ========================================================================

    #[TestDox('Disjoint mode still works as before')]
    public function test_disjoint_mode_still_works_as_before(): void
    {
        // Regression test: disjointPlans=true behaves like TOPK-1
        $order1 = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '95.00', 2, 2);
        $order2 = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '100.00', 2, 2);
        $order3 = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '105.00', 2, 2);

        $orderBook = new OrderBook([$order1, $order2, $order3]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '9500.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 3)
            ->withResultLimit(3)
            ->withDisjointPlans(true) // Explicit disjoint mode
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'USDT');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        self::assertSame(3, $outcome->paths()->count());

        // Verify all plans use disjoint orders
        $usedOrders = [];
        foreach ($outcome->paths() as $plan) {
            foreach ($plan->steps() as $step) {
                $orderId = spl_object_id($step->order());
                self::assertArrayNotHasKey(
                    $orderId,
                    $usedOrders,
                    'Disjoint mode should not reuse orders'
                );
                $usedOrders[$orderId] = true;
            }
        }
    }

    // ========================================================================
    // EDGE CASE TESTS
    // ========================================================================

    #[TestDox('Reusable mode handles single order gracefully')]
    public function test_reusable_mode_handles_single_order_gracefully(): void
    {
        // Only one order available
        $order = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '95.00', 2, 2);

        $orderBook = new OrderBook([$order]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '950.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 3)
            ->withResultLimit(5)
            ->withDisjointPlans(false)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'USDT');
        $outcome = $this->service->findBestPlans($request);

        // Should return 1 plan (no duplicates)
        self::assertTrue($outcome->hasPaths());
        self::assertSame(1, $outcome->paths()->count());
    }

    #[TestDox('Reusable mode with multi-hop paths')]
    public function test_reusable_mode_with_multi_hop_paths(): void
    {
        // Two parallel 2-hop routes:
        // Route 1: RUB -> USDT -> BTC
        // Route 2: RUB -> EUR -> BTC
        $rubToUsdt = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '95.00', 2, 2);
        $usdtToBtc = OrderFactory::buy('USDT', 'BTC', '10.00', '1000.00', '0.000025', 2, 8);

        $rubToEur = OrderFactory::sell('EUR', 'RUB', '10.00', '100.00', '100.00', 2, 2);
        $eurToBtc = OrderFactory::buy('EUR', 'BTC', '10.00', '100.00', '0.000023', 2, 8);

        $orderBook = new OrderBook([$rubToUsdt, $usdtToBtc, $rubToEur, $eurToBtc]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '9500.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 4)
            ->withResultLimit(3)
            ->withDisjointPlans(false)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        // Should find at least one multi-hop route
        self::assertGreaterThanOrEqual(1, $outcome->paths()->count());
    }

    #[TestDox('Reusable mode empty order book returns empty')]
    public function test_reusable_mode_empty_order_book_returns_empty(): void
    {
        $orderBook = new OrderBook([]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 3)
            ->withResultLimit(5)
            ->withDisjointPlans(false)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'EUR');
        $outcome = $this->service->findBestPlans($request);

        self::assertFalse($outcome->hasPaths());
    }

    // ========================================================================
    // SCENARIO TESTS
    // ========================================================================

    #[TestDox('Scenario: aggregation alternatives with reusable mode')]
    public function test_scenario_aggregation_alternatives(): void
    {
        // Order A: 1000 USDT capacity
        // Order B: 500 USDT capacity
        // Amount: 1200 USDT
        //
        // Plan 1: A alone (fills partially)
        // Plan 2: A + B might be different (aggregation)
        // Plan 3: B alone (fills partially)
        $orderA = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '95.00', 2, 2);
        $orderB = OrderFactory::sell('USDT', 'RUB', '10.00', '500.00', '97.00', 2, 2);

        $orderBook = new OrderBook([$orderA, $orderB]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '11400.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 3)
            ->withResultLimit(3)
            ->withDisjointPlans(false)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'USDT');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        // Should find at least 1 plan
        self::assertGreaterThanOrEqual(1, $outcome->paths()->count());
    }

    #[TestDox('Scenario: comparison disjoint vs reusable')]
    public function test_scenario_comparison_disjoint_vs_reusable(): void
    {
        // Same order book, same K
        $orders = [];
        for ($i = 0; $i < 3; ++$i) {
            $rate = (string) (95 + $i * 2);
            $orders[] = OrderFactory::sell('USDT', 'RUB', '10.00', '500.00', $rate, 2, 2);
        }

        $orderBook = new OrderBook($orders);
        $spendAmount = Money::fromString('RUB', '4750.00', 2);

        // Disjoint config
        $disjointConfig = PathSearchConfig::builder()
            ->withSpendAmount($spendAmount)
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 3)
            ->withResultLimit(3)
            ->withDisjointPlans(true)
            ->build();

        // Reusable config
        $reusableConfig = PathSearchConfig::builder()
            ->withSpendAmount($spendAmount)
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 3)
            ->withResultLimit(3)
            ->withDisjointPlans(false)
            ->build();

        $disjointOutcome = $this->service->findBestPlans(
            new PathSearchRequest($orderBook, $disjointConfig, 'USDT')
        );
        $reusableOutcome = $this->service->findBestPlans(
            new PathSearchRequest($orderBook, $reusableConfig, 'USDT')
        );

        // Both should have results
        self::assertTrue($disjointOutcome->hasPaths());
        self::assertTrue($reusableOutcome->hasPaths());

        // Best plans should have similar or same cost
        $disjointBest = $disjointOutcome->bestPath();
        $reusableBest = $reusableOutcome->bestPath();

        self::assertNotNull($disjointBest);
        self::assertNotNull($reusableBest);

        // Both modes should produce valid results with reasonable amounts
        // Note: They may not be exactly equal due to different algorithmic approaches
        self::assertGreaterThan(
            0,
            (float) $disjointBest->totalReceived()->amount(),
            'Disjoint best should receive a positive amount'
        );
        self::assertGreaterThan(
            0,
            (float) $reusableBest->totalReceived()->amount(),
            'Reusable best should receive a positive amount'
        );

        // Both should use the same source and target currencies
        self::assertSame($disjointBest->sourceCurrency(), $reusableBest->sourceCurrency());
        self::assertSame($disjointBest->targetCurrency(), $reusableBest->targetCurrency());

        // Best plans should have comparable costs (within 10% of each other)
        // since they have access to the same optimal order initially
        $disjointCost = (float) $disjointBest->totalSpent()->amount() / (float) $disjointBest->totalReceived()->amount();
        $reusableCost = (float) $reusableBest->totalSpent()->amount() / (float) $reusableBest->totalReceived()->amount();

        $costDiff = abs($disjointCost - $reusableCost);
        $maxCost = max($disjointCost, $reusableCost);

        self::assertLessThan(
            $maxCost * 0.10, // Within 10%
            $costDiff,
            'Best plans should have comparable costs (within 10%)'
        );
    }

    // ========================================================================
    // GUARD LIMIT TESTS
    // ========================================================================

    #[TestDox('Reusable mode aggregates guard reports')]
    public function test_reusable_mode_aggregates_guard_reports(): void
    {
        $orders = [];
        for ($i = 0; $i < 5; ++$i) {
            $orders[] = OrderFactory::sell('USDT', 'RUB', '10.00', '500.00', (string) (95 + $i * 2), 2, 2);
        }

        $orderBook = new OrderBook($orders);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '4750.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 3)
            ->withResultLimit(3)
            ->withDisjointPlans(false)
            ->withSearchGuards(10000, 25000, null)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'USDT');
        $outcome = $this->service->findBestPlans($request);

        $guardReport = $outcome->guardLimits();

        // Should have aggregated metrics from multiple iterations
        self::assertGreaterThan(0, $guardReport->expansions());
        self::assertGreaterThan(0, $guardReport->visitedStates());
    }

    // ========================================================================
    // DETERMINISM TESTS
    // ========================================================================

    #[TestDox('Reusable mode results are deterministic on repeated runs')]
    public function test_reusable_mode_determinism(): void
    {
        $orders = [];
        for ($i = 0; $i < 5; ++$i) {
            $orders[] = OrderFactory::sell('USDT', 'RUB', '10.00', '500.00', (string) (95 + $i), 2, 2);
        }

        $orderBook = new OrderBook($orders);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '4750.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 3)
            ->withResultLimit(3)
            ->withDisjointPlans(false)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'USDT');

        $results = [];
        for ($run = 0; $run < 5; ++$run) {
            $outcome = $this->service->findBestPlans($request);
            $planData = [];
            foreach ($outcome->paths() as $plan) {
                $planData[] = $plan->signature();
            }
            $results[] = $planData;
        }

        // All results should be identical
        for ($i = 1; $i < 5; ++$i) {
            self::assertSame($results[0], $results[$i], "Run {$i} differs from run 0");
        }
    }

    // ========================================================================
    // ADDITIONAL EDGE CASES
    // ========================================================================

    #[TestDox('Reusable mode with identical order rates')]
    public function test_reusable_mode_identical_rates(): void
    {
        // All orders have the same rate
        $order1 = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '95.00', 2, 2);
        $order2 = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '95.00', 2, 2);
        $order3 = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '95.00', 2, 2);

        $orderBook = new OrderBook([$order1, $order2, $order3]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '9500.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 3)
            ->withResultLimit(5)
            ->withDisjointPlans(false)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'USDT');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());

        // Should find plans but duplicate detection based on cost may filter some
        $signatures = [];
        foreach ($outcome->paths() as $plan) {
            $sig = $plan->signature();
            self::assertArrayNotHasKey($sig, $signatures, 'No duplicate signatures allowed');
            $signatures[$sig] = true;
        }
    }

    #[TestDox('Reusable mode stops after max consecutive duplicates')]
    public function test_reusable_mode_stops_on_too_many_duplicates(): void
    {
        // Single order - should produce only one plan, not loop forever
        $order = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '95.00', 2, 2);

        $orderBook = new OrderBook([$order]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '950.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 3)
            ->withResultLimit(10) // Request many more than possible
            ->withDisjointPlans(false)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'USDT');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        // Should terminate gracefully with only 1 plan
        self::assertSame(1, $outcome->paths()->count());
    }

    #[TestDox('Default disjoint mode is true when not specified')]
    public function test_default_disjoint_mode_is_true(): void
    {
        $order1 = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '95.00', 2, 2);
        $order2 = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '100.00', 2, 2);

        $orderBook = new OrderBook([$order1, $order2]);

        // Don't specify withDisjointPlans - should default to true
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '9500.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 3)
            ->withResultLimit(2)
            ->build();

        self::assertTrue($config->disjointPlans());

        $request = new PathSearchRequest($orderBook, $config, 'USDT');
        $outcome = $this->service->findBestPlans($request);

        // Verify disjoint behavior (no order reuse)
        $usedOrders = [];
        foreach ($outcome->paths() as $plan) {
            foreach ($plan->steps() as $step) {
                $orderId = spl_object_id($step->order());
                self::assertArrayNotHasKey($orderId, $usedOrders, 'Default mode should use disjoint orders');
                $usedOrders[$orderId] = true;
            }
        }
    }

    #[TestDox('Reusable mode handles disconnected graph')]
    public function test_reusable_mode_disconnected_graph(): void
    {
        // Orders that don't connect source to target
        $order1 = OrderFactory::buy('USD', 'EUR', '10.00', '1000.00', '0.92', 2, 2);
        $order2 = OrderFactory::buy('GBP', 'JPY', '10.00', '1000.00', '180.00', 2, 2);

        $orderBook = new OrderBook([$order1, $order2]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 3)
            ->withResultLimit(3)
            ->withDisjointPlans(false)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'JPY');
        $outcome = $this->service->findBestPlans($request);

        // Should return empty - no path exists
        self::assertFalse($outcome->hasPaths());
    }

    // ========================================================================
    // ASSERTION HELPERS
    // ========================================================================

    /**
     * @param list<ExecutionPlan> $plans
     */
    private function assertPlansUseDisjointOrderSets(array $plans): void
    {
        $usedOrders = [];

        foreach ($plans as $planIndex => $plan) {
            foreach ($plan->steps() as $step) {
                $orderId = spl_object_id($step->order());
                $previousPlan = $usedOrders[$orderId] ?? 'unknown';
                self::assertArrayNotHasKey(
                    $orderId,
                    $usedOrders,
                    "Order reused in plan {$planIndex} (was used in plan {$previousPlan})"
                );
                $usedOrders[$orderId] = $planIndex;
            }
        }
    }
}
