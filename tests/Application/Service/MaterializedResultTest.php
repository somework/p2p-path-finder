<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Service;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathCost;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathOrderKey;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\RouteSignature;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\PathResultSetEntry;
use SomeWork\P2PPathFinder\Application\Result\PathResult;
use SomeWork\P2PPathFinder\Application\Service\MaterializedResult;
use SomeWork\P2PPathFinder\Domain\ValueObject\DecimalTolerance;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Tests\Application\Support\DecimalFactory;

final class MaterializedResultTest extends TestCase
{
    public function test_it_exposes_path_result_and_order_key(): void
    {
        $result = new PathResult(
            Money::fromString('USD', '100.00', 2),
            Money::fromString('EUR', '90.00', 2),
            DecimalTolerance::fromNumericString('0.050000000000000000'),
        );

        $orderKey = new PathOrderKey(
            new PathCost(DecimalFactory::decimal('1.111111111111111111')),
            1,
            RouteSignature::fromNodes(['USD', 'EUR']),
            0,
        );

        $materialized = new MaterializedResult($result, $orderKey);

        self::assertSame($result, $materialized->result());
        self::assertSame($orderKey, $materialized->orderKey());

        $entry = $materialized->toEntry();

        self::assertInstanceOf(PathResultSetEntry::class, $entry);
        self::assertSame($result, $entry->path());
        self::assertSame($orderKey, $entry->orderKey());
        self::assertSame($result->jsonSerialize(), $materialized->jsonSerialize());
    }
}
