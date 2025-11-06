<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder\Result;

use JsonSerializable;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\CostHopsSignatureOrderingStrategy;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathCost;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathOrderKey;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\RouteSignature;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\PathResultSet;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\PathResultSetEntry;

final class PathResultSetTest extends TestCase
{
    public function test_it_orders_entries_using_strategy(): void
    {
        $strategy = new CostHopsSignatureOrderingStrategy(18);
        $set = PathResultSet::fromEntries(
            $strategy,
            [
                new PathResultSetEntry('late', new PathOrderKey(new PathCost('0.300000000000000000'), 3, new RouteSignature(['SRC', 'C', 'DST']), 2)),
                new PathResultSetEntry('early', new PathOrderKey(new PathCost('0.100000000000000000'), 1, new RouteSignature(['SRC', 'A', 'DST']), 0)),
                new PathResultSetEntry('middle', new PathOrderKey(new PathCost('0.100000000000000000'), 2, new RouteSignature(['SRC', 'B', 'DST']), 1)),
            ],
        );

        self::assertSame(['early', 'middle', 'late'], $set->toArray());
        self::assertSame($set->toArray(), iterator_to_array($set));
    }

    public function test_it_discards_duplicate_route_signatures(): void
    {
        $strategy = new CostHopsSignatureOrderingStrategy(18);
        $set = PathResultSet::fromEntries(
            $strategy,
            [
                new PathResultSetEntry('worse duplicate', new PathOrderKey(new PathCost('0.300000000000000000'), 3, new RouteSignature(['SRC', 'A', 'DST']), 2)),
                new PathResultSetEntry('preferred duplicate', new PathOrderKey(new PathCost('0.100000000000000000'), 1, new RouteSignature(['SRC', 'A', 'DST']), 0)),
                new PathResultSetEntry('unique', new PathOrderKey(new PathCost('0.200000000000000000'), 2, new RouteSignature(['SRC', 'B', 'DST']), 1)),
            ],
        );

        self::assertSame(['preferred duplicate', 'unique'], $set->toArray());
    }

    public function test_it_discards_duplicates_while_preserving_payload_order_and_json_output(): void
    {
        $preferred = ['id' => 'preferred'];
        $jsonSerializable = $this->createJsonSerializablePayload();
        $arrayConvertible = $this->createArrayConvertiblePayload();

        $strategy = new CostHopsSignatureOrderingStrategy(18);
        $set = PathResultSet::fromEntries(
            $strategy,
            [
                new PathResultSetEntry(['id' => 'discarded'], new PathOrderKey(new PathCost('0.400000000000000000'), 3, new RouteSignature(['SRC', 'A', 'DST']), 3)),
                new PathResultSetEntry($preferred, new PathOrderKey(new PathCost('0.100000000000000000'), 1, new RouteSignature(['SRC', 'A', 'DST']), 0)),
                new PathResultSetEntry($jsonSerializable, new PathOrderKey(new PathCost('0.150000000000000000'), 2, new RouteSignature(['SRC', 'B', 'DST']), 1)),
                new PathResultSetEntry($arrayConvertible, new PathOrderKey(new PathCost('0.200000000000000000'), 2, new RouteSignature(['SRC', 'C', 'DST']), 2)),
            ],
        );

        $expectedPayloads = [$preferred, $jsonSerializable, $arrayConvertible];

        self::assertSame($expectedPayloads, $set->toArray());
        self::assertSame(
            [
                $preferred,
                ['type' => 'json'],
                ['type' => 'array'],
            ],
            $set->jsonSerialize(),
        );
    }

    public function test_json_serialization_delegates_to_entries(): void
    {
        $serializable = $this->createJsonSerializablePayload();
        $arrayConvertible = $this->createArrayConvertiblePayload();

        $strategy = new CostHopsSignatureOrderingStrategy(18);
        $set = PathResultSet::fromEntries(
            $strategy,
            [
                new PathResultSetEntry($serializable, new PathOrderKey(new PathCost('0.100000000000000000'), 1, new RouteSignature(['SRC', 'A', 'DST']), 0)),
                new PathResultSetEntry($arrayConvertible, new PathOrderKey(new PathCost('0.200000000000000000'), 1, new RouteSignature(['SRC', 'B', 'DST']), 1)),
                new PathResultSetEntry(['type' => 'scalar'], new PathOrderKey(new PathCost('0.300000000000000000'), 1, new RouteSignature(['SRC', 'C', 'DST']), 2)),
            ],
        );

        self::assertSame(
            [
                ['type' => 'json'],
                ['type' => 'array'],
                ['type' => 'scalar'],
            ],
            $set->jsonSerialize(),
        );
    }

    public function test_slice_returns_subset_preserving_order(): void
    {
        $strategy = new CostHopsSignatureOrderingStrategy(18);
        $set = PathResultSet::fromEntries(
            $strategy,
            [
                new PathResultSetEntry('first', new PathOrderKey(new PathCost('0.100000000000000000'), 1, new RouteSignature(['SRC', 'A', 'DST']), 0)),
                new PathResultSetEntry('second', new PathOrderKey(new PathCost('0.200000000000000000'), 2, new RouteSignature(['SRC', 'B', 'DST']), 1)),
                new PathResultSetEntry('third', new PathOrderKey(new PathCost('0.300000000000000000'), 3, new RouteSignature(['SRC', 'C', 'DST']), 2)),
            ],
        );

        self::assertSame(['first', 'second'], $set->slice(0, 2)->toArray());
        self::assertSame(['second', 'third'], $set->slice(1)->toArray());
    }

    public function test_slice_returns_empty_set_for_out_of_bounds_offsets(): void
    {
        $strategy = new CostHopsSignatureOrderingStrategy(18);
        $set = PathResultSet::fromEntries(
            $strategy,
            [
                new PathResultSetEntry('only', new PathOrderKey(new PathCost('0.100000000000000000'), 1, new RouteSignature(['SRC', 'A', 'DST']), 0)),
            ],
        );

        $slice = $set->slice(5);

        self::assertTrue($slice->isEmpty());
        self::assertSame([], $slice->toArray());
    }

    private function createJsonSerializablePayload(): JsonSerializable
    {
        return new class implements JsonSerializable {
            public function jsonSerialize(): array
            {
                return ['type' => 'json'];
            }
        };
    }

    private function createArrayConvertiblePayload(): object
    {
        return new class {
            /**
             * @return array{type: string}
             */
            public function toArray(): array
            {
                return ['type' => 'array'];
            }
        };
    }
}
