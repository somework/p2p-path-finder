<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Service\PathFinder;

use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\SearchGuardReport;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\SearchOutcome;
use SomeWork\P2PPathFinder\Application\Result\PathResult;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

use function count;

final class BasicPathFinderServiceTest extends PathFinderServiceTestCase
{
    /**
     * @return list<PathResult>
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
        self::assertSame('100.000', $result->totalSpent()->amount());
        self::assertSame('JPY', $result->totalReceived()->currency());
        self::assertSame('16665.000', $result->totalReceived()->amount());
        self::assertTrue($result->residualTolerance()->isZero());
        self::assertSame('0.000000000000000000', $result->residualTolerance()->ratio());

        $legs = $result->legs()->all();
        self::assertCount(2, $legs);

        self::assertSame('EUR', $legs[0]->from());
        self::assertSame('USD', $legs[0]->to());
        self::assertSame('100.000', $legs[0]->spent()->amount());
        self::assertSame('111.100', $legs[0]->received()->amount());

        self::assertSame('USD', $legs[1]->from());
        self::assertSame('JPY', $legs[1]->to());
        self::assertSame('111.100', $legs[1]->spent()->amount());
        self::assertSame('16665.000', $legs[1]->received()->amount());
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

    /**
     * @testdox Chooses GBP bridge when the notional best scoring USD route lacks available capacity
     */
    public function test_it_skips_highest_scoring_path_when_complex_book_lacks_capacity(): void
    {
        $orderBook = $this->scenarioCapacityConstrainedUsdRoutes();

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.20')
            ->withHopLimits(1, 3)
            ->build();

        $searchResult = $this->makeService()->findBestPaths($this->makeRequest($orderBook, $config, 'USD'));
        $results = self::extractPaths($searchResult);

        self::assertNotSame([], $results);
        $result = $results[0];

        self::assertSame('EUR', $result->totalSpent()->currency());
        self::assertSame('100.000', $result->totalSpent()->amount());
        self::assertSame('USD', $result->totalReceived()->currency());
        self::assertSame('150.000', $result->totalReceived()->amount());

        $legs = $result->legs();
        self::assertCount(2, $legs);
        self::assertSame('EUR', $legs->at(0)->from());
        self::assertSame('GBP', $legs->at(0)->to());
        self::assertSame('100.000', $legs->at(0)->spent()->amount());
        self::assertSame('125.000', $legs->at(0)->received()->amount());

        self::assertSame('GBP', $legs->at(1)->from());
        self::assertSame('USD', $legs->at(1)->to());
        self::assertSame('125.000', $legs->at(1)->spent()->amount());
        self::assertSame('150.000', $legs->at(1)->received()->amount());
    }

    /**
     * @testdox Picks the most competitive GBP legs when multiple identical pairs quote different rates
     */
    public function test_it_prefers_best_rates_when_multiple_identical_pairs_exist(): void
    {
        $orderBook = $this->scenarioCompetingGbpQuotes();

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.15')
            ->withHopLimits(1, 3)
            ->build();

        $searchResult = $this->makeService()->findBestPaths($this->makeRequest($orderBook, $config, 'USD'));
        $results = self::extractPaths($searchResult);

        self::assertNotSame([], $results);
        $result = $results[0];

        self::assertSame('EUR', $result->totalSpent()->currency());
        self::assertSame('100.000', $result->totalSpent()->amount());
        self::assertSame('USD', $result->totalReceived()->currency());
        self::assertSame('178.625', $result->totalReceived()->amount());

        $legs = $result->legs();
        self::assertCount(2, $legs);

        self::assertSame('EUR', $legs->at(0)->from());
        self::assertSame('GBP', $legs->at(0)->to());
        self::assertSame('100.000', $legs->at(0)->spent()->amount());
        self::assertSame('142.900', $legs->at(0)->received()->amount());

        self::assertSame('GBP', $legs->at(1)->from());
        self::assertSame('USD', $legs->at(1)->to());
        self::assertSame('142.900', $legs->at(1)->spent()->amount());
        self::assertSame('178.625', $legs->at(1)->received()->amount());
    }

    public function test_it_returns_multiple_paths_ordered_by_cost(): void
    {
        $orderBook = $this->orderBook(
            $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '10.000', '500.000', '0.900', 3),
            $this->createOrder(OrderSide::SELL, 'GBP', 'EUR', '10.000', '500.000', '0.850', 3),
            $this->createOrder(OrderSide::BUY, 'GBP', 'USD', '10.000', '500.000', '0.900', 3),
        );

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.05')
            ->withHopLimits(1, 2)
            ->withResultLimit(2)
            ->build();

        $searchResult = $this->makeService()->findBestPaths($this->makeRequest($orderBook, $config, 'USD'));
        $results = self::extractPaths($searchResult);

        self::assertCount(2, $results);

        $first = $results[0];
        $second = $results[1];

        self::assertSame('USD', $first->totalReceived()->currency());
        self::assertSame('USD', $second->totalReceived()->currency());
        self::assertTrue($first->totalReceived()->greaterThan($second->totalReceived()));

        $firstLegs = $first->legs();
        self::assertCount(1, $firstLegs);
        self::assertSame('EUR', $firstLegs->at(0)->from());
        self::assertSame('USD', $firstLegs->at(0)->to());

        $secondLegs = $second->legs();
        self::assertCount(2, $secondLegs);
        self::assertSame('EUR', $secondLegs->at(0)->from());
        self::assertSame('GBP', $secondLegs->at(0)->to());
        self::assertSame('GBP', $secondLegs->at(1)->from());
        self::assertSame('USD', $secondLegs->at(1)->to());
    }

    public function test_it_preserves_result_insertion_order_when_costs_are_identical(): void
    {
        $orderBook = $this->orderBook(
            $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '10.000', '500.000', '0.900', 3),
            $this->createOrder(OrderSide::SELL, 'GBP', 'EUR', '10.000', '500.000', '0.750', 3),
            $this->createOrder(OrderSide::SELL, 'USD', 'GBP', '10.000', '500.000', '1.199', 3),
        );

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.0')
            ->withHopLimits(1, 2)
            ->withResultLimit(2)
            ->build();

        $searchResult = $this->makeService()->findBestPaths($this->makeRequest($orderBook, $config, 'USD'));
        $results = self::extractPaths($searchResult);

        self::assertCount(2, $results);
        self::assertSame('111.172', $results[0]->totalReceived()->amount());
        self::assertSame('111.100', $results[1]->totalReceived()->amount());
        self::assertTrue($results[0]->residualTolerance()->isZero());
        self::assertSame('0.000000000000000000', $results[0]->residualTolerance()->ratio());
        self::assertTrue($results[1]->residualTolerance()->isZero());
        self::assertSame('0.000000000000000000', $results[1]->residualTolerance()->ratio());
        self::assertCount(2, $results[0]->legs());
        self::assertCount(1, $results[1]->legs());
    }

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

        self::assertSame([], self::extractPaths($searchResult));
        $guardLimits = self::extractGuardLimits($searchResult);
        self::assertFalse($guardLimits->expansionsReached());
        self::assertFalse($guardLimits->visitedStatesReached());
    }

    public function test_it_prefers_two_hop_route_when_direct_candidate_violates_minimum_hops(): void
    {
        $orderBook = $this->orderBook(
            $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '10.000', '500.000', '0.900', 3),
            $this->createOrder(OrderSide::SELL, 'GBP', 'EUR', '10.000', '500.000', '0.850', 3),
            $this->createOrder(OrderSide::BUY, 'GBP', 'USD', '10.000', '500.000', '0.900', 3),
        );

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.05')
            ->withHopLimits(2, 3)
            ->build();

        $searchResult = $this->makeService()->findBestPaths($this->makeRequest($orderBook, $config, 'USD'));
        $results = self::extractPaths($searchResult);

        self::assertNotSame([], $results);

        $best = $results[0];
        self::assertSame('EUR', $best->totalSpent()->currency());
        self::assertSame('100.000', $best->totalSpent()->amount());
        self::assertSame('USD', $best->totalReceived()->currency());

        $legs = $best->legs();
        self::assertCount(2, $legs);
        self::assertSame('EUR', $legs->at(0)->from());
        self::assertSame('GBP', $legs->at(0)->to());
        self::assertSame('GBP', $legs->at(1)->from());
        self::assertSame('USD', $legs->at(1)->to());

        foreach ($results as $result) {
            self::assertGreaterThanOrEqual(2, count($result->legs()));
        }
    }

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

    /**
     * @testdox Provides the leading result through PathResultSet::first()
     */
    public function test_result_set_returns_first_materialized_result(): void
    {
        $orderBook = $this->scenarioCompetingGbpQuotes();

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.15')
            ->withHopLimits(1, 3)
            ->withResultLimit(2)
            ->build();

        $service = $this->makeService();

        $outcome = $service->findBestPaths($this->makeRequest($orderBook, $config, 'USD'));
        $resultSet = $outcome->paths();

        $first = $resultSet->first();
        self::assertNotNull($first);

        $paths = $resultSet->toArray();
        self::assertNotSame([], $paths);

        self::assertSame(
            $paths[0]->jsonSerialize(),
            $first->jsonSerialize(),
        );
    }

    /**
     * @testdox PathResultSet::first() returns null when no candidates exist
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
        $topFirstLeg = $top->legs()->at(0);
        $firstFirstLeg = $first->legs()->at(0);

        self::assertSame($topFirstLeg->to(), $firstFirstLeg->to());
        self::assertCount($top->legs()->count(), $first->legs());
    }

    private function scenarioEuroToUsdToJpyBridge(): OrderBook
    {
        return $this->orderBook(
            $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '10.000', '200.000', '0.900', 3),
            $this->createOrder(OrderSide::BUY, 'USD', 'JPY', '50.000', '200.000', '150.000', 3),
            $this->createOrder(OrderSide::SELL, 'JPY', 'EUR', '10.000', '20000.000', '0.007500', 6),
        );
    }

    private function scenarioCapacityConstrainedUsdRoutes(): OrderBook
    {
        return $this->orderBook(
            $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '10.000', '80.000', '0.600', 3),
            $this->createOrder(OrderSide::SELL, 'GBP', 'EUR', '5.000', '500.000', '0.800', 3),
            $this->createOrder(OrderSide::SELL, 'CHF', 'EUR', '5.000', '400.000', '0.920', 3),
            $this->createOrder(OrderSide::SELL, 'AUD', 'EUR', '5.000', '400.000', '0.700', 3),
            $this->createOrder(OrderSide::SELL, 'CAD', 'EUR', '5.000', '400.000', '0.750', 3),
            $this->createOrder(OrderSide::BUY, 'GBP', 'USD', '5.000', '500.000', '1.200', 3),
            $this->createOrder(OrderSide::BUY, 'CHF', 'USD', '5.000', '500.000', '1.050', 3),
            $this->createOrder(OrderSide::BUY, 'AUD', 'USD', '5.000', '500.000', '0.650', 3),
            $this->createOrder(OrderSide::BUY, 'CAD', 'USD', '5.000', '500.000', '0.730', 3),
            $this->createOrder(OrderSide::BUY, 'EUR', 'CHF', '5.000', '500.000', '1.100', 3),
        );
    }

    private function scenarioCompetingGbpQuotes(): OrderBook
    {
        return $this->orderBook(
            $this->createOrder(OrderSide::SELL, 'GBP', 'EUR', '5.000', '80.000', '0.680', 3),
            $this->createOrder(OrderSide::SELL, 'GBP', 'EUR', '5.000', '500.000', '0.760', 3),
            $this->createOrder(OrderSide::SELL, 'GBP', 'EUR', '5.000', '500.000', '0.780', 3),
            $this->createOrder(OrderSide::SELL, 'GBP', 'EUR', '5.000', '500.000', '0.710', 3),
            $this->createOrder(OrderSide::SELL, 'GBP', 'EUR', '5.000', '500.000', '0.700', 3),
            $this->createOrder(OrderSide::BUY, 'GBP', 'USD', '5.000', '80.000', '1.350', 3),
            $this->createOrder(OrderSide::BUY, 'GBP', 'USD', '5.000', '500.000', '1.220', 3),
            $this->createOrder(OrderSide::BUY, 'GBP', 'USD', '5.000', '500.000', '1.200', 3),
            $this->createOrder(OrderSide::BUY, 'GBP', 'USD', '5.000', '500.000', '1.180', 3),
            $this->createOrder(OrderSide::BUY, 'GBP', 'USD', '5.000', '500.000', '1.250', 3),
        );
    }
}
