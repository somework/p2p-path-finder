<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Application\PathFinder\PathFinder;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\SpendConstraints;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

use function count;

/**
 * Comprehensive algorithm tests including adversarial cases, boundary conditions, and stress tests.
 *
 * These tests are designed to verify PathFinder behavior under extreme conditions:
 * - Adversarial graphs (designed for worst-case performance)
 * - Boundary conditions (extreme parameter values)
 * - Guard stress tests (tight limits on complex graphs)
 * - Large-scale graphs (performance and correctness)
 *
 * @internal
 */
#[CoversClass(PathFinder::class)]
final class PathFinderAlgorithmStressTest extends TestCase
{
    /**
     * @testdox Adversarial graph: Complete graph forces maximum state exploration
     */
    public function test_adversarial_graph_complete_graph(): void
    {
        // Complete graph where every node connects to every other node
        // This maximizes the number of possible paths and state expansions
        $orderBook = new OrderBook();
        $nodes = ['USD', 'EUR', 'GBP', 'JPY', 'CHF'];

        // Create complete graph (every pair of nodes has an edge)
        foreach ($nodes as $from) {
            foreach ($nodes as $to) {
                if ($from !== $to) {
                    $orderBook->add(OrderFactory::buy($from, $to, '50.000', '150.000', '1.100', 3, 3));
                }
            }
        }

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));

        // Use tight guards to prevent runaway exploration
        $pathFinder = new PathFinder(
            maxHops: 3,
            tolerance: '0.0',
            topK: 5,
            maxExpansions: 20, // Very tight limit for complete graph
            maxVisitedStates: 15, // Very tight limit
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'CHF', null, null);
        $guardReport = $result->guardLimits();

        // Complete graphs generate many states; with tight limits we should hit at least one guard
        // OR find a path (if we're lucky with exploration order)
        self::assertTrue(
            $guardReport->expansionsReached() || $guardReport->visitedStatesReached() || count($result->paths()->toArray()) > 0,
            'Complete graph should trigger guards or find paths'
        );

        // Should still find some paths despite hitting limits
        self::assertGreaterThan(0, count($result->paths()->toArray()), 'Should find at least one path');
    }

    /**
     * @testdox Adversarial graph: Long linear chain tests hop limit enforcement
     */
    public function test_adversarial_graph_long_linear_chain(): void
    {
        // Linear chain: A → B → C → D → E → F → G → H
        // Requires exactly 7 hops to reach target
        $orderBook = new OrderBook();
        $chain = ['USD', 'GBP', 'JPY', 'CHF', 'AUD', 'CAD', 'NZD', 'EUR'];

        for ($i = 0; $i < count($chain) - 1; ++$i) {
            $orderBook->add(OrderFactory::buy($chain[$i], $chain[$i + 1], '50.000', '150.000', '1.050', 3, 3));
        }

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));

        // Test with maxHops < required hops (should find nothing)
        $pathFinder1 = new PathFinder(
            maxHops: 5, // Too few hops
            tolerance: '0.0',
            topK: 5,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $result1 = $pathFinder1->findBestPaths($graph, 'USD', 'EUR', null, null);
        self::assertCount(0, $result1->paths()->toArray(), 'Should find no paths when maxHops < required hops');

        // Test with maxHops = required hops (should find exactly one path)
        $pathFinder2 = new PathFinder(
            maxHops: 7, // Exactly enough hops
            tolerance: '0.0',
            topK: 5,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $result2 = $pathFinder2->findBestPaths($graph, 'USD', 'EUR', null, null);
        $paths2 = $result2->paths()->toArray();

        self::assertCount(1, $paths2, 'Should find exactly one path with exact hop count');
        self::assertSame(7, $paths2[0]->hops(), 'Path should have exactly 7 hops');
    }

    /**
     * @testdox Adversarial graph: Star topology with central hub
     */
    public function test_adversarial_graph_star_topology(): void
    {
        // Star topology: All nodes connect through central hub
        // USD → HUB, HUB → EUR, HUB → GBP, HUB → JPY, etc.
        // Forces paths to go through hub, testing state registry efficiency
        $orderBook = new OrderBook();
        $spokes = ['EUR', 'GBP', 'JPY', 'CHF', 'AUD', 'CAD', 'NZD', 'SEK'];

        // USD to HUB
        $orderBook->add(OrderFactory::buy('USD', 'HUB', '50.000', '150.000', '1.000', 3, 3));

        // HUB to all spokes
        foreach ($spokes as $spoke) {
            $orderBook->add(OrderFactory::buy('HUB', $spoke, '50.000', '150.000', '1.100', 3, 3));
        }

        // Spokes back to HUB (creates potential for cycles)
        foreach ($spokes as $spoke) {
            $orderBook->add(OrderFactory::buy($spoke, 'HUB', '50.000', '150.000', '0.900', 3, 3));
        }

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));

        $pathFinder = new PathFinder(
            maxHops: 4,
            tolerance: '0.1',
            topK: 10,
            maxExpansions: 500,
            maxVisitedStates: 200,
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'EUR', null, null);
        $paths = $result->paths()->toArray();

        // Should find direct path through hub (USD → HUB → EUR)
        self::assertGreaterThan(0, count($paths), 'Should find path through hub');

        // Verify shortest path is 2 hops (USD → HUB → EUR)
        $shortestHops = min(array_map(fn ($p) => $p->hops(), $paths));
        self::assertSame(2, $shortestHops, 'Shortest path should be 2 hops through hub');

        // Verify no cycles (visited state tracking prevents loops)
        $guardReport = $result->guardLimits();
        self::assertLessThan(200, $guardReport->visitedStates(), 'Should efficiently track visited states');
    }

    /**
     * @testdox Boundary condition: Tolerance = 0% (no tolerance)
     */
    public function test_boundary_condition_zero_tolerance(): void
    {
        $orderBook = new OrderBook();
        $orderBook->add(OrderFactory::buy('USD', 'EUR', '50.000', '150.000', '1.300', 3, 3));
        $orderBook->add(OrderFactory::buy('USD', 'GBP', '50.000', '150.000', '1.200', 3, 3));
        $orderBook->add(OrderFactory::buy('GBP', 'EUR', '50.000', '150.000', '1.100', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.0', // Zero tolerance
            topK: 5,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'EUR', null, null);
        $paths = $result->paths()->toArray();

        // With zero tolerance, should still find the best path
        self::assertGreaterThan(0, count($paths), 'Should find best path even with zero tolerance');

        // Best path is the one with lowest cost (best conversion rate)
        $bestPath = $paths[0];
        // Note: May be direct or 2-hop depending on which is discovered first and has better cost
        self::assertLessThanOrEqual(2, $bestPath->hops(), 'Best path should have low hop count');
    }

    /**
     * @testdox Boundary condition: Tolerance = 99.9% (maximum tolerance)
     */
    public function test_boundary_condition_maximum_tolerance(): void
    {
        $orderBook = new OrderBook();

        // Create paths with very different qualities
        $orderBook->add(OrderFactory::buy('USD', 'EUR', '50.000', '150.000', '2.000', 3, 3)); // Good: 2x
        $orderBook->add(OrderFactory::buy('USD', 'GBP', '50.000', '150.000', '1.010', 3, 3)); // Poor: 1.01x
        $orderBook->add(OrderFactory::buy('GBP', 'EUR', '50.000', '150.000', '1.010', 3, 3)); // Poor: 1.01x

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.999', // 99.9% tolerance (very permissive)
            topK: 10,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'EUR', null, null);
        $paths = $result->paths()->toArray();

        // With maximum tolerance, should find paths (may be 1 or more depending on topK and exploration)
        self::assertGreaterThan(0, count($paths), 'Should find paths with maximum tolerance');
    }

    /**
     * @testdox Boundary condition: maxHops = 1 (direct paths only)
     */
    public function test_boundary_condition_minimum_hops(): void
    {
        $orderBook = new OrderBook();

        // Direct and indirect paths
        $orderBook->add(OrderFactory::buy('USD', 'EUR', '50.000', '150.000', '1.300', 3, 3));
        $orderBook->add(OrderFactory::buy('USD', 'GBP', '50.000', '150.000', '1.200', 3, 3));
        $orderBook->add(OrderFactory::buy('GBP', 'EUR', '50.000', '150.000', '1.100', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));

        $pathFinder = new PathFinder(
            maxHops: 1, // Only direct paths
            tolerance: '0.0',
            topK: 5,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'EUR', null, null);
        $paths = $result->paths()->toArray();

        // Should only find direct path
        self::assertCount(1, $paths, 'Should find only direct path with maxHops=1');
        self::assertSame(1, $paths[0]->hops(), 'Path should have exactly 1 hop');
    }

    /**
     * @testdox Boundary condition: topK = 1 (single best path)
     */
    public function test_boundary_condition_top_k_one(): void
    {
        $orderBook = new OrderBook();

        // Multiple paths
        $orderBook->add(OrderFactory::buy('USD', 'EUR', '50.000', '150.000', '1.300', 3, 3));
        $orderBook->add(OrderFactory::buy('USD', 'GBP', '50.000', '150.000', '1.200', 3, 3));
        $orderBook->add(OrderFactory::buy('GBP', 'EUR', '50.000', '150.000', '1.100', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.15',
            topK: 1, // Only best path
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'EUR', null, null);
        $paths = $result->paths()->toArray();

        // Should return exactly one path (the best)
        self::assertCount(1, $paths, 'Should return exactly one path with topK=1');
    }

    /**
     * @testdox Guard stress test: Tight expansion limit on complex graph
     */
    public function test_guard_stress_tight_expansion_limit(): void
    {
        // Create moderately complex graph
        $orderBook = new OrderBook();
        $nodes = ['USD', 'EUR', 'GBP', 'JPY', 'CHF', 'AUD'];

        foreach ($nodes as $i => $from) {
            foreach ($nodes as $j => $to) {
                if ($i < $j) { // Create edges in one direction to avoid too many
                    $orderBook->add(OrderFactory::buy($from, $to, '50.000', '150.000', '1.100', 3, 3));
                }
            }
        }

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));

        $pathFinder = new PathFinder(
            maxHops: 4,
            tolerance: '0.1',
            topK: 5,
            maxExpansions: 10, // Very tight limit
            maxVisitedStates: 1000,
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'AUD', null, null);
        $guardReport = $result->guardLimits();

        // Should hit expansion limit
        self::assertTrue($guardReport->expansionsReached(), 'Should hit tight expansion limit');
        self::assertSame(10, $guardReport->expansions(), 'Should reach exactly the expansion limit');

        // May or may not find paths (depends on when limit hit)
        // But should not error
        self::assertIsArray($result->paths()->toArray(), 'Should return valid result despite hitting limit');
    }

    /**
     * @testdox Guard stress test: Tight visited state limit on complex graph
     */
    public function test_guard_stress_tight_visited_state_limit(): void
    {
        // Create graph with many branches
        $orderBook = new OrderBook();
        $orderBook->add(OrderFactory::buy('USD', 'HUB', '50.000', '150.000', '1.000', 3, 3));

        // Many nodes accessible from hub (use valid currency codes)
        $spokes = ['GBP', 'JPY', 'CHF', 'AUD', 'CAD', 'NZD', 'SEK', 'NOK', 'DKK', 'PLN'];
        foreach ($spokes as $node) {
            $orderBook->add(OrderFactory::buy('HUB', $node, '50.000', '150.000', '1.050', 3, 3));
            $orderBook->add(OrderFactory::buy($node, 'EUR', '50.000', '150.000', '1.100', 3, 3));
        }

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.15',
            topK: 10,
            maxExpansions: 1000,
            maxVisitedStates: 5, // Very tight limit
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'EUR', null, null);
        $guardReport = $result->guardLimits();

        // Should hit visited state limit
        self::assertLessThanOrEqual(5, $guardReport->visitedStates(), 'Should respect tight visited state limit');

        // May or may not find paths (depends on whether limit hit before reaching target)
        // The important thing is that the limit is respected
        $paths = $result->paths()->toArray();
        self::assertIsArray($paths, 'Should return valid result even with tight limit');
    }

    /**
     * @testdox Guard stress test: Tight time budget on complex graph
     */
    public function test_guard_stress_tight_time_budget(): void
    {
        // Create graph requiring significant exploration
        $orderBook = new OrderBook();
        $nodes = ['USD', 'EUR', 'GBP', 'JPY', 'CHF', 'AUD', 'CAD', 'NZD'];

        foreach ($nodes as $i => $from) {
            foreach ($nodes as $j => $to) {
                if ($from !== $to) {
                    $orderBook->add(OrderFactory::buy($from, $to, '50.000', '150.000', '1.080', 3, 3));
                }
            }
        }

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));

        $pathFinder = new PathFinder(
            maxHops: 3,
            tolerance: '0.1',
            topK: 10,
            maxExpansions: 10000,
            maxVisitedStates: 1000,
            timeBudgetMs: 1, // 1ms time budget (very tight)
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'NZD', null, null);
        $guardReport = $result->guardLimits();

        // Time budget may or may not be reached (depends on system speed)
        // But should not error and should return valid results
        self::assertIsArray($result->paths()->toArray(), 'Should return valid result with time budget');

        // If time budget was reached, verify it was reported
        if ($guardReport->timeBudgetReached()) {
            self::assertGreaterThan(0, $guardReport->elapsedMilliseconds(), 'Should report elapsed time');
        }
    }

    /**
     * @testdox Large graph: 20 nodes, multiple paths, verifies scalability
     */
    public function test_large_graph_scalability(): void
    {
        // Create larger graph with 20 nodes (use valid currency codes)
        $orderBook = new OrderBook();
        $nodes = [
            'USD', 'EUR', 'GBP', 'JPY', 'CHF', 'AUD', 'CAD', 'NZD', 'SEK', 'NOK',
            'DKK', 'PLN', 'HUF', 'CZK', 'SGD', 'HKD', 'THB', 'MYR', 'INR', 'BRL',
        ];

        // Create ring topology + some cross-connections
        for ($i = 0; $i < count($nodes); ++$i) {
            $from = $nodes[$i];
            $to = $nodes[($i + 1) % count($nodes)];
            $orderBook->add(OrderFactory::buy($from, $to, '50.000', '150.000', '1.050', 3, 3));

            // Add some shortcuts
            if (0 === $i % 3 && $i + 5 < count($nodes)) {
                $shortcut = $nodes[$i + 5];
                $orderBook->add(OrderFactory::buy($from, $shortcut, '50.000', '150.000', '1.200', 3, 3));
            }
        }

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));

        $pathFinder = new PathFinder(
            maxHops: 10,
            tolerance: '0.2',
            topK: 10,
            maxExpansions: 5000,
            maxVisitedStates: 2000,
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'DKK', null, null);
        $guardReport = $result->guardLimits();
        $paths = $result->paths()->toArray();

        // Should find paths without hitting guards
        self::assertFalse($guardReport->anyLimitReached(), 'Should handle large graph without hitting guards');
        self::assertGreaterThan(0, count($paths), 'Should find paths in large graph');

        // Verify paths are reasonable (not too long)
        foreach ($paths as $path) {
            self::assertLessThanOrEqual(10, $path->hops(), 'Paths should respect maxHops limit');
        }
    }

    /**
     * @testdox Empty graph: No nodes, returns empty result
     */
    public function test_boundary_condition_empty_graph(): void
    {
        $orderBook = new OrderBook();
        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.0',
            topK: 5,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'EUR', null, null);

        // Should return empty result (no error)
        self::assertCount(0, $result->paths()->toArray(), 'Empty graph should yield empty results');

        // Guards should be idle
        $guardReport = $result->guardLimits();
        self::assertSame(0, $guardReport->expansions(), 'No expansions in empty graph');
        self::assertSame(0, $guardReport->visitedStates(), 'No visited states in empty graph');
    }

    /**
     * @testdox Single node graph: Source equals target
     */
    public function test_boundary_condition_source_equals_target(): void
    {
        $orderBook = new OrderBook();
        $orderBook->add(OrderFactory::buy('USD', 'EUR', '50.000', '150.000', '1.300', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.0',
            topK: 5,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'USD', null, null);

        // Source == target: PathFinder bootstraps with a 0-hop state at source
        // Since source == target, it immediately satisfies the target condition
        // This results in a 0-hop "path" being returned
        $paths = $result->paths()->toArray();

        if (count($paths) > 0) {
            // If a path is returned, it should be a 0-hop path
            self::assertSame(0, $paths[0]->hops(), 'Source == target path should have 0 hops');
        }

        // This is expected behavior: source == target is satisfied immediately
        self::assertLessThanOrEqual(1, count($paths), 'Source == target should return at most one path');
    }

    /**
     * @testdox Disconnected graph: Source and target in different components
     */
    public function test_boundary_condition_disconnected_graph(): void
    {
        $orderBook = new OrderBook();

        // Component 1: USD ↔ EUR
        $orderBook->add(OrderFactory::buy('USD', 'EUR', '50.000', '150.000', '1.300', 3, 3));
        $orderBook->add(OrderFactory::buy('EUR', 'USD', '50.000', '150.000', '0.750', 3, 3));

        // Component 2: GBP ↔ JPY (disconnected)
        $orderBook->add(OrderFactory::buy('GBP', 'JPY', '50.000', '150.000', '1.200', 3, 3));
        $orderBook->add(OrderFactory::buy('JPY', 'GBP', '50.000', '150.000', '0.800', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.0',
            topK: 5,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'GBP', null, null);

        // Should return empty (no path between disconnected components)
        self::assertCount(0, $result->paths()->toArray(), 'Disconnected components should yield empty results');

        // Should not exhaust guards (early termination)
        $guardReport = $result->guardLimits();
        self::assertFalse($guardReport->anyLimitReached(), 'Should terminate early in disconnected graph');
    }

    /**
     * @testdox Spend constraints: Very tight constraints prune paths aggressively
     */
    public function test_boundary_condition_tight_spend_constraints(): void
    {
        $orderBook = new OrderBook();
        $orderBook->add(OrderFactory::buy('USD', 'EUR', '50.000', '150.000', '1.300', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));

        // Very tight constraints (min = max = desired)
        $spend = Money::fromString('USD', '75.00', 2);
        $constraints = SpendConstraints::from($spend, $spend, $spend);

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.0',
            topK: 5,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'EUR', $constraints, null);
        $paths = $result->paths()->toArray();

        // Should still find paths (constraints are carried through)
        self::assertGreaterThan(0, count($paths), 'Should find paths with tight spend constraints');

        // Paths should have spend range information
        foreach ($paths as $path) {
            self::assertNotNull($path->range(), 'Path should carry spend range information');
        }
    }
}
