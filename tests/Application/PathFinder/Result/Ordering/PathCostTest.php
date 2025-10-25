<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder\Result\Ordering;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathCost;

final class PathCostTest extends TestCase
{
    public function test_it_normalizes_costs_to_default_scale(): void
    {
        $cost = new PathCost('1.25');

        self::assertSame('1.250000000000000000', $cost->value());
    }

    public function test_it_compares_using_bc_math_with_custom_scale(): void
    {
        $left = new PathCost('0.333333333333333333');
        $right = new PathCost('0.333333333333333334');

        self::assertSame(0, $left->compare($right, 15));
        self::assertSame(-1, $left->compare($right));
    }

    public function test_equals_uses_normalized_value(): void
    {
        $baseline = new PathCost('2');
        $withTrailing = new PathCost('2.000000000000000000');

        self::assertTrue($baseline->equals($withTrailing));
    }
}
