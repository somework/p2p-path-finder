<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Engine\Ordering;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathCost;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

#[CoversClass(PathCost::class)]
final class PathCostTest extends TestCase
{
    #[TestDox('Construction from numeric string normalizes value to scale 18')]
    public function test_construction_from_string_normalizes_to_scale_18(): void
    {
        $cost = new PathCost('1.5');

        self::assertSame('1.500000000000000000', $cost->value());
    }

    #[TestDox('Construction from integer string normalizes to scale 18')]
    public function test_construction_from_integer_string_normalizes_to_scale_18(): void
    {
        $cost = new PathCost('42');

        self::assertSame('42.000000000000000000', $cost->value());
    }

    #[TestDox('Construction from BigDecimal normalizes to scale 18')]
    public function test_construction_from_bigdecimal(): void
    {
        $decimal = BigDecimal::of('0.5');

        $cost = new PathCost($decimal);

        self::assertSame('0.500000000000000000', $cost->value());
        self::assertSame(0, $cost->decimal()->compareTo($decimal->toScale(18, RoundingMode::HalfUp)));
    }

    #[TestDox('Construction from BigDecimal with higher precision rounds to scale 18')]
    public function test_construction_from_bigdecimal_with_higher_precision(): void
    {
        $decimal = BigDecimal::of('1.1234567890123456789');

        $cost = new PathCost($decimal);

        self::assertSame('1.123456789012345679', $cost->value());
    }

    #[TestDox('value() returns padded numeric string at scale 18')]
    public function test_value_returns_padded_numeric_string(): void
    {
        $cost = new PathCost('1.25');

        self::assertSame('1.250000000000000000', $cost->value());
    }

    #[TestDox('value() preserves trailing zeros for zero value')]
    public function test_value_preserves_trailing_zeros_for_zero(): void
    {
        $cost = new PathCost('0');

        self::assertSame('0.000000000000000000', $cost->value());
    }

    #[TestDox('decimal() returns BigDecimal instance')]
    public function test_decimal_returns_bigdecimal_instance(): void
    {
        $cost = new PathCost('3.14');

        $decimal = $cost->decimal();

        self::assertInstanceOf(BigDecimal::class, $decimal);
        self::assertSame(18, $decimal->getScale());
        self::assertSame('3.140000000000000000', (string) $decimal);
    }

    #[TestDox('equals() returns true for equal costs')]
    public function test_equals_returns_true_for_equal_costs(): void
    {
        $a = new PathCost('2');
        $b = new PathCost('2.000000000000000000');

        self::assertTrue($a->equals($b));
    }

    #[TestDox('equals() returns false for different costs')]
    public function test_equals_returns_false_for_different_costs(): void
    {
        $a = new PathCost('1.5');
        $b = new PathCost('2.5');

        self::assertFalse($a->equals($b));
    }

    #[TestDox('equals() detects difference in lowest decimal place')]
    public function test_equals_detects_difference_in_lowest_decimal_place(): void
    {
        $a = new PathCost('0.000000000000000001');
        $b = new PathCost('0.000000000000000002');

        self::assertFalse($a->equals($b));
    }

    #[TestDox('compare() returns 0 for equal costs')]
    public function test_compare_returns_zero_for_equal_costs(): void
    {
        $a = new PathCost('5.5');
        $b = new PathCost('5.500000000000000000');

        self::assertSame(0, $a->compare($b));
    }

    #[TestDox('compare() returns -1 when this cost is less')]
    public function test_compare_returns_negative_one_for_lesser_cost(): void
    {
        $lesser = new PathCost('1.0');
        $greater = new PathCost('2.0');

        self::assertSame(-1, $lesser->compare($greater));
    }

    #[TestDox('compare() returns 1 when this cost is greater')]
    public function test_compare_returns_positive_one_for_greater_cost(): void
    {
        $greater = new PathCost('3.0');
        $lesser = new PathCost('1.0');

        self::assertSame(1, $greater->compare($lesser));
    }

    #[TestDox('compare() with custom lower scale treats nearly-equal values as equal')]
    public function test_compare_with_custom_lower_scale(): void
    {
        $left = new PathCost('0.333333333333333333');
        $right = new PathCost('0.333333333333333334');

        self::assertSame(0, $left->compare($right, 15));
        self::assertSame(-1, $left->compare($right));
    }

    #[TestDox('compare() with scale 2 rounds both values to 2 decimal places')]
    public function test_compare_with_scale_2(): void
    {
        $a = new PathCost('1.231');
        $b = new PathCost('1.234');

        self::assertSame(0, $a->compare($b, 2));
    }

    #[TestDox('compare() with scale 0 compares integer parts after rounding')]
    public function test_compare_with_scale_zero(): void
    {
        $a = new PathCost('1.4');
        $b = new PathCost('1.3');

        self::assertSame(0, $a->compare($b, 0));
    }

    #[TestDox('compare() rejects negative scale')]
    public function test_compare_rejects_negative_scale(): void
    {
        $left = new PathCost('0.1');
        $right = new PathCost('0.2');

        self::expectException(InvalidInput::class);
        self::expectExceptionMessage('Scale must be a non-negative integer.');

        $left->compare($right, -1);
    }

    #[TestDox('__toString() matches value()')]
    public function test_to_string_matches_value(): void
    {
        $cost = new PathCost('7.123');

        self::assertSame($cost->value(), (string) $cost);
    }

    #[TestDox('__toString() implements Stringable interface')]
    public function test_implements_stringable(): void
    {
        $cost = new PathCost('1.0');

        self::assertInstanceOf(\Stringable::class, $cost);
    }
}
