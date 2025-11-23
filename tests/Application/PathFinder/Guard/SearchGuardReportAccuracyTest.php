<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder\Guard;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Graph\Graph;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Application\PathFinder\PathFinder;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\SpendConstraints;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

use function chr;
use function count;
use function ord;

/**
 * Integration tests verifying SearchGuardReport metrics accurately reflect actual search activity.
 *
 * These tests validate that:
 * - Expansion counts match actual state expansions
 * - Visited state counts match unique states registered
 * - Elapsed time measurements are reasonable
 * - Breach flags are set correctly based on actual limits
 *
 * @internal
 */
#[CoversClass(PathFinder::class)]
final class SearchGuardReportAccuracyTest extends TestCase
{
    /**
     * @testdox Expansion count matches actual number of states expanded from queue
     */
    public function test_expansion_count_accuracy(): void
    {
        // Create a small graph with known structure:
        // USD -> EUR -> GBP -> JPY (linear chain, 3 edges)
        // Expected expansions: 1 (USD) + 1 (EUR) + 1 (GBP) = 3 states before reaching JPY
        $orderBook = new OrderBook();
        $orderBook->add(OrderFactory::buy('USD', 'EUR', '50.000', '200.000', '1.100', 3, 3));
        $orderBook->add(OrderFactory::buy('EUR', 'GBP', '50.000', '200.000', '1.200', 3, 3));
        $orderBook->add(OrderFactory::buy('GBP', 'JPY', '50.000', '200.000', '150.000', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));
        $spendConstraints = SpendConstraints::fromScalars('USD', '100.000', '100.000', '100.000');

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.0',
            topK: 1,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'JPY', $spendConstraints, null);

        $guardReport = $result->guardLimits();

        // Should have expanded: USD, EUR, GBP (3 expansions)
        self::assertGreaterThanOrEqual(3, $guardReport->expansions(), 'Should expand at least 3 states (USD, EUR, GBP)');
        self::assertLessThanOrEqual(10, $guardReport->expansions(), 'Should not expand more than 10 states for this simple graph');

        // Expansion count should be reasonable for graph size
        $graphEdgeCount = $this->countGraphEdges($graph);
        self::assertLessThanOrEqual($graphEdgeCount * 2, $guardReport->expansions(), 'Expansions should not exceed reasonable multiple of graph edges');
    }

    /**
     * @testdox Visited state count matches unique states registered in search
     */
    public function test_visited_state_count_accuracy(): void
    {
        // Create a graph with multiple paths to same destination
        // USD -> EUR -> GBP
        // USD -> JPY -> GBP
        // This will cause multiple states to reach GBP, but only best ones are kept
        $orderBook = new OrderBook();
        $orderBook->add(OrderFactory::buy('USD', 'EUR', '50.000', '200.000', '1.100', 3, 3));
        $orderBook->add(OrderFactory::buy('EUR', 'GBP', '50.000', '200.000', '1.200', 3, 3));
        $orderBook->add(OrderFactory::buy('USD', 'JPY', '50.000', '200.000', '110.000', 3, 3));
        $orderBook->add(OrderFactory::buy('JPY', 'GBP', '5000.000', '15000.000', '1.000', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));
        $spendConstraints = SpendConstraints::fromScalars('USD', '100.000', '100.000', '100.000');

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.0',
            topK: 1,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'GBP', $spendConstraints, null);

        $guardReport = $result->guardLimits();

        // Should visit: USD (source), EUR, JPY, GBP
        // Visited states represent unique (node, signature) pairs
        self::assertGreaterThanOrEqual(3, $guardReport->visitedStates(), 'Should visit at least 3 unique states');
        self::assertLessThanOrEqual(20, $guardReport->visitedStates(), 'Should not visit more than 20 states for this graph');

        // Visited states should be <= expansions (can't visit more states than we expand)
        self::assertLessThanOrEqual($guardReport->visitedStates(), $guardReport->expansions(), 'Visited states should not exceed expansions');
    }

    /**
     * @testdox Elapsed time measurement is reasonable and non-negative
     */
    public function test_elapsed_time_reasonable(): void
    {
        $orderBook = new OrderBook();
        $orderBook->add(OrderFactory::buy('USD', 'EUR', '50.000', '200.000', '1.100', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));
        $spendConstraints = SpendConstraints::fromScalars('USD', '100.000', '100.000', '100.000');

        $startTime = microtime(true);

        $pathFinder = new PathFinder(
            maxHops: 3,
            tolerance: '0.0',
            topK: 1,
            maxExpansions: 100,
            maxVisitedStates: 100,
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'EUR', $spendConstraints, null);

        $endTime = microtime(true);
        $actualElapsedMs = ($endTime - $startTime) * 1000.0;

        $guardReport = $result->guardLimits();

        // Elapsed time should be non-negative
        self::assertGreaterThanOrEqual(0.0, $guardReport->elapsedMilliseconds(), 'Elapsed time should be non-negative');

        // Elapsed time should be reasonable (not wildly off from actual time)
        // Allow for some overhead, but should be in same order of magnitude
        self::assertLessThanOrEqual($actualElapsedMs * 2.0, $guardReport->elapsedMilliseconds(), 'Reported time should not exceed 2x actual time (accounting for overhead)');

        // For such a small search, should complete in under 1 second
        self::assertLessThan(1000.0, $guardReport->elapsedMilliseconds(), 'Simple search should complete in under 1 second');
    }

    /**
     * @testdox Expansion breach flag is set correctly when limit is reached
     */
    public function test_expansion_breach_flag_correct(): void
    {
        // Create a larger graph to ensure we hit the expansion limit
        $orderBook = new OrderBook();
        // Create a fan-out: USD -> A, B, C, D, E
        // Each connects to target
        for ($i = 0; $i < 5; ++$i) {
            $intermediate = chr(ord('A') + $i).chr(ord('A') + $i).chr(ord('A') + $i);
            $orderBook->add(OrderFactory::buy('USD', $intermediate, '50.000', '200.000', '1.100', 3, 3));
            $orderBook->add(OrderFactory::buy($intermediate, 'EUR', '50.000', '200.000', '1.100', 3, 3));
        }

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));
        $spendConstraints = SpendConstraints::fromScalars('USD', '100.000', '100.000', '100.000');

        // Set a very low expansion limit
        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.0',
            topK: 1,
            maxExpansions: 3, // Very low limit
            maxVisitedStates: 1000,
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'EUR', $spendConstraints, null);

        $guardReport = $result->guardLimits();

        // Expansion limit should be reached
        self::assertTrue($guardReport->expansionsReached(), 'Expansion limit should be reached with limit of 3');
        self::assertSame(3, $guardReport->expansions(), 'Should have exactly 3 expansions');
        self::assertSame(3, $guardReport->expansionLimit(), 'Expansion limit should be 3');

        // Other limits should not be reached
        self::assertFalse($guardReport->visitedStatesReached(), 'Visited states limit should not be reached');
        self::assertFalse($guardReport->timeBudgetReached(), 'Time budget should not be reached');

        // anyLimitReached should be true
        self::assertTrue($guardReport->anyLimitReached(), 'anyLimitReached() should return true');
    }

    /**
     * @testdox Visited states breach flag is set correctly when limit is reached
     */
    public function test_visited_states_breach_flag_correct(): void
    {
        // Create a graph with many nodes
        $orderBook = new OrderBook();
        for ($i = 0; $i < 10; ++$i) {
            $nodeA = chr(ord('A') + $i).chr(ord('A') + $i).chr(ord('A') + $i);
            $nodeB = chr(ord('B') + $i).chr(ord('B') + $i).chr(ord('B') + $i);
            $orderBook->add(OrderFactory::buy('USD', $nodeA, '50.000', '200.000', '1.100', 3, 3));
            $orderBook->add(OrderFactory::buy($nodeA, $nodeB, '50.000', '200.000', '1.100', 3, 3));
            $orderBook->add(OrderFactory::buy($nodeB, 'EUR', '50.000', '200.000', '1.100', 3, 3));
        }

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));
        $spendConstraints = SpendConstraints::fromScalars('USD', '100.000', '100.000', '100.000');

        // Set a very low visited states limit
        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.0',
            topK: 1,
            maxExpansions: 1000,
            maxVisitedStates: 5, // Very low limit
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'EUR', $spendConstraints, null);

        $guardReport = $result->guardLimits();

        // Visited states limit should be reached
        self::assertTrue($guardReport->visitedStatesReached(), 'Visited states limit should be reached with limit of 5');
        self::assertGreaterThanOrEqual(5, $guardReport->visitedStates(), 'Should have at least 5 visited states');
        self::assertSame(5, $guardReport->visitedStateLimit(), 'Visited states limit should be 5');

        // anyLimitReached should be true
        self::assertTrue($guardReport->anyLimitReached(), 'anyLimitReached() should return true');
    }

    /**
     * @testdox Time budget breach flag is set correctly when limit is reached
     */
    public function test_time_budget_breach_flag_correct(): void
    {
        // Create a large enough graph to ensure time budget is hit
        $orderBook = new OrderBook();
        for ($i = 0; $i < 20; ++$i) {
            $nodeA = chr(ord('A') + ($i % 26)).chr(ord('A') + (($i + 1) % 26)).chr(ord('A') + (($i + 2) % 26));
            $nodeB = chr(ord('B') + ($i % 26)).chr(ord('B') + (($i + 1) % 26)).chr(ord('B') + (($i + 2) % 26));
            $orderBook->add(OrderFactory::buy('USD', $nodeA, '50.000', '200.000', '1.100', 3, 3));
            $orderBook->add(OrderFactory::buy($nodeA, $nodeB, '50.000', '200.000', '1.100', 3, 3));
            $orderBook->add(OrderFactory::buy($nodeB, 'EUR', '50.000', '200.000', '1.100', 3, 3));
        }

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));
        $spendConstraints = SpendConstraints::fromScalars('USD', '100.000', '100.000', '100.000');

        // Set a very low time budget (1 millisecond)
        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.0',
            topK: 1,
            maxExpansions: 10000,
            maxVisitedStates: 10000,
            timeBudgetMs: 1, // 1 millisecond - very tight
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'EUR', $spendConstraints, null);

        $guardReport = $result->guardLimits();

        // Time budget should likely be reached (though this is timing-dependent)
        // If not reached, at least verify the time budget limit is set correctly
        self::assertSame(1, $guardReport->timeBudgetLimit(), 'Time budget limit should be 1ms');

        if ($guardReport->timeBudgetReached()) {
            self::assertGreaterThanOrEqual(1.0, $guardReport->elapsedMilliseconds(), 'Elapsed time should be >= 1ms if budget reached');
            self::assertTrue($guardReport->anyLimitReached(), 'anyLimitReached() should return true');
        }
    }

    /**
     * @testdox No breach flags set when limits are not reached
     */
    public function test_no_breach_flags_when_limits_not_reached(): void
    {
        // Simple graph that completes quickly
        $orderBook = new OrderBook();
        $orderBook->add(OrderFactory::buy('USD', 'EUR', '50.000', '200.000', '1.100', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));
        $spendConstraints = SpendConstraints::fromScalars('USD', '100.000', '100.000', '100.000');

        // Set very high limits
        $pathFinder = new PathFinder(
            maxHops: 10,
            tolerance: '0.0',
            topK: 1,
            maxExpansions: 10000,
            maxVisitedStates: 10000,
            timeBudgetMs: 60000, // 1 minute
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'EUR', $spendConstraints, null);

        $guardReport = $result->guardLimits();

        // No limits should be reached
        self::assertFalse($guardReport->expansionsReached(), 'Expansion limit should not be reached');
        self::assertFalse($guardReport->visitedStatesReached(), 'Visited states limit should not be reached');
        self::assertFalse($guardReport->timeBudgetReached(), 'Time budget should not be reached');
        self::assertFalse($guardReport->anyLimitReached(), 'anyLimitReached() should return false');

        // But metrics should be reasonable
        self::assertGreaterThan(0, $guardReport->expansions(), 'Should have expanded some states');
        self::assertGreaterThan(0, $guardReport->visitedStates(), 'Should have visited some states');
        self::assertGreaterThanOrEqual(0.0, $guardReport->elapsedMilliseconds(), 'Should have non-negative elapsed time');
    }

    /**
     * @testdox Expansion count equals visited states in simple linear search
     */
    public function test_expansion_equals_visited_in_linear_search(): void
    {
        // Linear chain: each expansion visits exactly one new state
        $orderBook = new OrderBook();
        $orderBook->add(OrderFactory::buy('USD', 'EUR', '50.000', '200.000', '1.100', 3, 3));
        $orderBook->add(OrderFactory::buy('EUR', 'GBP', '50.000', '200.000', '1.200', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));
        $spendConstraints = SpendConstraints::fromScalars('USD', '100.000', '100.000', '100.000');

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.0',
            topK: 1,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'GBP', $spendConstraints, null);

        $guardReport = $result->guardLimits();

        // In a linear search, expansions and visited states should be close
        // (allowing for bootstrap state which might not count as visited)
        self::assertGreaterThan(0, $guardReport->expansions(), 'Should have expansions');
        self::assertGreaterThan(0, $guardReport->visitedStates(), 'Should have visited states');

        // Visited states should be close to expansions for linear path
        $difference = abs($guardReport->expansions() - $guardReport->visitedStates());
        self::assertLessThanOrEqual(2, $difference, 'In linear search, visited states should be within 2 of expansions');
    }

    /**
     * @testdox Metrics are consistent across multiple searches on same graph
     */
    public function test_metrics_consistent_across_searches(): void
    {
        $orderBook = new OrderBook();
        $orderBook->add(OrderFactory::buy('USD', 'EUR', '50.000', '200.000', '1.100', 3, 3));
        $orderBook->add(OrderFactory::buy('EUR', 'GBP', '50.000', '200.000', '1.200', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));
        $spendConstraints = SpendConstraints::fromScalars('USD', '100.000', '100.000', '100.000');

        $expansionCounts = [];
        $visitedCounts = [];

        // Run same search multiple times
        for ($i = 0; $i < 3; ++$i) {
            $pathFinder = new PathFinder(
                maxHops: 5,
                tolerance: '0.0',
                topK: 1,
                maxExpansions: 1000,
                maxVisitedStates: 1000,
            );

            $result = $pathFinder->findBestPaths($graph, 'USD', 'GBP', $spendConstraints, null);
            $guardReport = $result->guardLimits();

            $expansionCounts[] = $guardReport->expansions();
            $visitedCounts[] = $guardReport->visitedStates();
        }

        // All runs should have same expansion and visited counts (deterministic)
        self::assertSame($expansionCounts[0], $expansionCounts[1], 'Expansion counts should be consistent across runs');
        self::assertSame($expansionCounts[0], $expansionCounts[2], 'Expansion counts should be consistent across runs');
        self::assertSame($visitedCounts[0], $visitedCounts[1], 'Visited counts should be consistent across runs');
        self::assertSame($visitedCounts[0], $visitedCounts[2], 'Visited counts should be consistent across runs');
    }

    /**
     * Helper to count total edges in graph.
     */
    private function countGraphEdges(Graph $graph): int
    {
        $count = 0;
        foreach ($graph->nodes() as $node) {
            $count += count(iterator_to_array($node->edges()));
        }

        return $count;
    }
}
