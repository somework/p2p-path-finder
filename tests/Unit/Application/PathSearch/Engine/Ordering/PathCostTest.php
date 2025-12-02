<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Engine\Ordering;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathCost;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

final class PathCostTest extends TestCase
{
    public function test_it_normalizes_costs_to_default_scale(): void
    {
        $cost = new PathCost('1.25');

        self::assertSame('1.250000000000000000', $cost->value());
    }

    public function test_it_accepts_bigdecimal_instances(): void
    {
        $decimal = BigDecimal::of('0.5');

        $cost = new PathCost($decimal);

        self::assertSame('0.500000000000000000', $cost->value());
        self::assertSame(0, $cost->decimal()->compareTo($decimal->toScale(18, RoundingMode::HALF_UP)));
    }

    public function test_it_compares_with_custom_scale(): void
    {
        $left = new PathCost('0.333333333333333333');
        $right = new PathCost('0.333333333333333334');

        self::assertSame(0, $left->compare($right, 15));
        self::assertSame(-1, $left->compare($right));
    }

    public function test_compare_rejects_negative_scale(): void
    {
        $left = new PathCost('0.1');
        $right = new PathCost('0.2');

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Scale must be a non-negative integer.');

        $left->compare($right, -1);
    }

    public function test_equals_uses_normalized_value(): void
    {
        $baseline = new PathCost('2');
        $withTrailing = new PathCost('2.000000000000000000');

        self::assertTrue($baseline->equals($withTrailing));
    }
}
