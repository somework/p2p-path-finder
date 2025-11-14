<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Math\BrickDecimalMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\MathAdapterFactory;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

use function sprintf;
use function str_repeat;

final class MoneyTest extends TestCase
{
    public function test_normalization_rounds_half_up(): void
    {
        foreach (self::mathAdapters() as $adapterName => $math) {
            $money = Money::fromString('usd', '1.23456', 4, $math);

            self::assertSame('USD', $money->currency(), $adapterName);
            self::assertSame('1.2346', $money->amount(), $adapterName);
            self::assertSame(4, $money->scale(), $adapterName);
        }
    }

    public function test_from_string_rejects_empty_currency(): void
    {
        foreach (self::mathAdapters() as $adapterName => $math) {
            self::assertInvalidInput(static fn () => Money::fromString('', '1.00', 2, $math), $adapterName);
        }
    }

    /**
     * @dataProvider provideMalformedCurrencies
     */
    public function test_from_string_rejects_malformed_currency(string $currency): void
    {
        foreach (self::mathAdapters() as $adapterName => $math) {
            self::assertInvalidInput(static fn () => Money::fromString($currency, '1.00', 2, $math), $adapterName);
        }
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
        foreach (self::mathAdapters() as $adapterName => $math) {
            $money = Money::fromString($currency, '5.00', 2, $math);

            self::assertSame(strtoupper($currency), $money->currency(), $adapterName);
        }
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
        foreach (self::mathAdapters() as $adapterName => $math) {
            $a = Money::fromString('EUR', '10.5', 2, $math);
            $b = Money::fromString('EUR', '2.345', 3, $math);

            $sum = $a->add($b);
            self::assertSame('12.845', $sum->amount(), $adapterName);
            self::assertSame(3, $sum->scale(), $adapterName);

            $difference = $sum->subtract($b);
            self::assertTrue($difference->equals($a->withScale(3)), $adapterName);
        }
    }

    public function test_multiply_rounds_result(): void
    {
        foreach (self::mathAdapters() as $adapterName => $math) {
            $money = Money::fromString('GBP', '12.00', 2, $math);

            $result = $money->multiply('1.157', 2);

            self::assertSame('13.88', $result->amount(), $adapterName);
        }
    }

    public function test_divide_rounds_result_and_honours_custom_scale(): void
    {
        foreach (self::mathAdapters() as $adapterName => $math) {
            $money = Money::fromString('USD', '100.00', 2, $math);

            $result = $money->divide('3', 4);

            self::assertSame('33.3333', $result->amount(), $adapterName);
            self::assertSame(4, $result->scale(), $adapterName);
            self::assertSame(0, $result->compare(Money::fromString('USD', '33.3333', 4, $math), 4), $adapterName);
        }
    }

    /**
     * @dataProvider provideInvalidDivisors
     */
    public function test_divide_rejects_invalid_divisors(string $divisor): void
    {
        foreach (self::mathAdapters() as $adapterName => $math) {
            $money = Money::fromString('USD', '10.00', 2, $math);

            self::assertInvalidInput(static fn () => $money->divide($divisor), $adapterName);
        }
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
        foreach (self::mathAdapters() as $adapterName => $math) {
            $zero = Money::fromString('JPY', '0.000', 3, $math);
            $alsoZero = Money::fromString('JPY', '0', 0, $math);
            $nonZero = Money::fromString('JPY', '0.001', 3, $math);

            self::assertTrue($zero->isZero(), $adapterName);
            self::assertTrue($alsoZero->isZero(), $adapterName);
            self::assertFalse($nonZero->isZero(), $adapterName);
        }
    }

    public function test_with_scale_returns_same_instance_when_unchanged(): void
    {
        foreach (self::mathAdapters() as $adapterName => $math) {
            $money = Money::fromString('AUD', '42.42', 2, $math);

            self::assertSame($money, $money->withScale(2), $adapterName);
        }
    }

    public function test_compare_detects_order(): void
    {
        foreach (self::mathAdapters() as $adapterName => $math) {
            $low = Money::fromString('CHF', '99.999', 3, $math);
            $high = Money::fromString('CHF', '100.001', 3, $math);

            self::assertTrue($low->lessThan($high), $adapterName);
            self::assertTrue($high->greaterThan($low), $adapterName);
            self::assertFalse($low->equals($high), $adapterName);
        }
    }

    public function test_currency_mismatch_throws_exception(): void
    {
        foreach (self::mathAdapters() as $adapterName => $math) {
            $left = Money::fromString('USD', '1.00', 2, $math);
            $right = Money::fromString('EUR', '1.00', 2, $math);

            self::assertInvalidInput(static fn () => $left->add($right), $adapterName);
        }
    }

    /**
     * @return iterable<string, \SomeWork\P2PPathFinder\Domain\Math\DecimalMathInterface>
     */
    private static function mathAdapters(): iterable
    {
        yield 'bc-math' => MathAdapterFactory::default();
        yield 'brick-decimal' => new BrickDecimalMath();
    }

    /**
     * @param callable():void $callback
     */
    private static function assertInvalidInput(callable $callback, string $adapterName): void
    {
        try {
            $callback();
            self::fail(sprintf('[%s] Expected InvalidInput exception to be thrown.', $adapterName));
        } catch (InvalidInput $exception) {
            self::assertInstanceOf(InvalidInput::class, $exception, $adapterName);
        }
    }
}
