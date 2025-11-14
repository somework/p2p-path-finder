<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Math\BrickDecimalMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\MathAdapterFactory;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

use function sprintf;

final class ExchangeRateTest extends TestCase
{
    public function test_conversion_uses_base_currency(): void
    {
        foreach (self::mathAdapters() as $adapterName => $math) {
            $rate = ExchangeRate::fromString('USD', 'EUR', '0.923456', 6, $math);
            $money = Money::fromString('USD', '100.00', 2, $math);

            $converted = $rate->convert($money, 4);

            self::assertSame('EUR', $converted->currency(), $adapterName);
            self::assertSame('92.3456', $converted->amount(), $adapterName);
        }
    }

    public function test_convert_rejects_currency_mismatch(): void
    {
        foreach (self::mathAdapters() as $adapterName => $math) {
            $rate = ExchangeRate::fromString('USD', 'EUR', '1.1000', 4, $math);

            $this->assertInvalidInput(
                static fn () => $rate->convert(Money::fromString('GBP', '5.00', 2, $math)),
                $adapterName,
            );
        }
    }

    public function test_invert_produces_reciprocal_rate(): void
    {
        foreach (self::mathAdapters() as $adapterName => $math) {
            $rate = ExchangeRate::fromString('USD', 'JPY', '151.235', 3, $math);

            $inverted = $rate->invert();

            self::assertSame('JPY', $inverted->baseCurrency(), $adapterName);
            self::assertSame('USD', $inverted->quoteCurrency(), $adapterName);
            self::assertSame('0.007', $inverted->rate(), $adapterName);
        }
    }

    public function test_from_string_rejects_identical_currencies(): void
    {
        foreach (self::mathAdapters() as $adapterName => $math) {
            $this->assertInvalidInput(
                static fn () => ExchangeRate::fromString('USD', 'USD', '1.0000', 4, $math),
                $adapterName,
            );
        }
    }

    /**
     * @param non-empty-string $rate
     *
     * @dataProvider invalidRateProvider
     */
    public function test_from_string_rejects_non_positive_rates(string $rate): void
    {
        foreach (self::mathAdapters() as $adapterName => $math) {
            $this->assertInvalidInput(
                static fn () => ExchangeRate::fromString('USD', 'EUR', $rate, 4, $math),
                $adapterName,
            );
        }
    }

    public function test_from_string_rejects_invalid_base_currency(): void
    {
        foreach (self::mathAdapters() as $adapterName => $math) {
            $this->assertInvalidInput(
                static fn () => ExchangeRate::fromString('US$', 'EUR', '1.0000', 4, $math),
                $adapterName,
            );
        }
    }

    public function test_from_string_rejects_invalid_quote_currency(): void
    {
        foreach (self::mathAdapters() as $adapterName => $math) {
            $this->assertInvalidInput(
                static fn () => ExchangeRate::fromString('USD', 'EU?', '1.0000', 4, $math),
                $adapterName,
            );
        }
    }

    public function test_from_string_normalizes_currency_symbols(): void
    {
        foreach (self::mathAdapters() as $adapterName => $math) {
            $rate = ExchangeRate::fromString('usd', 'eur', '1.2345', 4, $math);

            self::assertSame('USD', $rate->baseCurrency(), $adapterName);
            self::assertSame('EUR', $rate->quoteCurrency(), $adapterName);
        }
    }

    /**
     * @return iterable<array{string}>
     */
    public static function invalidRateProvider(): iterable
    {
        yield 'zero rate' => ['0'];
        yield 'negative rate' => ['-1.25'];
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
    private function assertInvalidInput(callable $callback, string $adapterName): void
    {
        try {
            $callback();
            self::fail(sprintf('[%s] Expected InvalidInput exception to be thrown.', $adapterName));
        } catch (InvalidInput $exception) {
            self::assertInstanceOf(InvalidInput::class, $exception, $adapterName);
        }
    }
}
