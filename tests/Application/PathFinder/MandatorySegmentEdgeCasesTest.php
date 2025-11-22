<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Graph\EdgeCapacity;
use SomeWork\P2PPathFinder\Application\Graph\EdgeSegment;
use SomeWork\P2PPathFinder\Application\Graph\EdgeSegmentCollection;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Application\PathFinder\PathFinder;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SegmentPruner;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\SpendConstraints;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

/**
 * Tests for mandatory segment edge cases to ensure correct pruning and aggregation.
 *
 * Mandatory segments represent capacity that MUST be filled (e.g., order minimums due to fees).
 * Optional segments represent additional capacity that MAY be filled up to the maximum.
 *
 * @internal
 */
#[CoversClass(SegmentPruner::class)]
#[CoversClass(EdgeSegmentCollection::class)]
final class MandatorySegmentEdgeCasesTest extends TestCase
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

        $pathFinder = new PathFinder(
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

    /**
     * @testdox All-mandatory vs mixed segments are aggregated correctly
     */
    public function test_all_mandatory_vs_mixed_segments(): void
    {
        // Test Case 1: Edge with only mandatory segment (no optional headroom)
        $allMandatory = EdgeSegmentCollection::fromArray([
            $this->segment(true, '100.000', '100.000'), // Mandatory [100,100]
        ]);

        $totals1 = $allMandatory->capacityTotals(EdgeSegmentCollection::MEASURE_QUOTE, 3);
        self::assertNotNull($totals1);
        self::assertSame('100.000', $totals1->mandatory()->amount());
        self::assertSame('100.000', $totals1->maximum()->amount());
        self::assertSame('0.000', $totals1->optionalHeadroom()->amount(), 'All-mandatory should have zero headroom');

        // Test Case 2: Edge with mandatory + optional segments
        $mixed = EdgeSegmentCollection::fromArray([
            $this->segment(true, '100.000', '100.000'),  // Mandatory [100,100]
            $this->segment(false, '0.000', '400.000'),   // Optional [0,400]
        ]);

        $totals2 = $mixed->capacityTotals(EdgeSegmentCollection::MEASURE_QUOTE, 3);
        self::assertNotNull($totals2);
        self::assertSame('100.000', $totals2->mandatory()->amount());
        self::assertSame('500.000', $totals2->maximum()->amount());
        self::assertSame('400.000', $totals2->optionalHeadroom()->amount(), 'Mixed should have positive headroom');
    }

    /**
     * @testdox Zero mandatory capacity (all optional) is aggregated correctly
     */
    public function test_zero_mandatory_capacity(): void
    {
        // Edge with only optional segments (no mandatory minimum)
        $allOptional = EdgeSegmentCollection::fromArray([
            $this->segment(false, '0.000', '500.000'), // Optional [0,500]
        ]);

        $totals = $allOptional->capacityTotals(EdgeSegmentCollection::MEASURE_QUOTE, 3);
        self::assertNotNull($totals);
        self::assertSame('0.000', $totals->mandatory()->amount(), 'All-optional should have zero mandatory');
        self::assertSame('500.000', $totals->maximum()->amount());
        self::assertSame('500.000', $totals->optionalHeadroom()->amount(), 'Headroom should equal maximum when mandatory=0');
    }

    /**
     * @testdox Segment pruner correctly prunes when optional headroom is zero
     */
    public function test_mandatory_segment_pruning_zero_headroom(): void
    {
        $pruner = new SegmentPruner(EdgeSegmentCollection::MEASURE_QUOTE);

        // Mandatory segment with no optional headroom
        $segments = EdgeSegmentCollection::fromArray([
            $this->segment(true, '100.000', '100.000'),  // Mandatory [100,100]
            $this->segment(false, '0.000', '0.000'),     // Optional [0,0] - should be pruned
            $this->segment(false, '0.000', '0.000'),     // Optional [0,0] - should be pruned
        ]);

        $pruned = $pruner->prune($segments)->toArray();

        // Only mandatory segment should remain
        self::assertCount(1, $pruned, 'Should keep only mandatory segment when headroom=0');
        self::assertTrue($pruned[0]->isMandatory(), 'Remaining segment should be mandatory');
    }

    /**
     * @testdox Segment pruner correctly handles boundaries with mixed capacities
     */
    public function test_mandatory_segment_pruning_at_boundaries(): void
    {
        $pruner = new SegmentPruner(EdgeSegmentCollection::MEASURE_QUOTE);

        // Mixed segments with varying capacities
        $segments = EdgeSegmentCollection::fromArray([
            $this->segment(true, '50.000', '50.000'),    // Mandatory [50,50]
            $this->segment(false, '0.000', '100.000'),   // Optional [0,100]
            $this->segment(false, '0.000', '0.000'),     // Optional [0,0] - zero capacity, should be pruned
            $this->segment(false, '0.000', '25.000'),    // Optional [0,25]
        ]);

        $pruned = $pruner->prune($segments)->toArray();

        // Should keep mandatory + non-zero optionals, prune zero-capacity optional
        self::assertCount(3, $pruned, 'Should keep mandatory + 2 non-zero optionals');

        // First should be mandatory
        self::assertTrue($pruned[0]->isMandatory(), 'First segment should be mandatory');

        // Remaining should be optionals sorted by max capacity DESC
        self::assertFalse($pruned[1]->isMandatory(), 'Second should be optional');
        self::assertFalse($pruned[2]->isMandatory(), 'Third should be optional');

        // Verify sorting: higher max capacity first
        self::assertSame('100.000', $pruned[1]->quote()->max()->amount());
        self::assertSame('25.000', $pruned[2]->quote()->max()->amount());
    }

    /**
     * @testdox Segment collection correctly represents order bounds
     */
    public function test_segment_collection_represents_order_bounds(): void
    {
        // Simulate what GraphBuilder creates for an order with min/max bounds
        // When hasFees=true and minBase > 0, GraphBuilder creates:
        // 1. Mandatory segment [min, min]
        // 2. Optional segment [0, max-min]

        $segments = EdgeSegmentCollection::fromArray([
            $this->segment(true, '100.000', '100.000'),  // Mandatory [100,100]
            $this->segment(false, '0.000', '400.000'),   // Optional [0,400]
        ]);

        // Verify capacity totals match expected order bounds
        $totals = $segments->capacityTotals(EdgeSegmentCollection::MEASURE_BASE, 3);
        self::assertNotNull($totals);

        // Mandatory should equal the order minimum (100)
        self::assertSame('100.000', $totals->mandatory()->amount());

        // Maximum should equal the order maximum (100 + 400 = 500)
        self::assertSame('500.000', $totals->maximum()->amount());

        // Headroom should be max - min (400)
        self::assertSame('400.000', $totals->optionalHeadroom()->amount());

        // This structure correctly represents an order with bounds [100, 500]
    }

    /**
     * @testdox Multiple mandatory segments are aggregated correctly
     */
    public function test_multiple_mandatory_segments(): void
    {
        // Edge with multiple mandatory segments (unusual but should be handled)
        $segments = EdgeSegmentCollection::fromArray([
            $this->segment(true, '50.000', '50.000'),   // Mandatory [50,50]
            $this->segment(true, '30.000', '30.000'),   // Mandatory [30,30]
            $this->segment(false, '0.000', '100.000'),  // Optional [0,100]
        ]);

        $totals = $segments->capacityTotals(EdgeSegmentCollection::MEASURE_QUOTE, 3);
        self::assertNotNull($totals);

        // Mandatory = sum of all mandatory mins = 50 + 30 = 80
        self::assertSame('80.000', $totals->mandatory()->amount());

        // Maximum = sum of all maxes = 50 + 30 + 100 = 180
        self::assertSame('180.000', $totals->maximum()->amount());

        // Headroom = 180 - 80 = 100
        self::assertSame('100.000', $totals->optionalHeadroom()->amount());
    }

    /**
     * @testdox Segment sorting is stable and deterministic
     */
    public function test_segment_sorting_determinism(): void
    {
        $pruner = new SegmentPruner(EdgeSegmentCollection::MEASURE_QUOTE);

        // Create segments with identical max capacities to test tie-breaking
        $segments1 = EdgeSegmentCollection::fromArray([
            $this->segment(false, '10.000', '100.000'), // Optional [10,100]
            $this->segment(true, '50.000', '50.000'),   // Mandatory [50,50]
            $this->segment(false, '20.000', '100.000'), // Optional [20,100] - same max as first
        ]);

        $segments2 = EdgeSegmentCollection::fromArray([
            $this->segment(false, '20.000', '100.000'), // Different insertion order
            $this->segment(false, '10.000', '100.000'),
            $this->segment(true, '50.000', '50.000'),
        ]);

        $pruned1 = $pruner->prune($segments1)->toArray();
        $pruned2 = $pruner->prune($segments2)->toArray();

        // Both should have same count
        self::assertCount(3, $pruned1);
        self::assertCount(3, $pruned2);

        // Both should have mandatory first
        self::assertTrue($pruned1[0]->isMandatory());
        self::assertTrue($pruned2[0]->isMandatory());

        // Optionals should be sorted by max DESC, then min DESC
        // Since both optionals have max=100, sort by min DESC: 20 before 10
        self::assertSame('20.000', $pruned1[1]->quote()->min()->amount());
        self::assertSame('10.000', $pruned1[2]->quote()->min()->amount());

        self::assertSame('20.000', $pruned2[1]->quote()->min()->amount());
        self::assertSame('10.000', $pruned2[2]->quote()->min()->amount());
    }

    /**
     * Helper to create a segment with specified mandatory flag and quote capacity.
     */
    private function segment(bool $mandatory, string $min, string $max, int $scale = 3): EdgeSegment
    {
        return new EdgeSegment(
            $mandatory,
            new EdgeCapacity(
                Money::fromString('BASE', $min, $scale),
                Money::fromString('BASE', $max, $scale),
            ),
            new EdgeCapacity(
                Money::fromString('USD', $min, $scale),
                Money::fromString('USD', $max, $scale),
            ),
            new EdgeCapacity(
                Money::fromString('BASE', $min, $scale),
                Money::fromString('BASE', $max, $scale),
            ),
        );
    }
}
