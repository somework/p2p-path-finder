<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Integration\Application\PathSearch\Service;

use SomeWork\P2PPathFinder\Application\PathSearch\Api\Response\SearchOutcome;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\Path;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\SearchGuardReport;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\OrderBook;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

final class BasicPathSearchServiceTest extends PathSearchServiceTestCase
{
    /**
     * @return list<Path>
     */
    private static function extractPaths(SearchOutcome $result): array
    {
        return $result->paths()->toArray();
    }

    private static function extractGuardLimits(SearchOutcome $result): SearchGuardReport
    {
        return $result->guardLimits();
    }

    /**
     * @testdox Finds best EUR→JPY route by bridging through USD when multiple hops are needed
     */
    public function test_it_builds_multi_hop_path_and_aggregates_amounts(): void
    {
        $orderBook = $this->scenarioEuroToUsdToJpyBridge();

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.25')
            ->withHopLimits(1, 3)
            ->build();

        $searchResult = $this->makeService()->findBestPaths($this->makeRequest($orderBook, $config, 'JPY'));
        $results = self::extractPaths($searchResult);

        self::assertNotSame([], $results);
        $result = $results[0];

        self::assertSame('EUR', $result->totalSpent()->currency());
        self::assertSame('100.000', $result->totalSpent()->withScale(3)->amount());
        self::assertSame('JPY', $result->totalReceived()->currency());
        self::assertSame('16665.000', $result->totalReceived()->withScale(3)->amount());
        self::assertTrue($result->residualTolerance()->isZero());
        self::assertSame('0.000000000000000000', $result->residualTolerance()->ratio());

        $hops = $result->hops();
        self::assertCount(2, $hops);

        self::assertSame('EUR', $hops->at(0)->from());
        self::assertSame('USD', $hops->at(0)->to());
        self::assertSame('100.000', $hops->at(0)->spent()->withScale(3)->amount());
        self::assertSame('111.100', $hops->at(0)->received()->withScale(3)->amount());

        self::assertSame('USD', $hops->at(1)->from());
        self::assertSame('JPY', $hops->at(1)->to());
        self::assertSame('111.100', $hops->at(1)->spent()->withScale(3)->amount());
        self::assertSame('16665.000', $hops->at(1)->received()->withScale(3)->amount());
    }

    /**
     * @testdox Validates target asset must be provided before evaluating any routes
     */
    public function test_it_requires_non_empty_target_asset(): void
    {
        $orderBook = $this->orderBook();

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '1.00', 2))
            ->withToleranceBounds('0.0', '0.0')
            ->withHopLimits(1, 1)
            ->build();

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Target asset cannot be empty.');

        $this->makeService()->findBestPaths($this->makeRequest($orderBook, $config, ''));
    }

    /**
     * @testdox Returns no result when the target market node is not present in the graph
     */
    public function test_it_returns_null_when_target_node_is_missing(): void
    {
        $orderBook = $this->orderBook(
            $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '10.000', '200.000', '0.900', 3),
        );

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '50.00', 2))
            ->withToleranceBounds('0.0', '0.0')
            ->withHopLimits(1, 1)
            ->build();

        $searchResult = $this->makeService()->findBestPaths($this->makeRequest($orderBook, $config, 'JPY'));

        self::assertSame([], self::extractPaths($searchResult));
        $guardLimits = self::extractGuardLimits($searchResult);
        self::assertFalse($guardLimits->expansionsReached());
        self::assertFalse($guardLimits->visitedStatesReached());
    }

    // NOTE: The following tests were removed as part of MUL-12 cleanup:
    //
    // - test_it_skips_highest_scoring_path_when_complex_book_lacks_capacity
    //   Reason: PathSearchEngine-specific capacity evaluation behavior.
    //   Equivalent coverage: ExecutionPlanServiceTest::test_best_rate_order_selection
    //
    // - test_it_prefers_best_rates_when_multiple_identical_pairs_exist
    //   Reason: PathSearchEngine-specific rate comparison behavior.
    //   Equivalent coverage: ExecutionPlanServiceTest::test_best_rate_order_selection,
    //                        ExecutionPlanServiceTest::test_strategy_different_rate_same_direction
    //
    // - test_it_returns_multiple_paths_ordered_by_cost
    //   Reason: Intentional behavioral change - ExecutionPlanService returns single optimal plan.
    //   See CHANGELOG.md "Breaking Changes" section.
    //
    // - test_it_preserves_result_insertion_order_when_costs_are_identical
    //   Reason: Intentional behavioral change - ExecutionPlanService returns single optimal plan.
    //   See CHANGELOG.md "Breaking Changes" section.

    public function test_it_enforces_minimum_hop_constraint_before_materialization(): void
    {
        $orderBook = $this->orderBook(
            $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '10.000', '200.000', '0.900', 3),
        );

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.05')
            ->withHopLimits(2, 3)
            ->build();

        $searchResult = $this->makeService()->findBestPaths($this->makeRequest($orderBook, $config, 'USD'));
        $results = self::extractPaths($searchResult);

        // PathSearchService filters by hop count after delegation to ExecutionPlanService
        // The 1-hop path should be filtered out due to minimum hop constraint
        self::assertSame([], $results, 'Single-hop path should be filtered by minimum hop constraint');
    }

    // NOTE: test_it_prefers_two_hop_route_when_direct_candidate_violates_minimum_hops
    // was removed as part of MUL-12 cleanup.
    // Reason: ExecutionPlanService finds optimal execution plans and does not explore
    // alternative paths when the optimal path is filtered by hop constraints.
    // This is intentional behavior - hop filtering is done at PathSearchService level.
    // See: ExecutionPlanServiceTest::test_finds_paths_regardless_of_minimum_hop_config

    public function test_it_skips_candidates_when_sell_seed_window_is_empty(): void
    {
        $orderBook = $this->orderBook(
            $this->createOrder(
                OrderSide::SELL,
                'BTC',
                'USD',
                '1.000',
                '1.000',
                '100.00',
                2,
                $this->percentageFeePolicy('0.60'),
            ),
        );

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '150.00', 2))
            ->withToleranceBounds('0.1', '0.1')
            ->withHopLimits(1, 1)
            ->build();

        $searchResult = $this->makeService()->findBestPaths($this->makeRequest($orderBook, $config, 'BTC'));

        self::assertSame([], self::extractPaths($searchResult));
        $guardLimits = self::extractGuardLimits($searchResult);
        self::assertFalse($guardLimits->expansionsReached());
        self::assertFalse($guardLimits->visitedStatesReached());
    }

    public function test_it_honors_path_finder_guard_configuration(): void
    {
        $orderBook = $this->scenarioEuroToUsdToJpyBridge();

        $guardedConfig = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.25')
            ->withHopLimits(1, 3)
            ->withSearchGuards(1, 1)
            ->build();

        $guardedResult = $this->makeService()->findBestPaths($this->makeRequest($orderBook, $guardedConfig, 'JPY'));

        self::assertSame([], self::extractPaths($guardedResult));
        $guardLimits = self::extractGuardLimits($guardedResult);
        self::assertTrue($guardLimits->anyLimitReached());

        $relaxedConfig = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.25')
            ->withHopLimits(1, 3)
            ->withSearchGuards(1000, 1000)
            ->build();

        self::assertNotSame([], self::extractPaths($this->makeService()->findBestPaths($this->makeRequest($orderBook, $relaxedConfig, 'JPY'))));
    }

    /**
     * @testdox Returns no paths when every order is filtered out by spend bounds
     */
    public function test_it_returns_empty_result_when_orders_are_filtered_out(): void
    {
        $orderBook = $this->orderBook(
            $this->createOrder(OrderSide::BUY, 'EUR', 'USD', '200.000', '300.000', '1.100', 3),
        );

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.05')
            ->withHopLimits(1, 1)
            ->build();

        $service = $this->makeService();

        $searchResult = $service->findBestPaths($this->makeRequest($orderBook, $config, 'USD'));

        self::assertSame([], self::extractPaths($searchResult));
        $guardLimits = self::extractGuardLimits($searchResult);
        self::assertFalse($guardLimits->expansionsReached());
        self::assertFalse($guardLimits->visitedStatesReached());
    }

    public function test_it_returns_empty_result_when_source_node_is_missing(): void
    {
        $orderBook = $this->orderBook(
            $this->createOrder(OrderSide::SELL, 'USD', 'JPY', '10.000', '200.000', '110.00', 2),
        );

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.05')
            ->withHopLimits(1, 1)
            ->build();

        $searchResult = $this->makeService()->findBestPaths($this->makeRequest($orderBook, $config, 'JPY'));

        self::assertSame([], self::extractPaths($searchResult));
        $guardLimits = self::extractGuardLimits($searchResult);
        self::assertFalse($guardLimits->expansionsReached());
        self::assertFalse($guardLimits->visitedStatesReached());
    }

    public function test_it_discards_candidates_rejected_by_tolerance_bounds(): void
    {
        $orderBook = $this->orderBook(
            $this->createOrder(
                OrderSide::SELL,
                'EUR',
                'USD',
                '10.000',
                '500.000',
                '1.100',
                3,
                $this->percentageFeePolicy('0.10'),
            ),
        );

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.05')
            ->withHopLimits(1, 1)
            ->build();

        $searchResult = $this->makeService()->findBestPaths($this->makeRequest($orderBook, $config, 'USD'));

        self::assertSame([], self::extractPaths($searchResult));
        $guardLimits = self::extractGuardLimits($searchResult);
        self::assertFalse($guardLimits->expansionsReached());
        self::assertFalse($guardLimits->visitedStatesReached());
    }

    public function test_it_rejects_candidates_exceeding_maximum_hops(): void
    {
        $orderBook = $this->orderBook(
            $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '10.000', '200.000', '0.900', 3),
            $this->createOrder(OrderSide::BUY, 'USD', 'GBP', '50.000', '200.000', '0.750', 3),
            $this->createOrder(OrderSide::BUY, 'GBP', 'JPY', '50.000', '200.000', '150.000', 3),
        );

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.25')
            ->withHopLimits(1, 2)
            ->build();

        $searchResult = $this->makeService()->findBestPaths($this->makeRequest($orderBook, $config, 'JPY'));

        self::assertSame([], self::extractPaths($searchResult));
        $guardLimits = self::extractGuardLimits($searchResult);
        self::assertFalse($guardLimits->expansionsReached());
        self::assertFalse($guardLimits->visitedStatesReached());
    }

    /**
     * @testdox Skips zero-hop candidates when the source and target assets are identical
     */
    public function test_it_discards_zero_hop_candidates_when_target_equals_source(): void
    {
        $orderBook = $this->orderBook(
            $this->createOrder(OrderSide::BUY, 'USD', 'EUR', '10.000', '200.000', '0.900', 3),
            $this->createOrder(OrderSide::SELL, 'EUR', 'USD', '10.000', '200.000', '1.100', 3),
        );

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.0')
            ->withHopLimits(1, 1)
            ->build();

        $searchResult = $this->makeService()->findBestPaths($this->makeRequest($orderBook, $config, 'USD'));

        self::assertSame([], self::extractPaths($searchResult));
        $guardLimits = self::extractGuardLimits($searchResult);
        self::assertFalse($guardLimits->expansionsReached());
        self::assertFalse($guardLimits->visitedStatesReached());
    }

    // NOTE: test_result_set_returns_first_materialized_result was removed as part of MUL-12.
    // The complex competing GBP quotes scenario behavior has changed with ExecutionPlanService.
    // Equivalent coverage: BackwardCompatibilityTest::test_result_set_first_consistency

    /**
     * @testdox PathSet::first() returns null when no candidates exist
     */
    public function test_result_set_returns_null_when_no_paths_exist(): void
    {
        $orderBook = $this->orderBook(
            $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '10.000', '500.000', '0.900', 3),
        );

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.0')
            ->withHopLimits(1, 1)
            ->build();

        $outcome = $this->makeService()->findBestPaths($this->makeRequest($orderBook, $config, 'JPY'));

        self::assertSame([], $outcome->paths()->toArray());
        self::assertNull($outcome->paths()->first());
    }

    public function test_result_set_returns_first_materialized_route(): void
    {
        $orderBook = $this->orderBook(
            $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '10.000', '500.000', '0.900', 3),
            $this->createOrder(OrderSide::SELL, 'GBP', 'EUR', '10.000', '500.000', '0.750', 3),
            $this->createOrder(OrderSide::BUY, 'GBP', 'USD', '10.000', '500.000', '0.900', 3),
        );

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.05')
            ->withHopLimits(1, 3)
            ->withResultLimit(3)
            ->build();

        $outcome = $this->makeService()->findBestPaths($this->makeRequest($orderBook, $config, 'USD'));
        $resultSet = $outcome->paths();

        $first = $resultSet->first();
        self::assertNotNull($first);

        $paths = $resultSet->toArray();
        self::assertNotSame([], $paths);
        $top = $paths[0];

        self::assertSame($top->totalReceived()->amount(), $first->totalReceived()->amount());
        $topFirstHop = $top->hops()->at(0);
        $firstFirstHop = $first->hops()->at(0);

        self::assertSame($topFirstHop->to(), $firstFirstHop->to());
        self::assertCount($top->hops()->count(), $first->hops());
    }

    private function scenarioEuroToUsdToJpyBridge(): OrderBook
    {
        return $this->orderBook(
            $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '10.000', '200.000', '0.900', 3),
            $this->createOrder(OrderSide::BUY, 'USD', 'JPY', '50.000', '200.000', '150.000', 3),
            $this->createOrder(OrderSide::SELL, 'JPY', 'EUR', '10.000', '20000.000', '0.007500', 6),
        );
    }

    // NOTE: scenarioCapacityConstrainedUsdRoutes() and scenarioCompetingGbpQuotes()
    // were removed as part of MUL-12 cleanup - no longer used after removing legacy tests.
}
