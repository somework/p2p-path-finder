<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder\ValueObject;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\SpendRange;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

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

    public function test_from_array_requires_keys(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Spend ranges require a "max" key.');

        SpendRange::fromArray([
            'min' => Money::fromString('USD', '1', 0),
        ]);
    }

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
}
