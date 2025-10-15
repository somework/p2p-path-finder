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

    public function test_divide_rounds_result_and_honours_custom_scale(): void
    {
        $money = Money::fromString('USD', '100.00', 2);

        $result = $money->divide('3', 4);

        self::assertSame('33.3333', $result->amount());
        self::assertSame(4, $result->scale());
        self::assertSame(0, $result->compare(Money::fromString('USD', '33.3333', 4), 4));
    }

    /**
     * @dataProvider provideInvalidDivisors
     */
    public function test_divide_rejects_invalid_divisors(string $divisor): void
    {
        $money = Money::fromString('USD', '10.00', 2);

        $this->expectException(InvalidArgumentException::class);

        $money->divide($divisor);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public function provideInvalidDivisors(): iterable
    {
        yield 'non-numeric' => ['foo'];
        yield 'zero' => ['0'];
    }

    public function test_is_zero_respects_scale(): void
    {
        $zero = Money::fromString('JPY', '0.000', 3);
        $alsoZero = Money::fromString('JPY', '0', 0);
        $nonZero = Money::fromString('JPY', '0.001', 3);

        self::assertTrue($zero->isZero());
        self::assertTrue($alsoZero->isZero());
        self::assertFalse($nonZero->isZero());
    }

    public function test_with_scale_returns_same_instance_when_unchanged(): void
    {
        $money = Money::fromString('AUD', '42.42', 2);

        self::assertSame($money, $money->withScale(2));
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
