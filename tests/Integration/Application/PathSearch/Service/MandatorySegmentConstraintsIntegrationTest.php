<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Integration\Application\PathSearch\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\PathSearchEngine;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\SpendConstraints;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;
use SomeWork\P2PPathFinder\Domain\Order\OrderBook;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

/**
 * Integration tests for mandatory segment constraints in path finding.
 *
 * Tests scenarios where mandatory segments (representing order minimums due to fees)
 * interact with spend constraints to ensure proper path rejection.
 */
#[CoversClass(PathSearchEngine::class)]
#[CoversClass(GraphBuilder::class)]
final class MandatorySegmentConstraintsIntegrationTest extends TestCase
{
    /**
     * @testdox Segments with mandatory capacity exceeding spend constraints are handled correctly
     */
    public function test_mandatory_segments_exceeding_constraints(): void
    {
        // Create an order with high minimum (due to fees)
        // This creates a mandatory segment that may exceed our spend amount
        $orderBook = new OrderBook();

        // Order with min=200, max=500 (mandatory segment [200,200] + optional [0,300])
        $orderBook->add(OrderFactory::buy('USD', 'EUR', '200.000', '500.000', '1.200', 3, 3));

        $graph = (new GraphBuilder())->build(iterator_to_array($orderBook));

        // Try to spend only 100 USD (less than the 200 USD minimum)
        $spendConstraints = SpendConstraints::fromScalars('USD', '100.000', '100.000', '100.000');

        $pathFinder = new PathSearchEngine(
            maxHops: 5,
            tolerance: '0.0',
            topK: 10,
            maxExpansions: 1000,
            maxVisitedStates: 1000,
        );

        $result = $pathFinder->findBestPaths($graph, 'USD', 'EUR', $spendConstraints, null);

        // PathFinder should find no valid paths since we can't meet the mandatory minimum
        // (spend amount 100 < mandatory minimum 200)
        self::assertCount(0, $result->paths()->toArray(), 'Should find no paths when spend < mandatory minimum');
    }
}
