<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder\Result;

use JsonSerializable;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\CostHopsSignatureOrderingStrategy;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathOrderKey;
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
                new PathResultSetEntry('late', new PathOrderKey('0.300000000000000000', 3, 'SRC->C->DST', 2)),
                new PathResultSetEntry('early', new PathOrderKey('0.100000000000000000', 1, 'SRC->A->DST', 0)),
                new PathResultSetEntry('middle', new PathOrderKey('0.100000000000000000', 2, 'SRC->B->DST', 1)),
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
                new PathResultSetEntry('worse duplicate', new PathOrderKey('0.300000000000000000', 3, 'SRC->A->DST', 2)),
                new PathResultSetEntry('preferred duplicate', new PathOrderKey('0.100000000000000000', 1, 'SRC->A->DST', 0)),
                new PathResultSetEntry('unique', new PathOrderKey('0.200000000000000000', 2, 'SRC->B->DST', 1)),
            ],
        );

        self::assertSame(['preferred duplicate', 'unique'], $set->toArray());
    }

    public function test_json_serialization_delegates_to_entries(): void
    {
        $serializable = new class implements JsonSerializable {
            public function jsonSerialize(): array
            {
                return ['type' => 'json'];
            }
        };

        $arrayConvertible = new class {
            /**
             * @return array{type: string}
             */
            public function toArray(): array
            {
                return ['type' => 'array'];
            }
        };

        $strategy = new CostHopsSignatureOrderingStrategy(18);
        $set = PathResultSet::fromEntries(
            $strategy,
            [
                new PathResultSetEntry($serializable, new PathOrderKey('0.100000000000000000', 1, 'SRC->A->DST', 0)),
                new PathResultSetEntry($arrayConvertible, new PathOrderKey('0.200000000000000000', 1, 'SRC->B->DST', 1)),
                new PathResultSetEntry(['type' => 'scalar'], new PathOrderKey('0.300000000000000000', 1, 'SRC->C->DST', 2)),
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
}
