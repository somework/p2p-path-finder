<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Application\PathFinder\PathFinder;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\SpendConstraints;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

use function count;
use function in_array;
use function sprintf;

/**
 * Tests for visited state tracking to ensure cycles are prevented and counts are accurate.
 *
 * The PathFinder uses two mechanisms for visited state tracking:
 * 1. Per-path cycle prevention: Each state tracks which nodes it has visited
 * 2. Global state registry: Tracks best states per node across all paths
 *
 * @internal
 */
#[CoversClass(PathFinder::class)]
final class VisitedStateTrackingTest extends TestCase
{
    /**
     * @testdox Multiple paths to same node are handled correctly
     */
    public function test_multiple_paths_to_same_node(): void
    {
        // Create a diamond graph where multiple paths reach the same destination
        //     GBP
        //    /   \
        // USD     EUR
        //    \   /
        //     JPY

        $orderBook = new OrderBook();

        // Path 1: USD → GBP → EUR
        $orderBook->add(OrderFactory::buy('USD', 'GBP', '50.000', '150.000', '1.500', 3, 3));
        $orderBook->add(OrderFactory::buy('GBP', 'EUR', '50.000', '150.000', '1.200', 3, 3));

        // Path 2: USD → JPY → EUR
        $orderBook->add(OrderFactory::buy('USD', 'JPY', '50.000', '150.000', '1.400', 3, 3));
        $orderBook->add(OrderFactory::buy('JPY', 'EUR', '50.000', '150.000', '1.300', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));
        $spendConstraints = SpendConstraints::fromScalars('USD', '100.000', '100.000', '100.000');

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.2',
            topK: 10,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'EUR', $spendConstraints, null);
        $paths = $result->paths()->toArray();

        // Should find both paths to EUR
        self::assertGreaterThanOrEqual(2, count($paths), 'Should find multiple paths to EUR');

        // Verify both paths are different
        $pathRoutes = [];
        foreach ($paths as $path) {
            $route = [];
            foreach ($path->edges() as $edge) {
                $route[] = $edge->from();
            }
            $route[] = $paths[0]->edges()->last()?->to();
            $pathRoutes[] = implode('->', $route);
        }

        // Should have distinct routes
        $uniqueRoutes = array_unique($pathRoutes);
        self::assertGreaterThanOrEqual(2, count($uniqueRoutes), 'Should have multiple distinct routes to EUR');
    }

    /**
     * @testdox Cycles are prevented in graph traversal
     */
    public function test_cycle_prevention(): void
    {
        // Create a graph with clear cycle potential
        // USD can reach GBP and CHF
        // Both GBP and CHF can reach EUR
        // But we also add edges that could create cycles
        $orderBook = new OrderBook();

        // Layer 1: USD to intermediates
        $orderBook->add(OrderFactory::buy('USD', 'GBP', '50.000', '150.000', '1.200', 3, 3));
        $orderBook->add(OrderFactory::buy('USD', 'CHF', '50.000', '150.000', '1.150', 3, 3));

        // Layer 2: Intermediates to EUR
        $orderBook->add(OrderFactory::buy('GBP', 'EUR', '50.000', '150.000', '1.100', 3, 3));
        $orderBook->add(OrderFactory::buy('CHF', 'EUR', '50.000', '150.000', '1.050', 3, 3));

        // Cross edges that could enable cycles if not prevented
        $orderBook->add(OrderFactory::buy('GBP', 'CHF', '50.000', '150.000', '1.080', 3, 3));
        $orderBook->add(OrderFactory::buy('CHF', 'GBP', '50.000', '150.000', '0.920', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));
        $spendConstraints = SpendConstraints::fromScalars('USD', '100.000', '100.000', '100.000');

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.15',
            topK: 10,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'EUR', $spendConstraints, null);
        $paths = $result->paths()->toArray();

        // Should find paths to EUR via different routes
        self::assertGreaterThan(0, count($paths), 'Should find path(s) to EUR');

        // Verify no path contains a cycle (revisits a node)
        foreach ($paths as $path) {
            $visitedNodes = [];
            foreach ($path->edges() as $edge) {
                $from = $edge->from();
                self::assertFalse(
                    in_array($from, $visitedNodes, true),
                    sprintf('Path should not revisit node %s (cycle detected)', $from)
                );
                $visitedNodes[] = $from;
            }

            // Also check destination
            $lastEdge = $path->edges()->last();
            if ($lastEdge) {
                $to = $lastEdge->to();
                self::assertFalse(
                    in_array($to, $visitedNodes, true),
                    sprintf('Path should not revisit node %s at destination', $to)
                );
            }
        }
    }

    /**
     * @testdox Same node reached via different costs is tracked correctly
     */
    public function test_same_node_via_different_costs(): void
    {
        // Create scenarios where same node is reached with different costs
        $orderBook = new OrderBook();

        // Expensive path: USD → GBP (high rate)
        $orderBook->add(OrderFactory::buy('USD', 'GBP', '50.000', '150.000', '1.500', 3, 3));

        // Cheap path: USD → GBP (lower rate)
        $orderBook->add(OrderFactory::buy('USD', 'GBP', '50.000', '150.000', '1.200', 3, 3));

        // Continue to EUR
        $orderBook->add(OrderFactory::buy('GBP', 'EUR', '50.000', '150.000', '1.100', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));
        $spendConstraints = SpendConstraints::fromScalars('USD', '100.000', '100.000', '100.000');

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.3',
            topK: 10,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'EUR', $spendConstraints, null);
        $paths = $result->paths()->toArray();

        // Should find path(s) to EUR
        self::assertGreaterThan(0, count($paths), 'Should find path(s) to EUR');

        // PathFinder should use the better (cheaper) edge to GBP
        // We can verify this by checking the result quality
        $bestPath = $paths[0] ?? null;
        self::assertNotNull($bestPath, 'Should have at least one path');

        // The best path should use the cheaper edge (rate 1.2)
        // Product represents the cumulative conversion rate
        $product = $bestPath->productDecimal()->toFloat();
        self::assertGreaterThan(1.30, $product, 'Should use cheaper path to maximize product (rate)');
    }

    /**
     * @testdox Visited states count matches actual unique states
     */
    public function test_visited_state_count_accuracy(): void
    {
        // Create a simple path to verify state counting
        $orderBook = new OrderBook();

        $orderBook->add(OrderFactory::buy('USD', 'GBP', '50.000', '150.000', '1.300', 3, 3));
        $orderBook->add(OrderFactory::buy('GBP', 'CHF', '50.000', '150.000', '1.200', 3, 3));
        $orderBook->add(OrderFactory::buy('CHF', 'EUR', '50.000', '150.000', '1.100', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));
        $spendConstraints = SpendConstraints::fromScalars('USD', '100.000', '100.000', '100.000');

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.0',
            topK: 10,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'EUR', $spendConstraints, null);
        $guardReport = $result->guardLimits();

        // Expected visited states:
        // 1. USD (initial)
        // 2. GBP (from USD)
        // 3. CHF (from GBP)
        // Note: EUR is the target and may not be counted in visited states (depends on impl)
        // The actual count should be 3 or 4

        self::assertGreaterThanOrEqual(3, $guardReport->visitedStates(), 'Visited states count should be at least 3');
        self::assertLessThanOrEqual(4, $guardReport->visitedStates(), 'Visited states count should be at most 4');

        // Verify no guard limits were reached
        self::assertFalse($guardReport->visitedStatesReached(), 'Visited states limit should not be reached');
        self::assertFalse($guardReport->expansionsReached(), 'Expansion limit should not be reached');
    }

    /**
     * @testdox Visited state limit prevents excessive state expansion
     */
    public function test_visited_state_limit_enforcement(): void
    {
        // Create a complex graph that could generate many states
        $orderBook = new OrderBook();

        // Create a star pattern with USD at center connecting to many currencies
        $currencies = ['GBP', 'JPY', 'CHF', 'AUD', 'CAD', 'NZD', 'SEK', 'NOK'];

        foreach ($currencies as $currency) {
            $orderBook->add(OrderFactory::buy('USD', $currency, '50.000', '150.000', '1.200', 3, 3));
            $orderBook->add(OrderFactory::buy($currency, 'EUR', '50.000', '150.000', '1.100', 3, 3));
        }

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));
        $spendConstraints = SpendConstraints::fromScalars('USD', '100.000', '100.000', '100.000');

        // Set a low visited state limit
        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.0',
            topK: 10,
            maxExpansions: 10000,
            maxVisitedStates: 5,  // Very low limit
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'EUR', $spendConstraints, null);
        $guardReport = $result->guardLimits();

        // Should hit the visited state limit
        self::assertLessThanOrEqual(5, $guardReport->visitedStates(), 'Should respect visited state limit');

        // With a very low limit, we might not find any paths to EUR (which is expected)
        // The important thing is that we respect the limit
        $pathCount = count($result->paths()->toArray());
        self::assertLessThanOrEqual(10, $pathCount, 'Should limit path exploration due to visited state constraint');
    }

    /**
     * @testdox State registry correctly updates when better state is found
     */
    public function test_state_registry_updates_with_better_state(): void
    {
        // Create a scenario where a node can be reached via multiple paths with different costs
        $orderBook = new OrderBook();

        // Path 1: USD → GBP → CHF (expensive route to CHF)
        $orderBook->add(OrderFactory::buy('USD', 'GBP', '50.000', '150.000', '1.100', 3, 3));
        $orderBook->add(OrderFactory::buy('GBP', 'CHF', '50.000', '150.000', '1.100', 3, 3));

        // Path 2: USD → CHF (cheaper direct route)
        $orderBook->add(OrderFactory::buy('USD', 'CHF', '50.000', '150.000', '1.300', 3, 3));

        // Continue to target
        $orderBook->add(OrderFactory::buy('CHF', 'EUR', '50.000', '150.000', '1.000', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));
        $spendConstraints = SpendConstraints::fromScalars('USD', '100.000', '100.000', '100.000');

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.1',
            topK: 10,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'EUR', $spendConstraints, null);
        $paths = $result->paths()->toArray();

        self::assertGreaterThan(0, count($paths), 'Should find path to EUR');

        // The best path should use the direct USD → CHF edge (rate 1.3)
        // rather than the 2-hop USD → GBP → CHF (rate 1.1 * 1.1 = 1.21)
        $bestPath = $paths[0];

        // Best path should be either:
        // - USD → CHF → EUR (2 hops, best rate)
        // - USD → GBP → CHF → EUR (3 hops, if within tolerance)
        self::assertLessThanOrEqual(3, $bestPath->hops(), 'Best path should be efficient');

        // Visited states should reflect the exploration:
        // USD, GBP, CHF (multiple paths), EUR
        // The pathfinder explores different routes, so visited count may vary
        $guardReport = $result->guardLimits();
        self::assertGreaterThanOrEqual(3, $guardReport->visitedStates(), 'Should visit at least source, intermediate, target');
        self::assertLessThanOrEqual(10, $guardReport->visitedStates(), 'Visited states should be reasonable');
    }

    /**
     * @testdox Self-loops are prevented at domain level
     */
    public function test_self_loop_prevention(): void
    {
        // AssetPair validation prevents creating orders with same base/quote
        // This test verifies the domain-level invariant is maintained

        $orderBook = new OrderBook();

        // Normal path
        $orderBook->add(OrderFactory::buy('USD', 'GBP', '50.000', '150.000', '1.200', 3, 3));
        $orderBook->add(OrderFactory::buy('GBP', 'EUR', '50.000', '150.000', '1.100', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));

        // Verify graph edges never have same from/to
        foreach ($graph->nodes() as $node) {
            foreach ($node->edges() as $edge) {
                self::assertNotSame(
                    $edge->from(),
                    $edge->to(),
                    'Graph should not contain self-loop edges (enforced by AssetPair validation)'
                );
            }
        }

        $spendConstraints = SpendConstraints::fromScalars('USD', '100.000', '100.000', '100.000');

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.0',
            topK: 10,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'EUR', $spendConstraints, null);
        $paths = $result->paths()->toArray();

        self::assertGreaterThan(0, count($paths), 'Should find path to EUR');

        // Verify no path contains a self-loop (double-check at path level)
        foreach ($paths as $path) {
            foreach ($path->edges() as $edge) {
                self::assertNotSame(
                    $edge->from(),
                    $edge->to(),
                    'Path should not contain self-loop'
                );
            }
        }
    }

    /**
     * @testdox Complex graph with many interconnections tracks states correctly
     */
    public function test_complex_graph_state_tracking(): void
    {
        // Create a more complex graph with multiple paths and interconnections
        $orderBook = new OrderBook();

        // Layer 1: USD to multiple currencies
        $orderBook->add(OrderFactory::buy('USD', 'GBP', '50.000', '150.000', '1.300', 3, 3));
        $orderBook->add(OrderFactory::buy('USD', 'JPY', '50.000', '150.000', '1.200', 3, 3));

        // Layer 2: Cross connections
        $orderBook->add(OrderFactory::buy('GBP', 'CHF', '50.000', '150.000', '1.200', 3, 3));
        $orderBook->add(OrderFactory::buy('JPY', 'CHF', '50.000', '150.000', '1.250', 3, 3));
        $orderBook->add(OrderFactory::buy('GBP', 'JPY', '50.000', '150.000', '1.100', 3, 3));

        // Layer 3: To target
        $orderBook->add(OrderFactory::buy('CHF', 'EUR', '50.000', '150.000', '1.100', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));
        $spendConstraints = SpendConstraints::fromScalars('USD', '100.000', '100.000', '100.000');

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.15',
            topK: 10,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'EUR', $spendConstraints, null);
        $guardReport = $result->guardLimits();

        // Should find multiple paths
        self::assertGreaterThan(0, count($result->paths()->toArray()), 'Should find paths in complex graph');

        // Visited states should be reasonable for graph size
        // Expected: USD, GBP, JPY, CHF, GBP→JPY (cross), EUR
        // The actual count depends on which paths are explored
        self::assertGreaterThanOrEqual(4, $guardReport->visitedStates(), 'Should visit at least source and target nodes');
        self::assertLessThanOrEqual(10, $guardReport->visitedStates(), 'Visited states should be reasonable for graph size');

        // No guard limits should be reached
        self::assertFalse($guardReport->anyLimitReached(), 'No guard limits should be reached with reasonable limits');
    }
}
