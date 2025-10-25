<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder\Result\Ordering;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\RouteSignature;

final class RouteSignatureTest extends TestCase
{
    public function test_it_trims_and_joins_nodes(): void
    {
        $signature = new RouteSignature(['  SRC ', 'MID', ' DST  ']);

        self::assertSame(['SRC', 'MID', 'DST'], $signature->nodes());
        self::assertSame('SRC->MID->DST', $signature->value());
    }

    public function test_it_handles_empty_sequences(): void
    {
        $signature = new RouteSignature([]);

        self::assertSame([], $signature->nodes());
        self::assertSame('', $signature->value());
    }

    public function test_equals_and_compare_use_normalized_value(): void
    {
        $alpha = new RouteSignature(['SRC', 'DST']);
        $beta = new RouteSignature(['SRC', 'dst']);
        $gamma = new RouteSignature(['SRC', 'BET']);

        self::assertTrue($alpha->equals(new RouteSignature(['SRC', 'DST'])));
        self::assertSame(0, $alpha->compare(new RouteSignature(['SRC', 'DST'])));
        self::assertSame(1, $alpha->compare($gamma));
        self::assertSame(-1, $gamma->compare($alpha));
        self::assertFalse($alpha->equals($beta));
    }
}
