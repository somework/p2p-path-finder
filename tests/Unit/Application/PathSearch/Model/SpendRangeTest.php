<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\SpendRange;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

#[CoversClass(SpendRange::class)]
final class SpendRangeTest extends TestCase
{
    public function test_from_bounds_normalizes_scale_and_order(): void
    {
        $min = Money::fromString('USD', '5.0', 1);
        $max = Money::fromString('USD', '3.000', 3);

        $range = SpendRange::fromBounds($min, $max);

        self::assertSame('USD', $range->min()->currency());
        self::assertSame('3.000', $range->min()->amount());
        self::assertSame('5.000', $range->max()->amount());
        self::assertSame(3, $range->min()->scale());
        self::assertSame(3, $range->max()->scale());
    }

    public function test_with_scale_raises_scale_to_match_targets(): void
    {
        $range = SpendRange::fromBounds(
            Money::fromString('USD', '1.00', 2),
            Money::fromString('USD', '5.000', 3),
        );

        $rescaled = $range->withScale(5);

        self::assertSame(5, $rescaled->scale());
        self::assertSame('1.00000', $rescaled->min()->amount());
        self::assertSame('5.00000', $rescaled->max()->amount());
    }

    public function test_normalize_with_raises_scale_to_cover_values(): void
    {
        $range = SpendRange::fromBounds(
            Money::fromString('USD', '1.00', 2),
            Money::fromString('USD', '5.00', 2),
        );

        $normalized = $range->normalizeWith(Money::fromString('USD', '3.0000', 4));

        self::assertSame(4, $normalized->scale());
        self::assertSame('1.0000', $normalized->min()->amount());
        self::assertSame('5.0000', $normalized->max()->amount());
    }

    public function test_clamp_limits_values_outside_range(): void
    {
        $range = SpendRange::fromBounds(
            Money::fromString('USD', '1.00', 2),
            Money::fromString('USD', '5.00', 2),
        );

        $below = $range->clamp(Money::fromString('USD', '0.50', 2));
        $above = $range->clamp(Money::fromString('USD', '7.00', 2));
        $inside = $range->clamp(Money::fromString('USD', '3.000', 3));

        self::assertSame('1.00', $below->amount());
        self::assertSame('5.00', $above->amount());
        self::assertSame('3.000', $inside->amount());
    }

    /**
     * @noinspection PhpMissingArrayKeyInspection
     */
    public function test_from_array_requires_keys(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Spend ranges require a "max" key.');

        SpendRange::fromArray([
            'min' => Money::fromString('USD', '1', 0),
        ]);
    }

    /**
     * @noinspection PhpParamsInspection
     */
    public function test_from_array_requires_money_instances(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessageMatches('/Spend range bounds must be Money instances/');

        SpendRange::fromArray([
            'min' => Money::fromString('USD', '1', 0),
            'max' => 'not-money',
        ]);
    }

    public function test_from_array_rejects_currency_mismatch(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Spend ranges require matching currencies.');

        SpendRange::fromArray([
            'min' => Money::fromString('USD', '1', 0),
            'max' => Money::fromString('EUR', '1', 0),
        ]);
    }

    public function test_from_bounds_rejects_currency_mismatch(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Spend ranges require matching currencies.');

        SpendRange::fromBounds(
            Money::fromString('USD', '1', 0),
            Money::fromString('EUR', '1', 0),
        );
    }

    public function test_normalize_with_rejects_mismatched_currency(): void
    {
        $range = SpendRange::fromBounds(
            Money::fromString('USD', '1', 0),
            Money::fromString('USD', '5', 0),
        );

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Spend range operations require matching currencies.');

        $range->normalizeWith(Money::fromString('EUR', '3', 0));
    }

    public function test_getters_return_correct_values(): void
    {
        $min = Money::fromString('USD', '10.50', 2);
        $max = Money::fromString('USD', '25.75', 2);

        $range = SpendRange::fromBounds($min, $max);

        self::assertTrue($range->min()->equals($min));
        self::assertTrue($range->max()->equals($max));
        self::assertSame('USD', $range->currency());
        self::assertSame(2, $range->scale());
    }

    public function test_to_bounds_array_returns_correct_structure(): void
    {
        $min = Money::fromString('EUR', '5.00', 2);
        $max = Money::fromString('EUR', '15.00', 2);

        $range = SpendRange::fromBounds($min, $max);
        $bounds = $range->toBoundsArray();

        self::assertIsArray($bounds);
        self::assertArrayHasKey('min', $bounds);
        self::assertArrayHasKey('max', $bounds);
        self::assertTrue($bounds['min']->equals($min));
        self::assertTrue($bounds['max']->equals($max));
    }

    public function test_from_bounds_handles_equal_min_max(): void
    {
        $value = Money::fromString('USD', '10.00', 2);

        $range = SpendRange::fromBounds($value, $value);

        self::assertTrue($range->min()->equals($value));
        self::assertTrue($range->max()->equals($value));
    }

    public function test_from_array_accepts_extra_keys(): void
    {
        $min = Money::fromString('USD', '1.00', 2);
        $max = Money::fromString('USD', '5.00', 2);

        $range = SpendRange::fromArray([
            'min' => $min,
            'max' => $max,
            'extra' => 'ignored',
            'another' => 123,
        ]);

        self::assertTrue($range->min()->equals($min));
        self::assertTrue($range->max()->equals($max));
    }

    public function test_with_scale_preserves_precision(): void
    {
        $range = SpendRange::fromBounds(
            Money::fromString('USD', '1.00', 2),
            Money::fromString('USD', '5.00', 2),
        );

        $scaled = $range->withScale(4);

        self::assertSame(4, $scaled->scale());
        self::assertSame('1.0000', $scaled->min()->amount());
        self::assertSame('5.0000', $scaled->max()->amount());
    }

    public function test_with_scale_does_not_reduce_precision(): void
    {
        $range = SpendRange::fromBounds(
            Money::fromString('USD', '1.000', 3),
            Money::fromString('USD', '5.000', 3),
        );

        $scaled = $range->withScale(2);

        // Should maintain the higher precision
        self::assertSame(3, $scaled->scale());
        self::assertSame('1.000', $scaled->min()->amount());
        self::assertSame('5.000', $scaled->max()->amount());
    }

    public function test_normalize_with_no_values_returns_same_scale(): void
    {
        $range = SpendRange::fromBounds(
            Money::fromString('USD', '1.00', 2),
            Money::fromString('USD', '5.00', 2),
        );

        $normalized = $range->normalizeWith();

        self::assertSame(2, $normalized->scale());
        self::assertTrue($normalized->min()->equals($range->min()));
        self::assertTrue($normalized->max()->equals($range->max()));
    }

    public function test_clamp_at_exact_boundaries(): void
    {
        $range = SpendRange::fromBounds(
            Money::fromString('USD', '1.00', 2),
            Money::fromString('USD', '5.00', 2),
        );

        $atMin = $range->clamp(Money::fromString('USD', '1.00', 2));
        $atMax = $range->clamp(Money::fromString('USD', '5.00', 2));

        self::assertTrue($atMin->equals($range->min()));
        self::assertTrue($atMax->equals($range->max()));
    }

    public function test_clamp_rejects_mismatched_currency(): void
    {
        $range = SpendRange::fromBounds(
            Money::fromString('USD', '1.00', 2),
            Money::fromString('USD', '5.00', 2),
        );

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Spend range operations require matching currencies.');

        $range->clamp(Money::fromString('EUR', '3.00', 2));
    }

    public function test_instances_are_immutable(): void
    {
        $originalMin = Money::fromString('USD', '1.00', 2);
        $originalMax = Money::fromString('USD', '5.00', 2);

        $range = SpendRange::fromBounds($originalMin, $originalMax);

        // Create a new range with different bounds
        $newRange = SpendRange::fromBounds(
            Money::fromString('USD', '2.00', 2),
            Money::fromString('USD', '8.00', 2),
        );

        // Original range should be unchanged
        self::assertTrue($range->min()->equals($originalMin));
        self::assertTrue($range->max()->equals($originalMax));
        self::assertFalse($range->min()->equals($newRange->min()));
        self::assertFalse($range->max()->equals($newRange->max()));
    }

    public function test_methods_return_new_instances(): void
    {
        $range = SpendRange::fromBounds(
            Money::fromString('USD', '1.00', 2),
            Money::fromString('USD', '5.00', 2),
        );

        $scaled = $range->withScale(3);
        $normalized = $range->normalizeWith(Money::fromString('USD', '3.000', 3));

        // Original should be unchanged
        self::assertSame(2, $range->scale());
        self::assertSame(3, $scaled->scale());
        self::assertSame(3, $normalized->scale());

        // Instances should be different objects
        self::assertNotSame($range, $scaled);
        self::assertNotSame($range, $normalized);
        self::assertNotSame($scaled, $normalized);
    }
}
