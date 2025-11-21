<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

use function str_repeat;

final class MoneyTest extends TestCase
{
    use MoneyAssertions;

    public function test_normalization_rounds_half_up(): void
    {
        $money = Money::fromString('usd', '1.23456', 4);

        self::assertSame('USD', $money->currency());
        self::assertMoneyAmount($money, '1.2346', 4);
    }

    public function test_decimal_accessor_reflects_amount(): void
    {
        $money = Money::fromString('usd', '42.4200', 4);

        self::assertMoneyAmount($money, '42.4200', 4);
    }

    public function test_from_string_rejects_empty_currency(): void
    {
        $this->expectException(InvalidInput::class);

        Money::fromString('', '1.00');
    }

    /**
     * @dataProvider provideMalformedCurrencies
     */
    public function test_from_string_rejects_malformed_currency(string $currency): void
    {
        $this->expectException(InvalidInput::class);

        Money::fromString($currency, '1.00');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideMalformedCurrencies(): iterable
    {
        yield 'too short' => ['US'];
        yield 'contains digits' => ['U5D'];
        yield 'contains symbols' => ['U$D'];
        yield 'contains whitespace' => ['U D'];
        yield 'excessively long' => [str_repeat('A', 13)];
    }

    /**
     * @dataProvider provideValidCurrencies
     */
    public function test_from_string_accepts_valid_currency(string $currency): void
    {
        $money = Money::fromString($currency, '5.00', 2);

        self::assertSame(strtoupper($currency), $money->currency());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideValidCurrencies(): iterable
    {
        yield 'lowercase' => ['usd'];
        yield 'uppercase' => ['JPY'];
        yield 'mixed case' => ['eUr'];
        yield 'extended length' => ['asset'];
        yield 'upper bound length' => [str_repeat('Z', 12)];
    }

    public function test_add_and_subtract_respect_scale(): void
    {
        $a = Money::fromString('EUR', '10.5', 2);
        $b = Money::fromString('EUR', '2.345', 3);

        $sum = $a->add($b);
        self::assertMoneyAmount($sum, '12.845', 3);

        $difference = $sum->subtract($b);
        self::assertTrue($difference->equals($a->withScale(3)));
    }

    public function test_multiply_rounds_result(): void
    {
        $money = Money::fromString('GBP', '12.00', 2);

        $result = $money->multiply('1.157', 2);

        self::assertMoneyAmount($result, '13.88', 2);
    }

    public function test_divide_rounds_result_and_honours_custom_scale(): void
    {
        $money = Money::fromString('USD', '100.00', 2);

        $result = $money->divide('3', 4);

        self::assertMoneyAmount($result, '33.3333', 4);
        self::assertSame(0, $result->compare(Money::fromString('USD', '33.3333', 4), 4));
    }

    /**
     * @dataProvider provideInvalidDivisors
     */
    public function test_divide_rejects_invalid_divisors(string $divisor): void
    {
        $money = Money::fromString('USD', '10.00', 2);

        $this->expectException(InvalidInput::class);

        $money->divide($divisor);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidDivisors(): iterable
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
        $this->expectException(InvalidInput::class);

        Money::fromString('USD', '1.00')->add(Money::fromString('EUR', '1.00'));
    }
}
