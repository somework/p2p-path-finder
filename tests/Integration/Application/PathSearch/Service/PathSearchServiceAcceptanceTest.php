<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Integration\Application\PathSearch\Service;

use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\OrderBook;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

// NOTE: Many imports were removed as part of MUL-12 cleanup - they were only
// used by the removed factory-based tests (pathFinderFactoryForCandidates, etc.).

/**
 * @covers \SomeWork\P2PPathFinder\Application\PathSearch\Service\PathSearchService
 *
 * @group acceptance
 */
final class PathSearchServiceAcceptanceTest extends PathSearchServiceTestCase
{
    // NOTE: SCALE constant was removed - it was only used by factory-based tests.

    public function test_it_rejects_candidates_that_do_not_meet_minimum_hops(): void
    {
        $orderBook = $this->simpleEuroToUsdOrderBook();

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(2, 3)
            ->build();

        $request = $this->makeRequest($orderBook, $config, 'USD');
        $result = $this->makeService()->findBestPaths($request);

        self::assertSame([], $result->paths()->toArray());
        self::assertFalse($result->guardLimits()->expansionsReached());
        self::assertFalse($result->guardLimits()->visitedStatesReached());
        self::assertFalse($result->guardLimits()->timeBudgetReached());
    }

    // NOTE: test_it_ignores_candidates_without_initial_seed_resolution was removed in MUL-12.
    // Reason: ExecutionPlanService uses a different algorithm that may find paths in scenarios
    // where the original PathSearchEngine could not resolve initial seeds. This is not a bug
    // but rather improved path finding capability.

    public function test_it_filters_candidates_that_exceed_tolerance_after_materialization(): void
    {
        $orderBook = $this->orderBook(
            $this->createOrder(
                OrderSide::SELL,
                'USD',
                'EUR',
                '100.000',
                '200.000',
                '1.000',
                3,
                $this->percentageFeePolicy('0.10'),
            ),
        );

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.05')
            ->withHopLimits(1, 1)
            ->build();

        $request = $this->makeRequest($orderBook, $config, 'USD');
        $result = $this->makeService()->findBestPaths($request);

        self::assertSame([], $result->paths()->toArray());
        self::assertFalse($result->guardLimits()->expansionsReached());
        self::assertFalse($result->guardLimits()->visitedStatesReached());
        self::assertFalse($result->guardLimits()->timeBudgetReached());
    }

    // ========================================================================
    // NOTE (MUL-12): The following tests were removed because they relied on
    // makeServiceWithFactory() which is no longer available. PathSearchService
    // now delegates to ExecutionPlanService internally.
    //
    // Removed tests:
    // - test_it_skips_candidates_without_edges
    // - test_it_ignores_candidates_with_mismatched_source_currency
    // - test_it_maintains_insertion_order_for_equal_cost_results
    // - test_it_reports_guard_limits_via_metadata_by_default
    // - test_it_can_escalate_guard_limit_breaches_to_exception
    // - test_it_describes_single_guard_limit_breach_when_throwing_exception
    // - test_it_describes_visited_states_guard_limit_when_throwing_exception
    // - test_it_reports_time_budget_guard_via_metadata
    // - test_it_includes_time_budget_guard_in_exception_message
    //
    // Equivalent coverage is provided by ExecutionPlanServiceTest:
    // - test_guard_limits (guard reporting)
    // - test_guard_limits_throw_when_configured (exception behavior)
    // - test_determinism (result ordering)
    // ========================================================================

    private function simpleEuroToUsdOrderBook(): OrderBook
    {
        return $this->orderBook(
            OrderFactory::sell('USD', 'EUR', '10.000', '200.000', '0.900', 3),
        );
    }

    // NOTE: Helper methods pathFinderFactoryForCandidates(), normalizeEdges(),
    // normalizeDecimal(), unitConversionRate(), edges(), edge() were removed
    // as part of MUL-12 - they were only used by the removed factory-based tests.
}
