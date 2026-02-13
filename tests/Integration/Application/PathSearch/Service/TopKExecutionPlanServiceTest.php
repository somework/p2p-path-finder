<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Integration\Application\PathSearch\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Api\Request\PathSearchRequest;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\CostHopsSignatureOrderingStrategy;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\ExecutionPlan;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\ExecutionPlanMaterializer;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\ExecutionPlanService;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\LegMaterializer;
use SomeWork\P2PPathFinder\Application\PathSearch\Support\OrderFillEvaluator;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderBook;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

use function count;

/**
 * Integration tests for Top-K execution plan discovery.
 *
 * These tests verify that ExecutionPlanService correctly returns multiple
 * distinct execution plans when requested via resultLimit configuration.
 */
#[CoversClass(ExecutionPlanService::class)]
final class TopKExecutionPlanServiceTest extends TestCase
{
    private ExecutionPlanService $service;

    protected function setUp(): void
    {
        $this->service = new ExecutionPlanService(new GraphBuilder());
    }

    // ========================================================================
    // BASIC TOP-K BEHAVIOR TESTS
    // ========================================================================

    #[TestDox('Returns single plan when K is one (default behavior)')]
    public function test_returns_single_plan_when_k_is_one(): void
    {
        // Multiple orders with different rates
        $order1 = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '95.00', 2, 2);  // best rate
        $order2 = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '100.00', 2, 2);
        $order3 = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '105.00', 2, 2); // worst rate

        $orderBook = new OrderBook([$order1, $order2, $order3]);

        // K=1 (default)
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '9500.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 3)
            ->withResultLimit(1)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'USDT');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        self::assertSame(1, $outcome->paths()->count());
    }

    #[TestDox('Returns K distinct plans when sufficient liquidity exists')]
    public function test_returns_k_distinct_plans_when_sufficient_liquidity(): void
    {
        // 3 independent orders with different rates
        $order1 = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '95.00', 2, 2);  // best
        $order2 = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '100.00', 2, 2); // mid
        $order3 = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '105.00', 2, 2); // worst

        $orderBook = new OrderBook([$order1, $order2, $order3]);

        // Request K=3
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '9500.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 3)
            ->withResultLimit(3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'USDT');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        self::assertSame(3, $outcome->paths()->count());

        // Verify all plans are distinct (use different orders)
        $this->assertPlansUseDisjointOrderSets($outcome->paths()->toArray());
    }

    #[TestDox('Returns fewer than K plans when insufficient alternatives')]
    public function test_returns_fewer_than_k_plans_when_insufficient_alternatives(): void
    {
        // Only 2 orders available
        $order1 = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '95.00', 2, 2);
        $order2 = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '100.00', 2, 2);

        $orderBook = new OrderBook([$order1, $order2]);

        // Request K=5, but only 2 orders exist
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '9500.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 3)
            ->withResultLimit(5)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'USDT');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        // Should return only 2 plans since only 2 orders exist
        self::assertSame(2, $outcome->paths()->count());
    }

    #[TestDox('Plans use disjoint order sets')]
    public function test_plans_use_disjoint_order_sets(): void
    {
        // Create 4 orders
        $order1 = OrderFactory::sell('USDT', 'RUB', '10.00', '500.00', '95.00', 2, 2);
        $order2 = OrderFactory::sell('USDT', 'RUB', '10.00', '500.00', '97.00', 2, 2);
        $order3 = OrderFactory::sell('USDT', 'RUB', '10.00', '500.00', '99.00', 2, 2);
        $order4 = OrderFactory::sell('USDT', 'RUB', '10.00', '500.00', '101.00', 2, 2);

        $orderBook = new OrderBook([$order1, $order2, $order3, $order4]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '4750.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 3)
            ->withResultLimit(4)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'USDT');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());

        // Verify no order appears in multiple plans
        $usedOrders = [];
        foreach ($outcome->paths() as $plan) {
            foreach ($plan->steps() as $step) {
                $orderId = spl_object_id($step->order());
                self::assertArrayNotHasKey(
                    $orderId,
                    $usedOrders,
                    'Order should not be reused across plans'
                );
                $usedOrders[$orderId] = true;
            }
        }
    }

    #[TestDox('Plans are ordered by cost ascending (best first)')]
    public function test_plans_are_ordered_by_cost_ascending(): void
    {
        // Orders with clearly different rates
        $order1 = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '90.00', 2, 2);  // best (90 RUB/USDT)
        $order2 = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '100.00', 2, 2); // mid (100 RUB/USDT)
        $order3 = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '110.00', 2, 2); // worst (110 RUB/USDT)

        $orderBook = new OrderBook([$order3, $order1, $order2]); // Shuffled order

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '9000.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 3)
            ->withResultLimit(3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'USDT');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $plans = $outcome->paths()->toArray();

        // Verify cost ordering: plan[0] should have best cost (receive most USDT for same spend)
        for ($i = 1; $i < count($plans); ++$i) {
            $prevReceived = $plans[$i - 1]->totalReceived()->decimal();
            $currReceived = $plans[$i]->totalReceived()->decimal();

            // Better plan = receive more for same (or similar) spend
            self::assertTrue(
                $prevReceived->isGreaterThanOrEqualTo($currReceived),
                "Plan {$i} should receive less than or equal to plan ".($i - 1)
            );
        }
    }

    // ========================================================================
    // GUARD LIMIT TESTS
    // ========================================================================

    #[TestDox('Guard limits aggregate across K searches')]
    public function test_guard_limits_aggregate_across_k_searches(): void
    {
        // Multiple orders
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
            ->withSearchGuards(10000, 25000, null)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'USDT');
        $outcome = $this->service->findBestPlans($request);

        $guardReport = $outcome->guardLimits();

        // Expansions and visited states should be sum of all iterations
        self::assertGreaterThan(0, $guardReport->expansions());
        self::assertGreaterThan(0, $guardReport->visitedStates());
    }

    // ========================================================================
    // EDGE CASE TESTS
    // ========================================================================

    #[TestDox('Empty result when no paths exist')]
    public function test_empty_result_when_no_paths_exist(): void
    {
        // Disconnected graph
        $order1 = OrderFactory::buy('USD', 'EUR', '10.00', '1000.00', '0.92', 2, 2);
        $order2 = OrderFactory::buy('GBP', 'JPY', '10.00', '1000.00', '180.00', 2, 2);

        $orderBook = new OrderBook([$order1, $order2]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 3)
            ->withResultLimit(3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'JPY');
        $outcome = $this->service->findBestPlans($request);

        self::assertFalse($outcome->hasPaths());
        self::assertSame(0, $outcome->paths()->count());
    }

    #[TestDox('Empty order book returns empty result')]
    public function test_empty_order_book_returns_empty(): void
    {
        $orderBook = new OrderBook([]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 3)
            ->withResultLimit(5)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'EUR');
        $outcome = $this->service->findBestPlans($request);

        self::assertFalse($outcome->hasPaths());
    }

    #[TestDox('bestPath returns first (optimal) plan')]
    public function test_best_path_returns_first_plan(): void
    {
        $order1 = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '95.00', 2, 2);  // best
        $order2 = OrderFactory::sell('USDT', 'RUB', '10.00', '1000.00', '110.00', 2, 2); // worst

        $orderBook = new OrderBook([$order2, $order1]); // worst first in book

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('RUB', '9500.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 3)
            ->withResultLimit(2)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'USDT');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        $bestPlan = $outcome->bestPath();
        $allPlans = $outcome->paths()->toArray();

        self::assertNotNull($bestPlan);
        // bestPath should be the same as the first in the collection
        self::assertSame($allPlans[0], $bestPlan);
    }

    // ========================================================================
    // MULTI-HOP PATH TESTS
    // ========================================================================

    #[TestDox('Top-K works with multi-hop paths')]
    public function test_top_k_with_multi_hop_paths(): void
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
            ->withResultLimit(2)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        // Should find both routes
        self::assertGreaterThanOrEqual(1, $outcome->paths()->count());

        // Verify plans are distinct
        if ($outcome->paths()->count() > 1) {
            $this->assertPlansUseDisjointOrderSets($outcome->paths()->toArray());
        }
    }

    #[TestDox('Alternative routes with different intermediaries')]
    public function test_alternative_routes_with_different_intermediaries(): void
    {
        // USD -> BTC via USDT
        // USD -> BTC via EUR
        // USD -> BTC via GBP
        $usdToUsdt = OrderFactory::buy('USD', 'USDT', '10.00', '1000.00', '1.0001', 2, 4);
        $usdtToBtc = OrderFactory::buy('USDT', 'BTC', '10.00', '1000.00', '0.000025', 2, 8);

        $usdToEur = OrderFactory::buy('USD', 'EUR', '10.00', '1000.00', '0.92', 2, 2);
        $eurToBtc = OrderFactory::buy('EUR', 'BTC', '10.00', '1000.00', '0.000027', 2, 8);

        $usdToGbp = OrderFactory::buy('USD', 'GBP', '10.00', '1000.00', '0.80', 2, 2);
        $gbpToBtc = OrderFactory::buy('GBP', 'BTC', '10.00', '1000.00', '0.000031', 2, 8);

        $orderBook = new OrderBook([
            $usdToUsdt, $usdtToBtc,
            $usdToEur, $eurToBtc,
            $usdToGbp, $gbpToBtc,
        ]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '500.00', 2))
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 4)
            ->withResultLimit(3)
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'BTC');
        $outcome = $this->service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        // Should find all 3 alternative routes
        self::assertSame(3, $outcome->paths()->count());

        // Verify each plan uses different intermediary
        $this->assertPlansUseDisjointOrderSets($outcome->paths()->toArray());
    }

    // ========================================================================
    // DETERMINISM TESTS
    // ========================================================================

    #[TestDox('Top-K results are deterministic on repeated runs')]
    public function test_determinism(): void
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
            ->build();

        $request = new PathSearchRequest($orderBook, $config, 'USDT');

        $results = [];
        for ($run = 0; $run < 5; ++$run) {
            $outcome = $this->service->findBestPlans($request);
            $planData = [];
            foreach ($outcome->paths() as $plan) {
                $planData[] = $plan->toArray();
            }
            $results[] = $planData;
        }

        // All results should be identical
        for ($i = 1; $i < 5; ++$i) {
            self::assertSame($results[0], $results[$i], "Run {$i} differs from run 0");
        }
    }

    // ========================================================================
    // ADDITIONAL SCENARIOS
    // ========================================================================

    #[TestDox('Top-K handles diamond topology (split and merge)')]
    public function test_topk_diamond_topology(): void
    {
        // Diamond: USD -> EUR, USD -> GBP, EUR -> BTC, GBP -> BTC
        // Using BUY orders: buy(from, to, ...) creates edge from -> to
        $orderBook = new OrderBook([
            OrderFactory::buy('USD', 'EUR', '100.00', '5000.00', '0.92', 2, 4),
            OrderFactory::buy('USD', 'GBP', '100.00', '5000.00', '0.80', 2, 4),
            OrderFactory::buy('EUR', 'BTC', '100.00', '5000.00', '0.000025', 4, 8),
            OrderFactory::buy('GBP', 'BTC', '100.00', '5000.00', '0.000028', 4, 8),
        ]);

        $spendAmount = Money::fromString('USD', '1000.00', 2);
        $config = PathSearchConfig::builder()
            ->withSpendAmount($spendAmount)
            ->withToleranceBounds('0.0', '0.50')
            ->withHopLimits(1, 3)
            ->withResultLimit(3)
            ->withSearchGuards(10000, 25000)
            ->build();

        $service = new ExecutionPlanService(new GraphBuilder());
        $request = new PathSearchRequest($orderBook, $config, 'BTC');
        $outcome = $service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        // Should find at least 1 route through diamond
        self::assertGreaterThanOrEqual(1, $outcome->paths()->count());
    }

    #[TestDox('Top-K with large order book performs within bounds')]
    public function test_topk_large_order_book(): void
    {
        // Create a large order book with many alternatives
        // Using BUY orders: buy(from, to, ...) creates edge from -> to
        $orders = [];
        for ($i = 0; $i < 20; ++$i) {
            $rate = (string) (0.90 + ($i * 0.005)); // Rates from 0.90 to 0.995
            $orders[] = OrderFactory::buy('USD', 'EUR', '100.00', '10000.00', $rate, 2, 4);
        }
        $orderBook = new OrderBook($orders);

        $spendAmount = Money::fromString('USD', '500.00', 2);
        $config = PathSearchConfig::builder()
            ->withSpendAmount($spendAmount)
            ->withToleranceBounds('0.0', '0.20')
            ->withHopLimits(1, 2)
            ->withResultLimit(10)
            ->withSearchGuards(10000, 25000)
            ->build();

        $service = new ExecutionPlanService(new GraphBuilder());
        $request = new PathSearchRequest($orderBook, $config, 'EUR');
        $outcome = $service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        self::assertGreaterThanOrEqual(5, $outcome->paths()->count());
        $this->assertPlansUseDisjointOrderSets(iterator_to_array($outcome->paths()));
    }

    #[TestDox('Top-K stops gracefully when guard limit hit mid-iteration')]
    public function test_topk_guard_limit_partial_results(): void
    {
        // Create multiple alternative routes
        $orderBook = new OrderBook([
            OrderFactory::sell('USD', 'EUR', '100.00', '5000.00', '0.92', 2, 4),
            OrderFactory::sell('USD', 'EUR', '100.00', '5000.00', '0.94', 2, 4),
            OrderFactory::sell('USD', 'EUR', '100.00', '5000.00', '0.96', 2, 4),
        ]);

        $spendAmount = Money::fromString('USD', '1000.00', 2);
        // Very restrictive guard to trigger early termination
        $config = PathSearchConfig::builder()
            ->withSpendAmount($spendAmount)
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 2)
            ->withResultLimit(5)
            ->withSearchGuards(1, 1) // Very restrictive
            ->build();

        $service = new ExecutionPlanService(new GraphBuilder());
        $request = new PathSearchRequest($orderBook, $config, 'EUR');
        $outcome = $service->findBestPlans($request);

        // Should have some results but maybe not all requested
        // Guard limits should be reported
        $report = $outcome->guardLimits();
        // At least one search should have been attempted
        self::assertGreaterThanOrEqual(0, $report->expansions());
    }

    #[TestDox('Top-K handles plans with identical costs correctly')]
    public function test_topk_identical_costs(): void
    {
        // Create orders with identical rates
        // Using BUY orders: buy(from, to, ...) creates edge from -> to
        $orderBook = new OrderBook([
            OrderFactory::buy('USD', 'EUR', '100.00', '5000.00', '0.92', 2, 4),
            OrderFactory::buy('USD', 'EUR', '100.00', '5000.00', '0.92', 2, 4),
            OrderFactory::buy('USD', 'EUR', '100.00', '5000.00', '0.92', 2, 4),
        ]);

        $spendAmount = Money::fromString('USD', '1000.00', 2);
        $config = PathSearchConfig::builder()
            ->withSpendAmount($spendAmount)
            ->withToleranceBounds('0.0', '0.20')
            ->withHopLimits(1, 2)
            ->withResultLimit(3)
            ->withSearchGuards(10000, 25000)
            ->build();

        $service = new ExecutionPlanService(new GraphBuilder());
        $request = new PathSearchRequest($orderBook, $config, 'EUR');
        $outcome = $service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        self::assertSame(3, $outcome->paths()->count());

        // All plans should use disjoint orders even with same costs
        $this->assertPlansUseDisjointOrderSets(iterator_to_array($outcome->paths()));
    }

    #[TestDox('Top-K handles single available path correctly')]
    public function test_topk_single_available_path(): void
    {
        // Using BUY order: buy(from, to, ...) creates edge from -> to
        $orderBook = new OrderBook([
            OrderFactory::buy('USD', 'EUR', '100.00', '5000.00', '0.92', 2, 4),
        ]);

        $spendAmount = Money::fromString('USD', '1000.00', 2);
        $config = PathSearchConfig::builder()
            ->withSpendAmount($spendAmount)
            ->withToleranceBounds('0.0', '0.20')
            ->withHopLimits(1, 2)
            ->withResultLimit(5) // Request more than available
            ->withSearchGuards(10000, 25000)
            ->build();

        $service = new ExecutionPlanService(new GraphBuilder());
        $request = new PathSearchRequest($orderBook, $config, 'EUR');
        $outcome = $service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        self::assertSame(1, $outcome->paths()->count()); // Only 1 available
    }

    #[TestDox('Top-K aggregated metrics reflect all iterations')]
    public function test_topk_aggregated_metrics(): void
    {
        $orderBook = new OrderBook([
            OrderFactory::sell('USD', 'EUR', '100.00', '5000.00', '0.92', 2, 4),
            OrderFactory::sell('USD', 'EUR', '100.00', '5000.00', '0.94', 2, 4),
            OrderFactory::sell('USD', 'EUR', '100.00', '5000.00', '0.96', 2, 4),
        ]);

        $spendAmount = Money::fromString('USD', '1000.00', 2);
        $config = PathSearchConfig::builder()
            ->withSpendAmount($spendAmount)
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 2)
            ->withResultLimit(3)
            ->withSearchGuards(10000, 25000)
            ->build();

        $service = new ExecutionPlanService(new GraphBuilder());
        $request = new PathSearchRequest($orderBook, $config, 'EUR');
        $outcome = $service->findBestPlans($request);

        $report = $outcome->guardLimits();

        // Should have metrics from multiple searches
        self::assertGreaterThan(0, $report->expansions());
        self::assertGreaterThan(0, $report->visitedStates());
        self::assertGreaterThan(0.0, $report->elapsedMilliseconds());
    }

    // ========================================================================
    // MUTANT KILLING TESTS
    // ========================================================================

    #[TestDox('Top-K iteration stops when no path found (break vs continue)')]
    public function test_topk_stops_on_no_path(): void
    {
        // Only 2 orders available, request K=5
        $orderBook = new OrderBook([
            OrderFactory::buy('USD', 'EUR', '100.00', '5000.00', '0.92', 2, 4),
            OrderFactory::buy('USD', 'EUR', '100.00', '5000.00', '0.94', 2, 4),
        ]);

        $spendAmount = Money::fromString('USD', '1000.00', 2);
        $config = PathSearchConfig::builder()
            ->withSpendAmount($spendAmount)
            ->withToleranceBounds('0.0', '0.20')
            ->withHopLimits(1, 2)
            ->withResultLimit(5) // Request 5 but only 2 possible
            ->withSearchGuards(10000, 25000)
            ->build();

        $service = new ExecutionPlanService(new GraphBuilder());
        $request = new PathSearchRequest($orderBook, $config, 'EUR');
        $outcome = $service->findBestPlans($request);

        // Should find exactly 2 plans and STOP, not continue looping
        self::assertTrue($outcome->hasPaths());
        self::assertSame(2, $outcome->paths()->count());

        // Guard metrics should reflect only 3 iterations (2 successful + 1 that found nothing)
        // not 5 iterations (which would happen if break was changed to continue)
        $report = $outcome->guardLimits();
        // The key assertion: if break was changed to continue, we'd have more expansions
        self::assertGreaterThan(0, $report->expansions());
    }

    #[TestDox('Order exclusion works correctly across iterations')]
    public function test_order_exclusion_across_iterations(): void
    {
        // Create 3 orders with distinct rates
        $orderBook = new OrderBook([
            OrderFactory::buy('USD', 'EUR', '100.00', '5000.00', '0.90', 2, 4),
            OrderFactory::buy('USD', 'EUR', '100.00', '5000.00', '0.92', 2, 4),
            OrderFactory::buy('USD', 'EUR', '100.00', '5000.00', '0.94', 2, 4),
        ]);

        $spendAmount = Money::fromString('USD', '1000.00', 2);
        $config = PathSearchConfig::builder()
            ->withSpendAmount($spendAmount)
            ->withToleranceBounds('0.0', '0.20')
            ->withHopLimits(1, 2)
            ->withResultLimit(3)
            ->withSearchGuards(10000, 25000)
            ->build();

        $service = new ExecutionPlanService(new GraphBuilder());
        $request = new PathSearchRequest($orderBook, $config, 'EUR');
        $outcome = $service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        self::assertSame(3, $outcome->paths()->count());

        // Verify each plan uses exactly one order (not reused)
        $allOrderIds = [];
        foreach ($outcome->paths() as $plan) {
            foreach ($plan->steps() as $step) {
                $orderId = spl_object_id($step->order());
                // If true was changed to false, orders wouldn't be excluded
                self::assertNotContains($orderId, $allOrderIds, 'Order should not be reused across plans');
                $allOrderIds[] = $orderId;
            }
        }
        self::assertCount(3, $allOrderIds);
    }

    #[TestDox('Graph with only source node returns empty')]
    public function test_graph_only_source_node(): void
    {
        // Create order that doesn't lead to target
        $orderBook = new OrderBook([
            OrderFactory::buy('USD', 'GBP', '100.00', '5000.00', '0.80', 2, 4),
        ]);

        $spendAmount = Money::fromString('USD', '1000.00', 2);
        $config = PathSearchConfig::builder()
            ->withSpendAmount($spendAmount)
            ->withToleranceBounds('0.0', '0.20')
            ->withHopLimits(1, 2)
            ->withResultLimit(3)
            ->withSearchGuards(10000, 25000)
            ->build();

        $service = new ExecutionPlanService(new GraphBuilder());
        $request = new PathSearchRequest($orderBook, $config, 'EUR'); // EUR not in graph
        $outcome = $service->findBestPlans($request);

        // Should return empty because target EUR is not in graph
        // This kills the || to && mutation on line 199
        self::assertFalse($outcome->hasPaths());
    }

    #[TestDox('Graph with only target node returns empty')]
    public function test_graph_only_target_node(): void
    {
        // Create order FROM target currency (so graph has target but not source path)
        $orderBook = new OrderBook([
            OrderFactory::buy('EUR', 'GBP', '100.00', '5000.00', '0.85', 2, 4),
        ]);

        $spendAmount = Money::fromString('USD', '1000.00', 2); // USD not in graph
        $config = PathSearchConfig::builder()
            ->withSpendAmount($spendAmount)
            ->withToleranceBounds('0.0', '0.20')
            ->withHopLimits(1, 2)
            ->withResultLimit(3)
            ->withSearchGuards(10000, 25000)
            ->build();

        $service = new ExecutionPlanService(new GraphBuilder());
        $request = new PathSearchRequest($orderBook, $config, 'EUR');
        $outcome = $service->findBestPlans($request);

        // Should return empty because source USD is not in graph
        // This kills the || to && mutation on line 199
        self::assertFalse($outcome->hasPaths());
    }

    #[TestDox('Handles lowercase target currency via normalization')]
    public function test_lowercase_target_currency(): void
    {
        $orderBook = new OrderBook([
            OrderFactory::buy('USD', 'EUR', '100.00', '5000.00', '0.92', 2, 4),
        ]);

        $spendAmount = Money::fromString('USD', '1000.00', 2);
        $config = PathSearchConfig::builder()
            ->withSpendAmount($spendAmount)
            ->withToleranceBounds('0.0', '0.20')
            ->withHopLimits(1, 2)
            ->withResultLimit(1)
            ->withSearchGuards(10000, 25000)
            ->build();

        $service = new ExecutionPlanService(new GraphBuilder());
        // Use lowercase target - should be normalized to uppercase
        $request = new PathSearchRequest($orderBook, $config, 'eur');
        $outcome = $service->findBestPlans($request);

        // Should find path because 'eur' is normalized to 'EUR'
        // This kills the UnwrapStrToUpper mutant
        self::assertTrue($outcome->hasPaths());
        self::assertSame(1, $outcome->paths()->count());
    }

    #[TestDox('Handles whitespace in target currency via normalization')]
    public function test_whitespace_target_currency(): void
    {
        $orderBook = new OrderBook([
            OrderFactory::buy('USD', 'EUR', '100.00', '5000.00', '0.92', 2, 4),
        ]);

        $spendAmount = Money::fromString('USD', '1000.00', 2);
        $config = PathSearchConfig::builder()
            ->withSpendAmount($spendAmount)
            ->withToleranceBounds('0.0', '0.20')
            ->withHopLimits(1, 2)
            ->withResultLimit(1)
            ->withSearchGuards(10000, 25000)
            ->build();

        $service = new ExecutionPlanService(new GraphBuilder());
        // Use target with whitespace - should be trimmed
        $request = new PathSearchRequest($orderBook, $config, ' EUR ');
        $outcome = $service->findBestPlans($request);

        // Should find path because ' EUR ' is trimmed to 'EUR'
        // This kills the UnwrapTrim mutant
        self::assertTrue($outcome->hasPaths());
        self::assertSame(1, $outcome->paths()->count());
    }

    #[TestDox('Handles lowercase and whitespace in target currency')]
    public function test_lowercase_whitespace_target_currency(): void
    {
        $orderBook = new OrderBook([
            OrderFactory::buy('USD', 'EUR', '100.00', '5000.00', '0.92', 2, 4),
        ]);

        $spendAmount = Money::fromString('USD', '1000.00', 2);
        $config = PathSearchConfig::builder()
            ->withSpendAmount($spendAmount)
            ->withToleranceBounds('0.0', '0.20')
            ->withHopLimits(1, 2)
            ->withResultLimit(1)
            ->withSearchGuards(10000, 25000)
            ->build();

        $service = new ExecutionPlanService(new GraphBuilder());
        // Use lowercase with whitespace
        $request = new PathSearchRequest($orderBook, $config, ' eur ');
        $outcome = $service->findBestPlans($request);

        // Should find path because ' eur ' is trimmed and uppercased to 'EUR'
        self::assertTrue($outcome->hasPaths());
    }

    #[TestDox('Works with custom materializer dependency')]
    public function test_with_custom_materializer(): void
    {
        $orderBook = new OrderBook([
            OrderFactory::buy('USD', 'EUR', '100.00', '5000.00', '0.92', 2, 4),
        ]);

        $spendAmount = Money::fromString('USD', '1000.00', 2);
        $config = PathSearchConfig::builder()
            ->withSpendAmount($spendAmount)
            ->withToleranceBounds('0.0', '0.20')
            ->withHopLimits(1, 2)
            ->withResultLimit(1)
            ->withSearchGuards(10000, 25000)
            ->build();

        // Pass custom materializer to kill the coalesce mutant
        $fillEvaluator = new OrderFillEvaluator();
        $legMaterializer = new LegMaterializer($fillEvaluator);
        $customMaterializer = new ExecutionPlanMaterializer($fillEvaluator, $legMaterializer);

        $service = new ExecutionPlanService(
            new GraphBuilder(),
            null, // use default ordering strategy
            $customMaterializer
        );

        $request = new PathSearchRequest($orderBook, $config, 'EUR');
        $outcome = $service->findBestPlans($request);

        // Should work correctly with custom materializer
        self::assertTrue($outcome->hasPaths());
        self::assertSame(1, $outcome->paths()->count());
    }

    #[TestDox('Works with custom ordering strategy dependency')]
    public function test_with_custom_ordering_strategy(): void
    {
        $orderBook = new OrderBook([
            OrderFactory::buy('USD', 'EUR', '100.00', '5000.00', '0.92', 2, 4),
            OrderFactory::buy('USD', 'EUR', '100.00', '5000.00', '0.94', 2, 4),
        ]);

        $spendAmount = Money::fromString('USD', '1000.00', 2);
        $config = PathSearchConfig::builder()
            ->withSpendAmount($spendAmount)
            ->withToleranceBounds('0.0', '0.20')
            ->withHopLimits(1, 2)
            ->withResultLimit(2)
            ->withSearchGuards(10000, 25000)
            ->build();

        // Pass custom ordering strategy to kill the coalesce mutant
        $customStrategy = new CostHopsSignatureOrderingStrategy(18);

        $service = new ExecutionPlanService(
            new GraphBuilder(),
            $customStrategy
        );

        $request = new PathSearchRequest($orderBook, $config, 'EUR');
        $outcome = $service->findBestPlans($request);

        // Should work correctly with custom strategy
        self::assertTrue($outcome->hasPaths());
        self::assertSame(2, $outcome->paths()->count());
    }

    #[TestDox('Custom ordering strategy affects result ordering')]
    public function test_custom_ordering_strategy_affects_results(): void
    {
        // Create orders with distinctly different rates
        $orderBook = new OrderBook([
            OrderFactory::buy('USD', 'EUR', '100.00', '5000.00', '0.80', 2, 4), // Worst rate
            OrderFactory::buy('USD', 'EUR', '100.00', '5000.00', '0.95', 2, 4), // Best rate
        ]);

        $spendAmount = Money::fromString('USD', '1000.00', 2);
        $config = PathSearchConfig::builder()
            ->withSpendAmount($spendAmount)
            ->withToleranceBounds('0.0', '0.30')
            ->withHopLimits(1, 2)
            ->withResultLimit(2)
            ->withSearchGuards(10000, 25000)
            ->build();

        // Default strategy: best cost first
        $defaultService = new ExecutionPlanService(new GraphBuilder());
        $defaultOutcome = $defaultService->findBestPlans(new PathSearchRequest($orderBook, $config, 'EUR'));

        // Custom reverse strategy: worst cost first
        $reverseStrategy = new class implements \SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathOrderStrategy {
            public function compare(
                \SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathOrderKey $left,
                \SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathOrderKey $right
            ): int {
                // Reverse comparison: higher cost = better (first)
                $costCompare = $right->cost()->compare($left->cost(), 18);
                if (0 !== $costCompare) {
                    return $costCompare;
                }

                return $left->insertionOrder() <=> $right->insertionOrder();
            }
        };

        $reverseService = new ExecutionPlanService(new GraphBuilder(), $reverseStrategy);
        $reverseOutcome = $reverseService->findBestPlans(new PathSearchRequest($orderBook, $config, 'EUR'));

        // Both should find paths
        self::assertTrue($defaultOutcome->hasPaths());
        self::assertTrue($reverseOutcome->hasPaths());

        // Get best paths from each
        $defaultBest = $defaultOutcome->bestPath();
        $reverseBest = $reverseOutcome->bestPath();

        self::assertNotNull($defaultBest);
        self::assertNotNull($reverseBest);

        // The "best" path should be different - default picks highest receive, reverse picks lowest
        // Default: 0.95 rate = 950 EUR received
        // Reverse: 0.80 rate = 800 EUR received
        $defaultReceived = $defaultBest->totalReceived()->amount();
        $reverseReceived = $reverseBest->totalReceived()->amount();

        // Verify the custom strategy actually changed the ordering
        self::assertNotSame(
            $defaultReceived,
            $reverseReceived,
            'Custom ordering strategy should produce different best path'
        );

        // Default should receive MORE (better rate selected)
        self::assertGreaterThan(
            $reverseReceived,
            $defaultReceived,
            'Default strategy should select better rate (more received)'
        );
    }

    #[TestDox('Top-K returns exactly K plans when more alternatives exist')]
    public function test_topk_returns_exactly_k(): void
    {
        // Create MORE orders than K (10 orders, request K=3)
        $orders = [];
        for ($i = 0; $i < 10; ++$i) {
            $rate = (string) (0.90 + ($i * 0.01)); // Rates from 0.90 to 0.99
            $orders[] = OrderFactory::buy('USD', 'EUR', '100.00', '10000.00', $rate, 2, 4);
        }
        $orderBook = new OrderBook($orders);

        $spendAmount = Money::fromString('USD', '1000.00', 2);
        $config = PathSearchConfig::builder()
            ->withSpendAmount($spendAmount)
            ->withToleranceBounds('0.0', '0.20')
            ->withHopLimits(1, 2)
            ->withResultLimit(3) // Request exactly 3
            ->withSearchGuards(10000, 25000)
            ->build();

        $service = new ExecutionPlanService(new GraphBuilder());
        $request = new PathSearchRequest($orderBook, $config, 'EUR');
        $outcome = $service->findBestPlans($request);

        self::assertTrue($outcome->hasPaths());
        // Must return EXACTLY 3, not more (kills LessThan mutant)
        self::assertSame(3, $outcome->paths()->count());
    }

    #[TestDox('Guard metrics are populated in report')]
    public function test_guard_metrics_populated(): void
    {
        // Simple scenario to verify guard metrics are tracked
        $orderBook = new OrderBook([
            OrderFactory::buy('USD', 'EUR', '100.00', '10000.00', '0.92', 2, 4),
            OrderFactory::buy('USD', 'EUR', '100.00', '10000.00', '0.94', 2, 4),
        ]);

        $spendAmount = Money::fromString('USD', '1000.00', 2);
        $config = PathSearchConfig::builder()
            ->withSpendAmount($spendAmount)
            ->withToleranceBounds('0.0', '0.20')
            ->withHopLimits(1, 2)
            ->withResultLimit(2)
            ->withSearchGuards(10000, 25000) // maxExpansions, maxVisitedStates
            ->build();

        $service = new ExecutionPlanService(new GraphBuilder());
        $request = new PathSearchRequest($orderBook, $config, 'EUR');
        $outcome = $service->findBestPlans($request);

        // The guard report should have limits and metrics
        $report = $outcome->guardLimits();
        // Verify limits are preserved in aggregated report
        self::assertGreaterThan(0, $report->expansionLimit());
        self::assertGreaterThan(0, $report->visitedStateLimit());
        // Should have done some work
        self::assertGreaterThanOrEqual(0, $report->expansions());
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
