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
}
