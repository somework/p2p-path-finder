<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Engine\State;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\State\SegmentPruner;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\EdgeCapacity;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\EdgeSegment;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\EdgeSegmentCollection;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

#[CoversClass(SegmentPruner::class)]
final class SegmentPrunerTest extends TestCase
{
    public function test_constructor_rejects_invalid_measure(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Unsupported segment capacity measure "invalid".');

        new SegmentPruner('invalid');
    }

    public function test_it_preserves_segments_when_optional_headroom_exists(): void
    {
        $pruner = new SegmentPruner(EdgeSegmentCollection::MEASURE_QUOTE);

        $segments = EdgeSegmentCollection::fromArray([
            $this->segment(true, '1.000', '2.500'),
            $this->segment(false, '0.000', '1.500'),
            $this->segment(false, '0.000', '0.500'),
        ]);

        $pruned = $pruner->prune($segments)->toArray();

        self::assertCount(3, $pruned);
        self::assertTrue($pruned[0]->isMandatory());
        self::assertFalse($pruned[1]->isMandatory());
        self::assertFalse($pruned[2]->isMandatory());

        self::assertSame('1.500', $pruned[1]->quote()->max()->withScale(3)->amount());
        self::assertSame('0.500', $pruned[2]->quote()->max()->withScale(3)->amount());
    }

    public function test_it_discards_optional_zero_capacity_segments(): void
    {
        $pruner = new SegmentPruner(EdgeSegmentCollection::MEASURE_QUOTE);

        $segments = EdgeSegmentCollection::fromArray([
            $this->segment(true, '1.000', '2.000'),
            $this->segment(false, '0.000', '0.000'),
            $this->segment(false, '0.000', '0.000'),
        ]);

        $pruned = $pruner->prune($segments)->toArray();

        self::assertCount(1, $pruned);
        self::assertTrue($pruned[0]->isMandatory());
    }

    public function test_it_discards_all_optionals_when_optional_headroom_is_zero(): void
    {
        $pruner = new SegmentPruner(EdgeSegmentCollection::MEASURE_QUOTE);

        $segments = EdgeSegmentCollection::fromArray([
            $this->segmentWithCapacities(
                true,
                $this->capacity('BASE', '2.000', '2.000', 3),
                $this->capacity('USD', '2.000', '2.000', 3),
                $this->capacity('BASE', '2.000', '2.000', 3),
            ),
            $this->segment(false, '0.000', '0.000'),
            $this->segment(false, '0.000', '0.000'),
        ]);

        $pruned = $pruner->prune($segments)->toArray();

        self::assertCount(1, $pruned);
        self::assertTrue($pruned[0]->isMandatory());
    }

    public function test_it_honours_requested_capacity_measure(): void
    {
        $pruner = new SegmentPruner(EdgeSegmentCollection::MEASURE_BASE);

        $segments = EdgeSegmentCollection::fromArray([
            $this->segmentWithCapacities(
                true,
                $this->capacity('BASE', '1.000', '1.000', 3),
                $this->capacity('USD', '0.000', '5.000', 3),
                $this->capacity('BASE', '1.000', '1.000', 3),
            ),
            $this->segmentWithCapacities(
                false,
                $this->capacity('BASE', '0.000', '0.000', 3),
                $this->capacity('USD', '0.000', '5.000', 3),
                $this->capacity('BASE', '0.000', '0.000', 3),
            ),
            $this->segmentWithCapacities(
                false,
                $this->capacity('BASE', '0.000', '2.000', 3),
                $this->capacity('USD', '0.000', '0.000', 3),
                $this->capacity('BASE', '0.000', '2.000', 3),
            ),
        ]);

        $pruned = $pruner->prune($segments)->toArray();

        self::assertCount(2, $pruned);
        self::assertTrue($pruned[0]->isMandatory());
        self::assertSame('2.000', $pruned[1]->base()->max()->withScale(3)->amount());
    }

    public function test_it_honours_gross_base_capacity_measure(): void
    {
        $pruner = new SegmentPruner(EdgeSegmentCollection::MEASURE_GROSS_BASE);

        $segments = EdgeSegmentCollection::fromArray([
            $this->segmentWithCapacities(
                true,
                $this->capacity('BASE', '1.000', '1.000', 3),
                $this->capacity('USD', '0.000', '5.000', 3),
                $this->capacity('BASE', '1.000', '1.000', 3),
            ),
            $this->segmentWithCapacities(
                false,
                $this->capacity('BASE', '0.000', '0.000', 3),
                $this->capacity('USD', '0.000', '5.000', 3),
                $this->capacity('BASE', '0.000', '2.000', 3),
            ),
            $this->segmentWithCapacities(
                false,
                $this->capacity('BASE', '0.000', '3.000', 3),
                $this->capacity('USD', '0.000', '0.000', 3),
                $this->capacity('BASE', '0.000', '0.000', 3),
            ),
        ]);

        $pruned = $pruner->prune($segments)->toArray();

        self::assertCount(2, $pruned);
        self::assertTrue($pruned[0]->isMandatory());
        self::assertSame('2.000', $pruned[1]->grossBase()->max()->withScale(3)->amount());
    }

    public function test_it_orders_optionals_by_maximum_then_minimum_capacity(): void
    {
        $pruner = new SegmentPruner(EdgeSegmentCollection::MEASURE_QUOTE);

        $segments = EdgeSegmentCollection::fromArray([
            $this->segment(false, '0.250', '2.000'),
            $this->segment(true, '1.000', '1.500'),
            $this->segment(false, '0.750', '2.000'),
            $this->segment(false, '0.500', '1.000'),
        ]);

        $pruned = $pruner->prune($segments)->toArray();

        self::assertCount(4, $pruned);
        self::assertTrue($pruned[0]->isMandatory());
        self::assertSame('0.750', $pruned[1]->quote()->min()->withScale(3)->amount());
        self::assertSame('0.250', $pruned[2]->quote()->min()->withScale(3)->amount());
        self::assertSame('1.000', $pruned[3]->quote()->max()->withScale(3)->amount());
    }

    public function test_it_is_deterministic_across_insertion_orders(): void
    {
        $pruner = new SegmentPruner(EdgeSegmentCollection::MEASURE_QUOTE);

        $first = EdgeSegmentCollection::fromArray([
            $this->segment(false, '0.000', '0.500'),
            $this->segment(true, '1.000', '2.500'),
            $this->segment(false, '0.000', '1.500'),
        ]);

        $second = EdgeSegmentCollection::fromArray([
            $this->segment(true, '1.000', '2.500'),
            $this->segment(false, '0.000', '1.500'),
            $this->segment(false, '0.000', '0.500'),
        ]);

        $firstResult = $pruner->prune($first)->toArray();
        $secondResult = $pruner->prune($second)->toArray();

        self::assertCount(3, $firstResult);
        self::assertCount(3, $secondResult);

        foreach ($firstResult as $index => $segment) {
            self::assertSame($segment->isMandatory(), $secondResult[$index]->isMandatory());
            self::assertTrue($segment->quote()->max()->equals($secondResult[$index]->quote()->max()));
            self::assertTrue($segment->quote()->min()->equals($secondResult[$index]->quote()->min()));
        }
    }

    public function test_it_returns_empty_collection_unchanged(): void
    {
        $pruner = new SegmentPruner(EdgeSegmentCollection::MEASURE_QUOTE);
        $emptyCollection = EdgeSegmentCollection::fromArray([]);

        $result = $pruner->prune($emptyCollection);

        self::assertTrue($result->isEmpty());
        self::assertSame($emptyCollection, $result);
    }

    public function test_it_prunes_segments_when_optional_headroom_is_zero(): void
    {
        $pruner = new SegmentPruner(EdgeSegmentCollection::MEASURE_QUOTE);

        // Create segments where mandatory capacity equals maximum (zero headroom)
        $segments = EdgeSegmentCollection::fromArray([
            $this->segmentWithCapacities(
                true,
                $this->capacity('BASE', '100.000', '100.000', 3),
                $this->capacity('USD', '100.000', '100.000', 3),
                $this->capacity('BASE', '100.000', '100.000', 3),
            ),
            $this->segment(false, '0.000', '0.000'), // Zero capacity optional
            $this->segment(false, '0.000', '0.000'), // Zero capacity optional
        ]);

        $pruned = $pruner->prune($segments)->toArray();

        // Only mandatory segment should remain when headroom is zero
        self::assertCount(1, $pruned, 'Should keep only mandatory segment when headroom=0');
        self::assertTrue($pruned[0]->isMandatory(), 'Remaining segment should be mandatory');
    }

    public function test_it_prunes_and_sorts_segments_with_mixed_capacities(): void
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
        self::assertSame('100.000', $pruned[1]->quote()->max()->withScale(3)->amount());
        self::assertSame('25.000', $pruned[2]->quote()->max()->withScale(3)->amount());
    }

    public function test_it_sorts_segments_deterministically(): void
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
        self::assertSame('20.000', $pruned1[1]->quote()->min()->withScale(3)->amount());
        self::assertSame('10.000', $pruned1[2]->quote()->min()->withScale(3)->amount());

        self::assertSame('20.000', $pruned2[1]->quote()->min()->withScale(3)->amount());
        self::assertSame('10.000', $pruned2[2]->quote()->min()->withScale(3)->amount());
    }

    private function segment(bool $mandatory, string $min, string $max, int $scale = 3): EdgeSegment
    {
        return $this->segmentWithCapacities(
            $mandatory,
            $this->capacity('BASE', $min, $max, $scale),
            $this->capacity('USD', $min, $max, $scale),
            $this->capacity('BASE', $min, $max, $scale),
        );
    }

    private function segmentWithCapacities(
        bool $mandatory,
        EdgeCapacity $base,
        EdgeCapacity $quote,
        EdgeCapacity $grossBase
    ): EdgeSegment {
        return new EdgeSegment(
            $mandatory,
            $base,
            $quote,
            $grossBase,
        );
    }

    private function capacity(string $currency, string $min, string $max, int $scale): EdgeCapacity
    {
        return new EdgeCapacity(
            Money::fromString($currency, $min, $scale),
            Money::fromString($currency, $max, $scale),
        );
    }
}
