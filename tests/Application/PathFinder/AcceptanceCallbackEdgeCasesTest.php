<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Application\PathFinder\PathFinder;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\CandidatePath;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\SpendConstraints;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

/**
 * Tests edge cases for the acceptance callback mechanism.
 *
 * The acceptance callback is invoked when a path reaches the target, allowing
 * consumers to filter paths based on custom criteria before they're added to results.
 *
 * @internal
 */
#[CoversClass(PathFinder::class)]
final class AcceptanceCallbackEdgeCasesTest extends TestCase
{
    /**
     * @testdox Callback that always returns false yields empty results but search completes
     */
    public function testCallbackAlwaysReturnsFalse(): void
    {
        $orderBook = new OrderBook();
        $orderBook->add(OrderFactory::buy('USD', 'GBP', '50.000', '150.000', '1.200', 3, 3));
        $orderBook->add(OrderFactory::buy('GBP', 'EUR', '50.000', '150.000', '1.100', 3, 3));
        $orderBook->add(OrderFactory::buy('USD', 'EUR', '50.000', '150.000', '1.300', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));

        $invocationCount = 0;
        $discoveredPaths = [];

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.15',
            topK: 10,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $result = $pathFinder->findBestPaths(
            $graph,
            'USD',
            'EUR',
            null,
            function (CandidatePath $candidate) use (&$invocationCount, &$discoveredPaths): bool {
                ++$invocationCount;
                $discoveredPaths[] = [
                    'hops' => $candidate->hops(),
                    'cost' => $candidate->cost(),
                ];
                return false; // Reject all paths
            }
        );

        // Should discover paths but not accept any
        self::assertGreaterThan(0, $invocationCount, 'Callback should be invoked for discovered paths');
        self::assertGreaterThan(0, count($discoveredPaths), 'Should discover multiple paths');
        
        // No results should be returned
        self::assertCount(0, $result->paths()->toArray(), 'Result should be empty when all paths rejected');
        
        // Search should complete normally (no errors)
        $guardReport = $result->guardLimits();
        self::assertFalse($guardReport->anyLimitReached(), 'Search should complete without hitting guards');
    }

    /**
     * @testdox Callback with complex acceptance criteria filters paths correctly
     */
    public function testCallbackWithComplexCriteria(): void
    {
        // Create multiple paths with different characteristics
        $orderBook = new OrderBook();
        
        // Direct path: USD → EUR (1 hop, best rate)
        $orderBook->add(OrderFactory::buy('USD', 'EUR', '50.000', '150.000', '1.300', 3, 3));
        
        // 2-hop paths
        $orderBook->add(OrderFactory::buy('USD', 'GBP', '50.000', '150.000', '1.200', 3, 3));
        $orderBook->add(OrderFactory::buy('GBP', 'EUR', '50.000', '150.000', '1.100', 3, 3));
        
        // 3-hop path
        $orderBook->add(OrderFactory::buy('USD', 'CHF', '50.000', '150.000', '1.150', 3, 3));
        $orderBook->add(OrderFactory::buy('CHF', 'GBP', '50.000', '150.000', '1.050', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.20',
            topK: 10,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        // Only accept paths with exactly 2 hops
        $result = $pathFinder->findBestPaths(
            $graph,
            'USD',
            'EUR',
            null,
            fn(CandidatePath $c) => $c->hops() === 2
        );

        $paths = $result->paths()->toArray();
        
        // Should only have 2-hop paths
        self::assertGreaterThan(0, count($paths), 'Should find 2-hop paths');
        foreach ($paths as $path) {
            self::assertSame(2, $path->hops(), 'All returned paths should have exactly 2 hops');
        }
    }

    /**
     * @testdox Callback can collect candidates without accepting any
     */
    public function testCallbackCollectsWithoutAccepting(): void
    {
        $orderBook = new OrderBook();
        $orderBook->add(OrderFactory::buy('USD', 'GBP', '50.000', '150.000', '1.200', 3, 3));
        $orderBook->add(OrderFactory::buy('GBP', 'EUR', '50.000', '150.000', '1.100', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));

        $allCandidates = [];

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.0',
            topK: 10,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $result = $pathFinder->findBestPaths(
            $graph,
            'USD',
            'EUR',
            null,
            function (CandidatePath $candidate) use (&$allCandidates): bool {
                // Collect candidate details
                $allCandidates[] = [
                    'hops' => $candidate->hops(),
                    'cost' => $candidate->cost(),
                    'product' => $candidate->product(),
                    'edgeCount' => $candidate->edges()->count(),
                ];
                return false; // Don't accept any
            }
        );

        // Should have collected candidates
        self::assertGreaterThan(0, count($allCandidates), 'Should collect candidate information');
        
        // But results should be empty
        self::assertCount(0, $result->paths()->toArray(), 'Results should be empty');
        
        // Verify collected data structure
        foreach ($allCandidates as $candidate) {
            self::assertArrayHasKey('hops', $candidate);
            self::assertArrayHasKey('cost', $candidate);
            self::assertArrayHasKey('product', $candidate);
            self::assertArrayHasKey('edgeCount', $candidate);
        }
    }

    /**
     * @testdox Callback rejection affects tolerance pruning correctly
     */
    public function testCallbackRejectionAffectsTolerancePruning(): void
    {
        // Create graph where callback rejection influences which path becomes "best"
        $orderBook = new OrderBook();
        
        // Good direct path (cost ~1.3)
        $orderBook->add(OrderFactory::buy('USD', 'EUR', '50.000', '150.000', '1.300', 3, 3));
        
        // Worse 2-hop path (cost ~1.32)
        $orderBook->add(OrderFactory::buy('USD', 'GBP', '50.000', '150.000', '1.200', 3, 3));
        $orderBook->add(OrderFactory::buy('GBP', 'EUR', '50.000', '150.000', '1.100', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.05', // 5% tolerance
            topK: 10,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        // Reject direct path, accept 2-hop path
        $result = $pathFinder->findBestPaths(
            $graph,
            'USD',
            'EUR',
            null,
            fn(CandidatePath $c) => $c->hops() >= 2
        );

        $paths = $result->paths()->toArray();
        
        // Should only have 2-hop path (direct was rejected)
        self::assertGreaterThan(0, count($paths), 'Should find paths');
        self::assertSame(2, $paths[0]->hops(), 'Best path should be 2-hop (direct was rejected)');
    }

    /**
     * @testdox Null callback accepts all paths
     */
    public function testNullCallbackAcceptsAll(): void
    {
        $orderBook = new OrderBook();
        $orderBook->add(OrderFactory::buy('USD', 'EUR', '50.000', '150.000', '1.300', 3, 3));
        $orderBook->add(OrderFactory::buy('USD', 'GBP', '50.000', '150.000', '1.200', 3, 3));
        $orderBook->add(OrderFactory::buy('GBP', 'EUR', '50.000', '150.000', '1.100', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.15',
            topK: 10,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        // Null callback should accept all
        $result = $pathFinder->findBestPaths($graph, 'USD', 'EUR', null, null);

        $paths = $result->paths()->toArray();
        
        // Should have multiple paths (all discovered paths accepted)
        self::assertGreaterThan(0, count($paths), 'Should find multiple paths with null callback');
    }

    /**
     * @testdox Callback invoked multiple times as paths are discovered
     */
    public function testCallbackInvokedMultipleTimes(): void
    {
        $orderBook = new OrderBook();
        
        // Create multiple paths
        $orderBook->add(OrderFactory::buy('USD', 'EUR', '50.000', '150.000', '1.300', 3, 3));
        $orderBook->add(OrderFactory::buy('USD', 'GBP', '50.000', '150.000', '1.200', 3, 3));
        $orderBook->add(OrderFactory::buy('GBP', 'EUR', '50.000', '150.000', '1.100', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));

        $invocations = [];

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.15',
            topK: 10,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $result = $pathFinder->findBestPaths(
            $graph,
            'USD',
            'EUR',
            null,
            function (CandidatePath $candidate) use (&$invocations): bool {
                $invocations[] = $candidate->hops();
                return true;
            }
        );

        // Should be invoked multiple times (once per discovered path)
        self::assertGreaterThanOrEqual(2, count($invocations), 'Callback should be invoked multiple times');
        
        // Invocations should have different hop counts
        $uniqueHops = array_unique($invocations);
        self::assertGreaterThan(1, count($uniqueHops), 'Should discover paths with different hop counts');
    }

    /**
     * @testdox Callback with spend constraints can access range information
     */
    public function testCallbackAccessesSpendConstraints(): void
    {
        $orderBook = new OrderBook();
        $orderBook->add(OrderFactory::buy('USD', 'EUR', '50.000', '150.000', '1.300', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));

        $spend = Money::fromString('USD', '100.00', 2);
        $constraints = SpendConstraints::from($spend, $spend, $spend);

        $receivedConstraints = null;

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.0',
            topK: 10,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $result = $pathFinder->findBestPaths(
            $graph,
            'USD',
            'EUR',
            $constraints,
            function (CandidatePath $candidate) use (&$receivedConstraints): bool {
                $receivedConstraints = $candidate->range();
                return true;
            }
        );

        // Callback should receive spend constraints
        self::assertNotNull($receivedConstraints, 'Callback should receive spend constraints');
        // The range currency is converted to target currency (EUR) during search
        self::assertSame('EUR', $receivedConstraints->internalRange()->currency());
    }

    /**
     * @testdox Callback respects top-K limit (only accepted paths count toward limit)
     */
    public function testCallbackRespectsTopKLimit(): void
    {
        $orderBook = new OrderBook();
        
        // Create many potential paths
        $orderBook->add(OrderFactory::buy('USD', 'EUR', '50.000', '150.000', '1.300', 3, 3));
        $orderBook->add(OrderFactory::buy('USD', 'GBP', '50.000', '150.000', '1.200', 3, 3));
        $orderBook->add(OrderFactory::buy('GBP', 'EUR', '50.000', '150.000', '1.100', 3, 3));
        $orderBook->add(OrderFactory::buy('USD', 'CHF', '50.000', '150.000', '1.150', 3, 3));
        $orderBook->add(OrderFactory::buy('CHF', 'EUR', '50.000', '150.000', '1.050', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));

        $topK = 2;
        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.20',
            topK: $topK,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $result = $pathFinder->findBestPaths(
            $graph,
            'USD',
            'EUR',
            null,
            fn(CandidatePath $c) => true // Accept all
        );

        $paths = $result->paths()->toArray();
        
        // Should respect top-K limit
        self::assertLessThanOrEqual($topK, count($paths), 'Should respect topK limit');
    }

    /**
     * @testdox Callback can use side effects for logging or metrics
     */
    public function testCallbackSideEffectsForLogging(): void
    {
        $orderBook = new OrderBook();
        $orderBook->add(OrderFactory::buy('USD', 'GBP', '50.000', '150.000', '1.200', 3, 3));
        $orderBook->add(OrderFactory::buy('GBP', 'EUR', '50.000', '150.000', '1.100', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));

        // Simulate logging/metrics collection
        $metrics = [
            'totalCandidates' => 0,
            'acceptedCandidates' => 0,
            'rejectedCandidates' => 0,
            'minCost' => null,
            'maxCost' => null,
        ];

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.0',
            topK: 10,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $result = $pathFinder->findBestPaths(
            $graph,
            'USD',
            'EUR',
            null,
            function (CandidatePath $candidate) use (&$metrics): bool {
                ++$metrics['totalCandidates'];
                
                $cost = (float) $candidate->cost();
                $metrics['minCost'] = $metrics['minCost'] === null ? $cost : min($metrics['minCost'], $cost);
                $metrics['maxCost'] = $metrics['maxCost'] === null ? $cost : max($metrics['maxCost'], $cost);
                
                // Accept only 2-hop paths
                $accept = $candidate->hops() === 2;
                if ($accept) {
                    ++$metrics['acceptedCandidates'];
                } else {
                    ++$metrics['rejectedCandidates'];
                }
                
                return $accept;
            }
        );

        // Metrics should be collected
        self::assertGreaterThan(0, $metrics['totalCandidates'], 'Should process candidates');
        self::assertGreaterThan(0, $metrics['acceptedCandidates'], 'Should accept some candidates');
        self::assertNotNull($metrics['minCost'], 'Should track min cost');
        self::assertNotNull($metrics['maxCost'], 'Should track max cost');
        
        // Verify metrics consistency
        self::assertSame(
            $metrics['totalCandidates'],
            $metrics['acceptedCandidates'] + $metrics['rejectedCandidates'],
            'Total should equal accepted + rejected'
        );
    }

    /**
     * @testdox Callback exception propagates to caller
     */
    public function testCallbackExceptionPropagates(): void
    {
        $orderBook = new OrderBook();
        $orderBook->add(OrderFactory::buy('USD', 'EUR', '50.000', '150.000', '1.300', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));

        $pathFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.0',
            topK: 10,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Callback error');

        $pathFinder->findBestPaths(
            $graph,
            'USD',
            'EUR',
            null,
            function (CandidatePath $candidate): bool {
                throw new \RuntimeException('Callback error');
            }
        );
    }
}

