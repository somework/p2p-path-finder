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
use SomeWork\P2PPathFinder\Tests\Application\Support\DecimalFactory;

final class PathResultSetTest extends TestCase
{
    public function test_it_orders_entries_using_strategy(): void
    {
        $paths = ['late', 'early', 'middle'];
        $orderKeys = [
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.300000000000000000')), 3, RouteSignature::fromNodes(['SRC', 'C', 'DST']), 2),
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.100000000000000000')), 1, RouteSignature::fromNodes(['SRC', 'A', 'DST']), 0),
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.100000000000000000')), 2, RouteSignature::fromNodes(['SRC', 'B', 'DST']), 1),
        ];

        $set = $this->createResultSet($paths, $orderKeys);

        self::assertSame(['early', 'middle', 'late'], $set->toArray());
        self::assertSame($set->toArray(), iterator_to_array($set));
    }

    public function test_it_discards_duplicate_route_signatures(): void
    {
        $paths = ['worse duplicate', 'preferred duplicate', 'unique'];
        $orderKeys = [
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.300000000000000000')), 3, RouteSignature::fromNodes(['SRC', 'A', 'DST']), 2),
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.100000000000000000')), 1, RouteSignature::fromNodes(['SRC', 'A', 'DST']), 0),
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.200000000000000000')), 2, RouteSignature::fromNodes(['SRC', 'B', 'DST']), 1),
        ];

        $set = $this->createResultSet($paths, $orderKeys);

        self::assertSame(['preferred duplicate', 'unique'], $set->toArray());
    }

    public function test_it_discards_duplicates_while_preserving_payload_order_and_json_output(): void
    {
        $preferred = ['id' => 'preferred'];
        $jsonSerializable = $this->createJsonSerializablePayload();
        $arrayConvertible = $this->createArrayConvertiblePayload();

        $paths = [
            ['id' => 'discarded'],
            $preferred,
            $jsonSerializable,
            $arrayConvertible,
        ];
        $orderKeys = [
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.400000000000000000')), 3, RouteSignature::fromNodes(['SRC', 'A', 'DST']), 3),
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.100000000000000000')), 1, RouteSignature::fromNodes(['SRC', 'A', 'DST']), 0),
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.150000000000000000')), 2, RouteSignature::fromNodes(['SRC', 'B', 'DST']), 1),
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.200000000000000000')), 2, RouteSignature::fromNodes(['SRC', 'C', 'DST']), 2),
        ];

        $set = $this->createResultSet($paths, $orderKeys);

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

        $paths = [$serializable, $arrayConvertible, ['type' => 'scalar']];
        $orderKeys = [
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.100000000000000000')), 1, RouteSignature::fromNodes(['SRC', 'A', 'DST']), 0),
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.200000000000000000')), 1, RouteSignature::fromNodes(['SRC', 'B', 'DST']), 1),
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.300000000000000000')), 1, RouteSignature::fromNodes(['SRC', 'C', 'DST']), 2),
        ];

        $set = $this->createResultSet($paths, $orderKeys);

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
        $paths = ['first', 'second', 'third'];
        $orderKeys = [
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.100000000000000000')), 1, RouteSignature::fromNodes(['SRC', 'A', 'DST']), 0),
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.200000000000000000')), 2, RouteSignature::fromNodes(['SRC', 'B', 'DST']), 1),
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.300000000000000000')), 3, RouteSignature::fromNodes(['SRC', 'C', 'DST']), 2),
        ];

        $set = $this->createResultSet($paths, $orderKeys);

        self::assertSame(['first', 'second'], $set->slice(0, 2)->toArray());
        self::assertSame(['second', 'third'], $set->slice(1)->toArray());
    }

    public function test_slice_returns_empty_set_for_out_of_bounds_offsets(): void
    {
        $paths = ['only'];
        $orderKeys = [
            new PathOrderKey(new PathCost(DecimalFactory::decimal('0.100000000000000000')), 1, RouteSignature::fromNodes(['SRC', 'A', 'DST']), 0),
        ];

        $set = $this->createResultSet($paths, $orderKeys);

        $slice = $set->slice(5);

        self::assertTrue($slice->isEmpty());
        self::assertSame([], $slice->toArray());
    }

    /**
     * @template TPath of mixed
     *
     * @param list<TPath>        $paths
     * @param list<PathOrderKey> $orderKeys
     *
     * @return PathResultSet<TPath>
     */
    private function createResultSet(array $paths, array $orderKeys): PathResultSet
    {
        $strategy = new CostHopsSignatureOrderingStrategy(18);

        return PathResultSet::fromPaths(
            $strategy,
            $paths,
            static fn (mixed $path, int $index): PathOrderKey => $orderKeys[$index],
        );
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
