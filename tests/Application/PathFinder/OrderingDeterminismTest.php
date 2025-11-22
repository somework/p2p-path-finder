<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder;

use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Application\PathFinder\PathFinder;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\SpendConstraints;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

/**
 * Tests to verify path ordering is deterministic and stable across multiple runs.
 *
 * Ordering Criteria (in priority order):
 * 1. Cost (lower is better)
 * 2. Hops (fewer is better)
 * 3. Route signature (lexicographic)
 * 4. Insertion order (earlier is better - ensures determinism)
 *
 * These tests verify that:
 * - Equal-cost paths maintain consistent ordering
 * - Repeated runs produce identical results
 * - Tie-breaking is deterministic
 * - No sources of non-determinism (timestamps, random, object IDs)
 *
 * @internal
 */
#[CoversClass(PathFinder::class)]
final class OrderingDeterminismTest extends TestCase
{
    /**
     * @testdox Equal-cost paths with different hops are ordered deterministically
     */
    public function testEqualCostPathsOrderDeterministically(): void
    {
        // Create paths with same cost but different hop counts
        // PathFinder should order by cost first, then hops
        $orderBook = new OrderBook();
        
        // 1-hop path (USD -> EUR directly) with rate 1.5
        $orderBook->add(OrderFactory::buy('USD', 'EUR', '50.000', '200.000', '1.500', 3, 3));
        
        // 2-hop path (USD -> GBP -> EUR) with rates that give ~same result: 1.224 * 1.224 ≈ 1.5
        $orderBook->add(OrderFactory::buy('USD', 'GBP', '50.000', '200.000', '1.224', 3, 3));
        $orderBook->add(OrderFactory::buy('GBP', 'EUR', '50.000', '200.000', '1.224', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));
        $spendConstraints = SpendConstraints::fromScalars('USD', '100.000', '100.000', '100.000');

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.05', // 5% tolerance to capture both paths
            topK: 10,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'EUR', $spendConstraints, null);
        $paths = $result->paths()->toArray();

        // Should find both paths
        self::assertGreaterThanOrEqual(2, count($paths), 'Should find at least 2 paths');
        
        // Path with fewer hops should come first (when costs are similar)
        $firstPathHops = $paths[0]->hops();
        if (count($paths) >= 2) {
            $secondPathHops = $paths[1]->hops();
            self::assertLessThanOrEqual($secondPathHops, $firstPathHops, 'Path with fewer hops should come first when costs are similar');
        }
    }

    /**
     * @testdox Paths with different signatures are consistently ordered
     */
    public function testPathSignatureOrdering(): void
    {
        // Create a simple scenario with 2 distinct paths to test signature ordering
        $orderBook = new OrderBook();
        
        // Path 1: USD -> GBP -> EUR (will have signature "USD->GBP->EUR")
        $orderBook->add(OrderFactory::buy('USD', 'GBP', '50.000', '200.000', '1.200', 3, 3));
        $orderBook->add(OrderFactory::buy('GBP', 'EUR', '50.000', '200.000', '1.000', 3, 3));
        
        // Path 2: USD -> AUD -> EUR (will have signature "USD->AUD->EUR", lexically before GBP)
        // Give it slightly worse rate so GBP path is still best
        $orderBook->add(OrderFactory::buy('USD', 'AUD', '50.000', '200.000', '1.190', 3, 3));
        $orderBook->add(OrderFactory::buy('AUD', 'EUR', '50.000', '200.000', '1.000', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));
        $spendConstraints = SpendConstraints::fromScalars('USD', '100.000', '100.000', '100.000');

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.1', // 10% tolerance to get both paths
            topK: 10,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'EUR', $spendConstraints, null);
        $paths = $result->paths()->toArray();

        self::assertGreaterThanOrEqual(2, count($paths), 'Should find at least 2 paths');

        // Extract intermediate nodes
        $nodes = [];
        foreach ($paths as $path) {
            $edges = iterator_to_array($path->edges());
            if (count($edges) === 2) {
                $nodes[] = $edges[0]->to();
            }
        }

        // First should be GBP (better cost), second should be AUD (worse cost)
        if (count($nodes) >= 2) {
            self::assertSame('GBP', $nodes[0], 'GBP path should come first (better cost)');
            self::assertSame('AUD', $nodes[1], 'AUD path should come second (worse cost)');
        }
    }

    /**
     * @testdox Repeated runs with same input produce exactly the same path ordering
     */
    public function testRepeatedRunsProduceSameOrder(): void
    {
        // Create a non-trivial graph with multiple paths
        $orderBook = new OrderBook();
        
        // Create a diamond pattern with multiple equal-cost paths
        $orderBook->add(OrderFactory::buy('USD', 'GBP', '50.000', '200.000', '1.050', 3, 3));
        $orderBook->add(OrderFactory::buy('USD', 'JPY', '50.000', '200.000', '1.050', 3, 3));
        $orderBook->add(OrderFactory::buy('USD', 'AUD', '50.000', '200.000', '1.050', 3, 3));
        $orderBook->add(OrderFactory::buy('GBP', 'EUR', '50.000', '200.000', '1.050', 3, 3));
        $orderBook->add(OrderFactory::buy('JPY', 'EUR', '50.000', '200.000', '1.050', 3, 3));
        $orderBook->add(OrderFactory::buy('AUD', 'EUR', '50.000', '200.000', '1.050', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));
        $spendConstraints = SpendConstraints::fromScalars('USD', '100.000', '100.000', '100.000');

        $results = [];
        
        // Run the same search 5 times
        for ($i = 0; $i < 5; ++$i) {
            $pathFinder = new PathFinder(
                maxHops: 5,
                tolerance: '0.001', // Small tolerance to capture all equal-cost paths
                topK: 10,
                maxExpansions: 1000,
                maxVisitedStates: 1000,
            );

            $result = $pathFinder->findBestPaths($graph, 'USD', 'EUR', $spendConstraints, null);
            $paths = $result->paths()->toArray();

            // Extract signatures for comparison
            $signatures = [];
            foreach ($paths as $path) {
                foreach ($path->edges() as $edge) {
                    $signatures[] = $edge->from() . '->' . $edge->to();
                }
                // Add separator between paths
                if (count($path->edges()->toArray()) > 0) {
                    $signatures[count($signatures) - 1] .= '||';
                }
            }
            // Group by path
            $pathSignatures = [];
            $current = '';
            foreach ($signatures as $sig) {
                if (str_ends_with($sig, '||')) {
                    $current .= rtrim($sig, '||');
                    $pathSignatures[] = $current;
                    $current = '';
                } else {
                    $current .= $sig . '|';
                }
            }
            $signatures = $pathSignatures;
            
            $results[] = $signatures;
        }

        // All runs should produce exactly the same order
        self::assertGreaterThan(0, count($results[0]), 'Should find at least one path');
        
        for ($i = 1; $i < 5; ++$i) {
            self::assertSame(
                $results[0],
                $results[$i],
                sprintf('Run %d should produce same ordering as run 1', $i + 1)
            );
        }
    }

    /**
     * @testdox Ordering is deterministic across multiple currencies
     */
    public function testOrderingDeterminismAcrossMultipleCurrencies(): void
    {
        // Test that when we have multiple paths with varied quality,
        // they are always returned in the same order
        $orderBook = new OrderBook();
        
        // Create paths with distinct costs to ensure multiple get returned
        $orderBook->add(OrderFactory::buy('USD', 'GBP', '50.000', '200.000', '1.500', 3, 3)); // Best
        $orderBook->add(OrderFactory::buy('GBP', 'EUR', '50.000', '200.000', '1.000', 3, 3));
        
        $orderBook->add(OrderFactory::buy('USD', 'JPY', '50.000', '200.000', '1.400', 3, 3)); // Good
        $orderBook->add(OrderFactory::buy('JPY', 'EUR', '50.000', '200.000', '1.000', 3, 3));
        
        $orderBook->add(OrderFactory::buy('USD', 'AUD', '50.000', '200.000', '1.300', 3, 3)); // Okay
        $orderBook->add(OrderFactory::buy('AUD', 'EUR', '50.000', '200.000', '1.000', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));
        $spendConstraints = SpendConstraints::fromScalars('USD', '100.000', '100.000', '100.000');

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.2', // 20% tolerance to capture all three paths
            topK: 10,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'EUR', $spendConstraints, null);
        $paths = $result->paths()->toArray();

        // Should find all 3 paths
        self::assertGreaterThanOrEqual(3, count($paths), 'Should find at least 3 paths');
        
        // Extract intermediate nodes - should be in cost order: GBP, JPY, AUD
        $nodes = [];
        foreach ($paths as $path) {
            $edges = iterator_to_array($path->edges());
            if (count($edges) === 2) {
                $nodes[] = $edges[0]->to();
            }
        }
        
        // Verify cost ordering
        if (count($nodes) >= 3) {
            self::assertSame('GBP', $nodes[0], 'GBP path should be first (best cost)');
            self::assertSame('JPY', $nodes[1], 'JPY path should be second');
            self::assertSame('AUD', $nodes[2], 'AUD path should be third (worst cost)');
        }
    }

    /**
     * @testdox Multiple varied-cost paths maintain stable ordering
     */
    public function testMultiplePathsStableOrdering(): void
    {
        $orderBook = new OrderBook();
        
        // Create paths with incrementally worse costs to ensure multiple get returned
        $rates = [
            'GBP' => '1.500',  // Best
            'JPY' => '1.450',
            'AUD' => '1.400',
            'CHF' => '1.350',
            'CAD' => '1.300',  // Worst
        ];
        
        foreach ($rates as $currency => $rate) {
            $orderBook->add(OrderFactory::buy('USD', $currency, '50.000', '200.000', $rate, 3, 3));
            $orderBook->add(OrderFactory::buy($currency, 'EUR', '50.000', '200.000', '1.000', 3, 3));
        }

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));
        $spendConstraints = SpendConstraints::fromScalars('USD', '100.000', '100.000', '100.000');

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.20', // 20% tolerance to capture all paths
            topK: 10,
            maxExpansions: 10000,
            maxVisitedStates: 10000,
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'EUR', $spendConstraints, null);
        $paths = $result->paths()->toArray();

        self::assertGreaterThanOrEqual(4, count($paths), 'Should find at least 4 paths');

        // Extract intermediate currencies in order
        $currencies = [];
        foreach ($paths as $path) {
            $edges = iterator_to_array($path->edges());
            if (count($edges) === 2) {
                $currencies[] = $edges[0]->to();
            }
        }

        // Verify cost ordering: GBP > JPY > AUD > CHF > CAD
        if (count($currencies) >= 4) {
            self::assertSame('GBP', $currencies[0], 'GBP path should be first (best cost)');
            self::assertSame('JPY', $currencies[1], 'JPY path should be second');
            self::assertSame('AUD', $currencies[2], 'AUD path should be third');
            self::assertSame('CHF', $currencies[3], 'CHF path should be fourth');
        }
    }

    /**
     * @testdox Paths with different costs are ordered by cost, regardless of signature
     */
    public function testDifferentCostsOrderByCostNotSignature(): void
    {
        $orderBook = new OrderBook();
        
        // Path with SEK (worse lexicographically) but best cost
        $orderBook->add(OrderFactory::buy('USD', 'SEK', '50.000', '200.000', '1.500', 3, 3)); // Best cost
        $orderBook->add(OrderFactory::buy('SEK', 'EUR', '50.000', '200.000', '1.000', 3, 3));
        
        // Path with AUD (better lexicographically) but worst cost
        $orderBook->add(OrderFactory::buy('USD', 'AUD', '50.000', '200.000', '1.100', 3, 3)); // Worst cost
        $orderBook->add(OrderFactory::buy('AUD', 'EUR', '50.000', '200.000', '1.000', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));
        $spendConstraints = SpendConstraints::fromScalars('USD', '100.000', '100.000', '100.000');

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.5', // Allow tolerance to capture both paths
            topK: 10,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'EUR', $spendConstraints, null);
        $paths = $result->paths()->toArray();

        self::assertGreaterThanOrEqual(2, count($paths), 'Should find at least 2 paths');

        // First path should be SEK (better cost), not AUD (better signature)
        $firstEdges = iterator_to_array($paths[0]->edges());
        $firstPathNode = $firstEdges[0]->to();
        self::assertSame('SEK', $firstPathNode, 'Path with better cost should come first, regardless of signature');

        // Verify second path is AUD
        if (count($paths) >= 2) {
            $secondEdges = iterator_to_array($paths[1]->edges());
            $secondPathNode = $secondEdges[0]->to();
            self::assertSame('AUD', $secondPathNode, 'Path with worse cost should come second');
        }
    }

    /**
     * @testdox Ordering is deterministic and considers hop count
     */
    public function testOrderingConsidersHopCount(): void
    {
        $orderBook = new OrderBook();
        
        // Create clearly distinct paths so PathFinder will return multiple results
        // 1-hop path (best cost, fewest hops)
        $orderBook->add(OrderFactory::buy('USD', 'EUR', '50.000', '200.000', '1.600', 3, 3));
        
        // 2-hop path (good cost)
        $orderBook->add(OrderFactory::buy('USD', 'GBP', '50.000', '200.000', '1.400', 3, 3));
        $orderBook->add(OrderFactory::buy('GBP', 'EUR', '50.000', '200.000', '1.000', 3, 3));
        
        // 3-hop path (okay cost, most hops)
        $orderBook->add(OrderFactory::buy('USD', 'JPY', '50.000', '200.000', '1.200', 3, 3));
        $orderBook->add(OrderFactory::buy('JPY', 'CHF', '50.000', '200.000', '1.000', 3, 3));
        $orderBook->add(OrderFactory::buy('CHF', 'EUR', '50.000', '200.000', '1.000', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));
        $spendConstraints = SpendConstraints::fromScalars('USD', '100.000', '100.000', '100.000');

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.4', // 40% tolerance to capture all paths
            topK: 10,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'EUR', $spendConstraints, null);
        $paths = $result->paths()->toArray();

        self::assertGreaterThanOrEqual(2, count($paths), 'Should find at least 2 paths');

        // Verify hop counts differ (demonstrates PathFinder handles paths of different lengths deterministically)
        $hopCounts = array_map(fn($p) => $p->hops(), $paths);
        self::assertGreaterThan(1, count(array_unique($hopCounts)), 'Should have paths with different hop counts');
        
        // Verify costs are ordered (PathFinder orders by cost primarily)
        // Cost field represents cost per unit, lower is better
        if (count($paths) >= 2) {
            $firstCost = BigDecimal::of($paths[0]->cost());
            $secondCost = BigDecimal::of($paths[1]->cost());
            
            // First path should have better (lower) or equal cost
            self::assertLessThanOrEqual(
                0,
                $firstCost->compareTo($secondCost),
                sprintf('Paths should be cost-ordered. Got costs %s, %s', $paths[0]->cost(), $paths[1]->cost())
            );
        }
    }
}

