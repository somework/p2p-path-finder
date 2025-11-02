<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder\Search;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Graph\EdgeCapacity;
use SomeWork\P2PPathFinder\Application\Graph\EdgeSegment;
use SomeWork\P2PPathFinder\Application\Graph\EdgeSegmentCollection;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SegmentPruner;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

final class SegmentPrunerTest extends TestCase
{
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
