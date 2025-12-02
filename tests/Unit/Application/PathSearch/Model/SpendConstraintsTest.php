<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Model;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\SpendConstraints;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\SpendRange;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

final class SpendConstraintsTest extends TestCase
{
    public function test_from_scalars_normalizes_scale_and_currency(): void
    {
        $constraints = SpendConstraints::fromScalars(
            'usd',
            '1.2345678901234567894',
            '5.6789',
            '3.4567',
        );

        self::assertSame('USD', $constraints->min()->currency());
        self::assertSame(18, $constraints->min()->scale());
        self::assertSame('1.234567890123456789', $constraints->min()->amount());
        self::assertSame('5.678900000000000000', $constraints->max()->amount());
        self::assertSame('3.456700000000000000', $constraints->desired()?->amount());
    }

    public function test_from_scalars_applies_half_up_rounding(): void
    {
        $constraints = SpendConstraints::fromScalars(
            'EUR',
            '1.0000000000000000005',
            '2',
        );

        self::assertSame('1.000000000000000001', $constraints->min()->amount());
        self::assertSame('2.000000000000000000', $constraints->max()->amount());
    }

    public function test_from_scalars_requires_currency(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Currency cannot be empty.');

        SpendConstraints::fromScalars('', '1', '2');
    }

    public function test_from_scalars_rejects_negative_bounds(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Money amount cannot be negative');

        SpendConstraints::fromScalars('USD', '-1', '2');
    }

    public function test_bounds_method_returns_min_max_array(): void
    {
        $constraints = SpendConstraints::fromScalars('USD', '10.00', '20.00', '15.00');

        $bounds = $constraints->bounds();

        self::assertIsArray($bounds);
        self::assertArrayHasKey('min', $bounds);
        self::assertArrayHasKey('max', $bounds);
        self::assertSame('10.000000000000000000', $bounds['min']->amount());
        self::assertSame('20.000000000000000000', $bounds['max']->amount());
        self::assertSame('USD', $bounds['min']->currency());
        self::assertSame('USD', $bounds['max']->currency());
    }

    public function test_spend_range_from_array_constructor(): void
    {
        $min = Money::fromString('EUR', '50.00', 2);
        $max = Money::fromString('EUR', '100.00', 2);

        $range = SpendRange::fromArray(['min' => $min, 'max' => $max]);

        self::assertSame('50.00', $range->min()->amount());
        self::assertSame('100.00', $range->max()->amount());
        self::assertSame('EUR', $range->currency());
    }

    public function test_spend_range_from_array_rejects_missing_keys(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Spend ranges require a "min" key.');

        $max = Money::fromString('USD', '100.00', 2);
        SpendRange::fromArray(['max' => $max]);
    }

    public function test_spend_range_from_array_rejects_invalid_types(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Spend range bounds must be Money instances');

        SpendRange::fromArray(['min' => 'not-money', 'max' => Money::fromString('USD', '100', 0)]);
    }

    public function test_spend_range_from_array_rejects_currency_mismatch(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Spend ranges require matching currencies.');

        $min = Money::fromString('USD', '50.00', 2);
        $max = Money::fromString('EUR', '100.00', 2);

        SpendRange::fromArray(['min' => $min, 'max' => $max]);
    }

    public function test_spend_range_auto_swaps_min_max(): void
    {
        $min = Money::fromString('GBP', '100.00', 2);
        $max = Money::fromString('GBP', '50.00', 2);

        $range = SpendRange::fromBounds($min, $max);

        // Should auto-swap so min < max
        self::assertSame('50.00', $range->min()->amount());
        self::assertSame('100.00', $range->max()->amount());
    }

    public function test_spend_range_normalize_with_method(): void
    {
        $range = SpendRange::fromBounds(
            Money::fromString('BTC', '0.1', 1),
            Money::fromString('BTC', '1.0', 1)
        );

        $higherScaleValue = Money::fromString('BTC', '0.5', 8);
        $normalized = $range->normalizeWith($higherScaleValue);

        // Range should be scaled up to match the higher scale value
        self::assertSame(8, $normalized->scale());
        self::assertSame('0.10000000', $normalized->min()->amount());
        self::assertSame('1.00000000', $normalized->max()->amount());
    }

    public function test_spend_range_to_bounds_array(): void
    {
        $min = Money::fromString('JPY', '1000', 0);
        $max = Money::fromString('JPY', '5000', 0);

        $range = SpendRange::fromBounds($min, $max);
        $bounds = $range->toBoundsArray();

        self::assertIsArray($bounds);
        self::assertArrayHasKey('min', $bounds);
        self::assertArrayHasKey('max', $bounds);
        self::assertSame($min, $bounds['min']);
        self::assertSame($max, $bounds['max']);
    }

    public function test_spend_range_currency_method(): void
    {
        $range = SpendRange::fromBounds(
            Money::fromString('CAD', '10.00', 2),
            Money::fromString('CAD', '20.00', 2)
        );

        self::assertSame('CAD', $range->currency());
    }

    public function test_spend_range_scale_method(): void
    {
        // Test with same scale
        $range = SpendRange::fromBounds(
            Money::fromString('USD', '10.00', 2),
            Money::fromString('USD', '20.00', 2)
        );
        self::assertSame(2, $range->scale());

        // Test with different scales (should return max)
        $range2 = SpendRange::fromBounds(
            Money::fromString('USD', '10.0', 1),
            Money::fromString('USD', '20.000', 3)
        );
        self::assertSame(3, $range2->scale());
    }

    public function test_spend_range_clamp_rejects_currency_mismatch(): void
    {
        $range = SpendRange::fromBounds(
            Money::fromString('USD', '10.00', 2),
            Money::fromString('USD', '20.00', 2)
        );

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Spend range operations require matching currencies.');

        $range->clamp(Money::fromString('EUR', '15.00', 2));
    }

    public function test_spend_range_normalize_with_rejects_currency_mismatch(): void
    {
        $range = SpendRange::fromBounds(
            Money::fromString('USD', '10.00', 2),
            Money::fromString('USD', '20.00', 2)
        );

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Spend range operations require matching currencies.');

        $range->normalizeWith(Money::fromString('EUR', '15.00', 2));
    }

    public function test_spend_constraints_from_method_with_currency_mismatch(): void
    {
        $min = Money::fromString('USD', '10.00', 2);
        $max = Money::fromString('USD', '20.00', 2);
        $desired = Money::fromString('EUR', '15.00', 2);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Desired spend must use the same currency as the spend bounds.');

        SpendConstraints::from($min, $max, $desired);
    }

    public function test_spend_constraints_desired_can_be_null(): void
    {
        $constraints = SpendConstraints::from(
            Money::fromString('USD', '10.00', 2),
            Money::fromString('USD', '20.00', 2),
            null
        );

        self::assertNull($constraints->desired());
    }

    public function test_spend_range_with_scale_extremes(): void
    {
        // Test with scale 0
        $rangeZero = SpendRange::fromBounds(
            Money::fromString('JPY', '1000', 0),
            Money::fromString('JPY', '5000', 0)
        );
        self::assertSame(0, $rangeZero->scale());

        // Test with scale 50 (max)
        $rangeMax = SpendRange::fromBounds(
            Money::fromString('PREC', '1.'.str_repeat('0', 49).'1', 50),
            Money::fromString('PREC', '1.'.str_repeat('0', 49).'2', 50)
        );
        self::assertSame(50, $rangeMax->scale());
    }

    public function test_spend_constraints_with_extreme_scales(): void
    {
        $constraints = SpendConstraints::from(
            Money::fromString('TEST', '1', 0),
            Money::fromString('TEST', '100', 0),
            Money::fromString('TEST', '50', 0)
        );

        self::assertSame(0, $constraints->min()->scale());
        self::assertSame(0, $constraints->max()->scale());
        self::assertSame(0, $constraints->desired()->scale());
    }
}
