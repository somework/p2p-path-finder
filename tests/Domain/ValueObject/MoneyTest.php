<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Domain\ValueObject;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

final class MoneyTest extends TestCase
{
    public function test_normalization_rounds_half_up(): void
    {
        $money = Money::fromString('usd', '1.23456', 4);

        self::assertSame('USD', $money->currency());
        self::assertSame('1.2346', $money->amount());
        self::assertSame(4, $money->scale());
    }

    public function test_add_and_subtract_respect_scale(): void
    {
        $a = Money::fromString('EUR', '10.5', 2);
        $b = Money::fromString('EUR', '2.345', 3);

        $sum = $a->add($b);
        self::assertSame('12.845', $sum->amount());
        self::assertSame(3, $sum->scale());

        $difference = $sum->subtract($b);
        self::assertTrue($difference->equals($a->withScale(3)));
    }

    public function test_multiply_rounds_result(): void
    {
        $money = Money::fromString('GBP', '12.00', 2);

        $result = $money->multiply('1.157', 2);

        self::assertSame('13.88', $result->amount());
    }

    public function test_compare_detects_order(): void
    {
        $low = Money::fromString('CHF', '99.999', 3);
        $high = Money::fromString('CHF', '100.001', 3);

        self::assertTrue($low->lessThan($high));
        self::assertTrue($high->greaterThan($low));
        self::assertFalse($low->equals($high));
    }

    public function test_currency_mismatch_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromString('USD', '1.00')->add(Money::fromString('EUR', '1.00'));
    }
}
