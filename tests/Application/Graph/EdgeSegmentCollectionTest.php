<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Graph;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Graph\EdgeCapacity;
use SomeWork\P2PPathFinder\Application\Graph\EdgeSegment;
use SomeWork\P2PPathFinder\Application\Graph\EdgeSegmentCollection;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

use function iterator_to_array;

final class EdgeSegmentCollectionTest extends TestCase
{
    public function test_it_knows_when_it_is_empty(): void
    {
        $collection = EdgeSegmentCollection::empty();

        self::assertTrue($collection->isEmpty());
        self::assertSame(0, $collection->count());
        self::assertSame([], $collection->toArray());
        self::assertSame([], $collection->jsonSerialize());
        self::assertSame([], iterator_to_array($collection));
    }

    public function test_it_can_be_created_from_a_list_of_segments(): void
    {
        $first = $this->createSegment(true, '1', '2');
        $second = $this->createSegment(false, '2', '3');

        $collection = EdgeSegmentCollection::fromArray([$first, $second]);

        self::assertFalse($collection->isEmpty());
        self::assertCount(2, $collection);
        self::assertSame([$first, $second], $collection->toArray());
        self::assertSame([$first, $second], iterator_to_array($collection));
    }

    public function test_it_serializes_all_segments(): void
    {
        $first = $this->createSegment(true, '1', '2');
        $second = $this->createSegment(false, '2', '3');

        $collection = EdgeSegmentCollection::fromArray([$first, $second]);

        self::assertSame([
            $first->jsonSerialize(),
            $second->jsonSerialize(),
        ], $collection->jsonSerialize());
    }

    public function test_it_rejects_non_list_input(): void
    {
        $segment = $this->createSegment(true, '1', '2');

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Graph edge segments must be provided as a list.');

        EdgeSegmentCollection::fromArray(['segment' => $segment]);
    }

    public function test_it_rejects_non_edge_segment(): void
    {
        $segment = $this->createSegment(true, '1', '2');

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Graph edge segments must be instances of EdgeSegment.');

        EdgeSegmentCollection::fromArray([$segment, 'not-a-segment']);
    }

    private function createSegment(bool $isMandatory, string $minAmount, string $maxAmount): EdgeSegment
    {
        return new EdgeSegment(
            $isMandatory,
            new EdgeCapacity(
                Money::fromString('USD', $minAmount, 0),
                Money::fromString('USD', $maxAmount, 0),
            ),
            new EdgeCapacity(
                Money::fromString('EUR', $minAmount, 0),
                Money::fromString('EUR', $maxAmount, 0),
            ),
            new EdgeCapacity(
                Money::fromString('USD', $minAmount, 0),
                Money::fromString('USD', $maxAmount, 0),
            ),
        );
    }
}
