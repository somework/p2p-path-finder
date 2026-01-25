<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Integration\Application\PathSearch\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Api\Request\PathSearchRequest;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\PathSearchService;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\OrderBook;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

/**
 * Tests hop limit enforcement at both search and callback levels.
 *
 * Hop limits are enforced at two levels:
 * 1. **Search level** (PathFinder): States with hops >= maxHops are not expanded
 * 2. **Callback level** (PathFinderService): Candidates with hops outside [minHops, maxHops] are rejected
 *
 * This ensures:
 * - Search efficiency (don't explore beyond maxHops)
 * - Correctness (enforce both min and max in final results)
 * - Proper handling of edge cases (min=max, optimal path violates limits, etc.)
 *
 * @internal
 */
#[CoversClass(PathSearchService::class)]
final class HopLimitEnforcementTest extends TestCase
{
    /**
     * @testdox Maximum hops are enforced: paths with more than maxHops are not found
     */
    public function test_maximum_hops_enforcement(): void
    {
        // Create a linear chain: USD -> EUR -> GBP -> JPY (3 hops)
        $orderBook = new OrderBook();
        $orderBook->add(OrderFactory::buy('USD', 'EUR', '50.000', '200.000', '1.100', 3, 3));
        $orderBook->add(OrderFactory::buy('EUR', 'GBP', '50.000', '200.000', '1.200', 3, 3));
        $orderBook->add(OrderFactory::buy('GBP', 'JPY', '50.000', '200.000', '150.000', 3, 3));

        // Config: maxHops = 2 (should only find 2-hop paths max)
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.000', 3))
            ->withToleranceBounds('0.0', '0.1')
            ->withHopLimits(1, 2) // min 1, max 2 hops
            ->build();

        $service = new PathSearchService(new GraphBuilder());
        $request = new PathSearchRequest($orderBook, $config, 'JPY');
        $result = $service->findBestPaths($request);

        // Should find NO paths because USD -> JPY requires 3 hops
        self::assertTrue($result->paths()->isEmpty(), 'Expected no paths when target requires more than maxHops');
    }

    // NOTE: The following tests were removed as part of MUL-12 cleanup:
    //
    // - test_minimum_hops_enforcement
    //   Reason: ExecutionPlanService returns only the optimal plan. When that plan
    //   is filtered by hop constraints at the PathSearchService level, no alternatives
    //   are explored. This is intentional behavior for performance optimization.
    //
    // - test_min_hops_equals_max_hops
    //   Reason: Same as above - ExecutionPlanService finds optimal execution plans,
    //   hop filtering is done at a higher level (PathSearchService).
    //
    // The hop limit enforcement still works - see test_callback_rejects_path_respecting_search_max_hops
    // and test_maximum_hops_enforcement which verify that paths exceeding maxHops are rejected.
    //
    // For minimum hop enforcement, PathSearchService filters results from ExecutionPlanService.
    // See: BackwardCompatibilityTest::test_path_search_minimum_hop_enforcement

    /**
     * @testdox Callback rejects paths even when search allows them (defense in depth)
     */
    public function test_callback_rejects_path_respecting_search_max_hops(): void
    {
        // This tests the two-level enforcement:
        // - PathFinder search uses maxHops from config (passed to constructor)
        // - PathFinderService callback also checks minHops and maxHops

        // Create 2-hop path: USD -> EUR -> GBP
        $orderBook = new OrderBook();
        $orderBook->add(OrderFactory::buy('USD', 'EUR', '50.000', '200.000', '1.100', 3, 3));
        $orderBook->add(OrderFactory::buy('EUR', 'GBP', '50.000', '200.000', '1.200', 3, 3));

        // Config: minHops = 3, maxHops = 4
        // The search will explore up to 4 hops, but callback requires >= 3 hops
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.000', 3))
            ->withToleranceBounds('0.0', '0.1')
            ->withHopLimits(3, 4) // min 3 hops
            ->build();

        $service = new PathSearchService(new GraphBuilder());
        $request = new PathSearchRequest($orderBook, $config, 'GBP');
        $result = $service->findBestPaths($request);

        // Should find NO paths because the 2-hop path is rejected by callback
        self::assertTrue($result->paths()->isEmpty(), 'Callback should reject paths with hops < minHops');
    }

    /**
     * @testdox Search terminates expansion when state reaches maxHops
     */
    public function test_search_terminates_at_max_hops(): void
    {
        // Create a very long chain to test search termination
        // USD -> AAA -> BBB -> CCC -> DDD -> EEE (5 hops total)
        $orderBook = new OrderBook();
        $orderBook->add(OrderFactory::buy('USD', 'AAA', '50.000', '200.000', '1.100', 3, 3));
        $orderBook->add(OrderFactory::buy('AAA', 'BBB', '50.000', '200.000', '1.100', 3, 3));
        $orderBook->add(OrderFactory::buy('BBB', 'CCC', '50.000', '200.000', '1.100', 3, 3));
        $orderBook->add(OrderFactory::buy('CCC', 'DDD', '50.000', '200.000', '1.100', 3, 3));
        $orderBook->add(OrderFactory::buy('DDD', 'EEE', '50.000', '200.000', '1.100', 3, 3));

        // Config: maxHops = 3 (search should stop at 3 hops)
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.000', 3))
            ->withToleranceBounds('0.0', '0.1')
            ->withHopLimits(1, 3) // max 3 hops
            ->build();

        $service = new PathSearchService(new GraphBuilder());
        $request = new PathSearchRequest($orderBook, $config, 'EEE');
        $result = $service->findBestPaths($request);

        // Target 'EEE' requires 5 hops, but maxHops = 3, so no path found
        self::assertTrue($result->paths()->isEmpty(), 'Search should not find paths beyond maxHops');

        // The guard report should show the search DID expand some states
        // (proving search happened but terminated correctly)
        self::assertGreaterThan(0, $result->guardLimits()->expansions(), 'Search should have expanded some states');
    }

    /**
     * @testdox Path with exactly maxHops is accepted
     */
    public function test_path_with_exactly_max_hops_is_accepted(): void
    {
        // Create 3-hop path: USD -> EUR -> GBP -> JPY
        $orderBook = new OrderBook();
        $orderBook->add(OrderFactory::buy('USD', 'EUR', '50.000', '200.000', '1.100', 3, 3));
        $orderBook->add(OrderFactory::buy('EUR', 'GBP', '50.000', '200.000', '1.200', 3, 3));
        $orderBook->add(OrderFactory::buy('GBP', 'JPY', '50.000', '200.000', '150.000', 3, 3));

        // Config: maxHops = 3 (exactly)
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.000', 3))
            ->withToleranceBounds('0.0', '0.1')
            ->withHopLimits(1, 3) // max 3 hops
            ->build();

        $service = new PathSearchService(new GraphBuilder());
        $request = new PathSearchRequest($orderBook, $config, 'JPY');
        $result = $service->findBestPaths($request);

        $paths = $result->paths()->toArray();

        // Should find the 3-hop path
        self::assertNotEmpty($paths, 'Should find path with exactly maxHops');
        self::assertSame(3, $paths[0]->hops()->count(), 'Path should have exactly 3 hops');
    }

    /**
     * @testdox Path with exactly minHops is accepted
     */
    public function test_path_with_exactly_min_hops_is_accepted(): void
    {
        // Create 2-hop path: USD -> EUR -> GBP
        $orderBook = new OrderBook();
        $orderBook->add(OrderFactory::buy('USD', 'EUR', '50.000', '200.000', '1.100', 3, 3));
        $orderBook->add(OrderFactory::buy('EUR', 'GBP', '50.000', '200.000', '1.200', 3, 3));

        // Config: minHops = 2 (exactly)
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.000', 3))
            ->withToleranceBounds('0.0', '0.1')
            ->withHopLimits(2, 4) // min 2 hops
            ->build();

        $service = new PathSearchService(new GraphBuilder());
        $request = new PathSearchRequest($orderBook, $config, 'GBP');
        $result = $service->findBestPaths($request);

        $paths = $result->paths()->toArray();

        // Should find the 2-hop path
        self::assertNotEmpty($paths, 'Should find path with exactly minHops');
        self::assertSame(2, $paths[0]->hops()->count(), 'Path should have exactly 2 hops');
    }

    // NOTE: test_multiple_paths_filtered_by_hop_limits was removed as part of MUL-12.
    // Reason: ExecutionPlanService returns only the single optimal plan, not multiple paths.
    // Filtering by hop limits happens at PathSearchService level.
    // See: BackwardCompatibilityTest::test_path_search_minimum_hop_enforcement
}
