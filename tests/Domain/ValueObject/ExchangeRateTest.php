<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Domain\ValueObject;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

final class ExchangeRateTest extends TestCase
{
    public function test_conversion_uses_base_currency(): void
    {
        $rate = ExchangeRate::fromString('USD', 'EUR', '0.923456', 6);
        $money = Money::fromString('USD', '100.00', 2);

        $converted = $rate->convert($money, 4);

        self::assertSame('EUR', $converted->currency());
        self::assertSame('92.3456', $converted->amount());
    }

    public function test_convert_rejects_currency_mismatch(): void
    {
        $rate = ExchangeRate::fromString('USD', 'EUR', '1.1000', 4);

        $this->expectException(InvalidArgumentException::class);
        $rate->convert(Money::fromString('GBP', '5.00'));
    }

    public function test_invert_produces_reciprocal_rate(): void
    {
        $rate = ExchangeRate::fromString('USD', 'JPY', '151.235', 3);

        $inverted = $rate->invert();

        self::assertSame('JPY', $inverted->baseCurrency());
        self::assertSame('USD', $inverted->quoteCurrency());
        self::assertSame('0.007', $inverted->rate());
    }

    public function test_from_string_rejects_identical_currencies(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ExchangeRate::fromString('USD', 'USD', '1.0000', 4);
    }

    /**
     * @param non-empty-string $rate
     *
     * @dataProvider invalidRateProvider
     */
    public function test_from_string_rejects_non_positive_rates(string $rate): void
    {
        $this->expectException(InvalidArgumentException::class);

        ExchangeRate::fromString('USD', 'EUR', $rate, 4);
    }

    /**
     * @return iterable<array{string}>
     */
    public function invalidRateProvider(): iterable
    {
        yield 'zero rate' => ['0'];
        yield 'negative rate' => ['-1.25'];
    }
}
