<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Support\DecimalMath;

final class ExchangeRateTest extends TestCase
{
    use MoneyAssertions;

    public function test_conversion_uses_base_currency(): void
    {
        $rate = ExchangeRate::fromString('USD', 'EUR', '0.923456', 6);
        $money = Money::fromString('USD', '100.00', 2);

        $converted = $rate->convert($money, 4);

        self::assertSame('EUR', $converted->currency());
        self::assertMoneyAmount($converted, '92.3456', 4);
    }

    public function test_decimal_accessor_matches_rate(): void
    {
        $rate = ExchangeRate::fromString('USD', 'JPY', '151.235', 3);

        self::assertSame(0, DecimalMath::decimal('151.235', 3)->compareTo($rate->decimal()));
    }

    public function test_convert_rejects_currency_mismatch(): void
    {
        $rate = ExchangeRate::fromString('USD', 'EUR', '1.1000', 4);

        $this->expectException(InvalidInput::class);
        $rate->convert(Money::fromString('GBP', '5.00'));
    }

    public function test_invert_produces_reciprocal_rate(): void
    {
        $rate = ExchangeRate::fromString('USD', 'JPY', '151.235', 3);

        $inverted = $rate->invert();

        self::assertSame('JPY', $inverted->baseCurrency());
        self::assertSame('USD', $inverted->quoteCurrency());
        self::assertSame('0.007', $inverted->rate());
        self::assertSame(0, DecimalMath::decimal('0.007', 3)->compareTo($inverted->decimal()));
    }

    public function test_from_string_rejects_identical_currencies(): void
    {
        $this->expectException(InvalidInput::class);

        ExchangeRate::fromString('USD', 'USD', '1.0000', 4);
    }

    /**
     * @param non-empty-string $rate
     *
     * @dataProvider invalidRateProvider
     */
    public function test_from_string_rejects_non_positive_rates(string $rate): void
    {
        $this->expectException(InvalidInput::class);

        ExchangeRate::fromString('USD', 'EUR', $rate, 4);
    }

    public function test_from_string_rejects_invalid_base_currency(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Invalid currency "US$" supplied.');

        ExchangeRate::fromString('US$', 'EUR', '1.0000', 4);
    }

    public function test_from_string_rejects_invalid_quote_currency(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Invalid currency "EU?" supplied.');

        ExchangeRate::fromString('USD', 'EU?', '1.0000', 4);
    }

    public function test_from_string_normalizes_currency_symbols(): void
    {
        $rate = ExchangeRate::fromString('usd', 'eur', '1.2345', 4);

        self::assertSame('USD', $rate->baseCurrency());
        self::assertSame('EUR', $rate->quoteCurrency());
    }

    /**
     * @return iterable<array{string}>
     */
    public static function invalidRateProvider(): iterable
    {
        yield 'zero rate' => ['0'];
        yield 'negative rate' => ['-1.25'];
    }

    // ==================== Extreme Rate Tests ====================

    /**
     * @test
     */
    public function testVerySmallExchangeRate(): void
    {
        // Test extremely small rate (e.g., 1 BTC = 0.00000001 satoshi unit)
        $verySmallRate = ExchangeRate::fromString('BTC', 'SATS', '0.00000001', 8);

        $this->assertSame('BTC', $verySmallRate->baseCurrency());
        $this->assertSame('SATS', $verySmallRate->quoteCurrency());
        $this->assertSame('0.00000001', $verySmallRate->rate());
        $this->assertSame(8, $verySmallRate->scale());

        // Test conversion with very small rate
        $money = Money::fromString('BTC', '1.00000000', 8);
        $converted = $verySmallRate->convert($money);

        $this->assertSame('SATS', $converted->currency());
        $this->assertSame('0.00000001', $converted->amount());
        $this->assertSame(8, $converted->scale());

        // Test with larger amount
        $largeMoney = Money::fromString('BTC', '1000000.00000000', 8);
        $largeConverted = $verySmallRate->convert($largeMoney);

        $this->assertSame('0.01000000', $largeConverted->amount());

        // Test inversion of very small rate produces very large rate
        $inverted = $verySmallRate->invert();

        $this->assertSame('SATS', $inverted->baseCurrency());
        $this->assertSame('BTC', $inverted->quoteCurrency());
        $this->assertSame('100000000.00000000', $inverted->rate());

        // Test at extreme precision (scale 20)
        $extremelySmall = ExchangeRate::fromString('ASSETA', 'ASSETB', '0.00000000000000000001', 20);
        $this->assertSame('0.00000000000000000001', $extremelySmall->rate());
        $this->assertSame(20, $extremelySmall->scale());

        // Test conversion maintains precision
        $preciseAmount = Money::fromString('ASSETA', '1000000000000000000.00000000000000000000', 20);
        $preciseConverted = $extremelySmall->convert($preciseAmount);
        $this->assertSame('0.01000000000000000000', $preciseConverted->amount());

        // Test very small rate comparison
        $smallRate1 = ExchangeRate::fromString('USD', 'TINY', '0.00000001', 8);
        $smallRate2 = ExchangeRate::fromString('USD', 'TINY', '0.00000002', 8);

        $money1 = Money::fromString('USD', '100.00', 2);
        $result1 = $smallRate1->convert($money1, 8);
        $result2 = $smallRate2->convert($money1, 8);

        $this->assertSame('0.00000100', $result1->amount());
        $this->assertSame('0.00000200', $result2->amount());
        $this->assertTrue($result2->greaterThan($result1));
    }

    /**
     * @test
     */
    public function testVeryLargeExchangeRate(): void
    {
        // Test very large rate (e.g., USD to hyperinflated currency)
        $veryLargeRate = ExchangeRate::fromString('USD', 'HYP', '100000000.0', 1);

        $this->assertSame('USD', $veryLargeRate->baseCurrency());
        $this->assertSame('HYP', $veryLargeRate->quoteCurrency());
        $this->assertSame('100000000.0', $veryLargeRate->rate());
        $this->assertSame(1, $veryLargeRate->scale());

        // Test conversion with very large rate
        $money = Money::fromString('USD', '1.00', 2);
        $converted = $veryLargeRate->convert($money);

        $this->assertSame('HYP', $converted->currency());
        $this->assertSame('100000000.00', $converted->amount());
        $this->assertSame(2, $converted->scale());

        // Test with larger amount
        $largeMoney = Money::fromString('USD', '100.00', 2);
        $largeConverted = $veryLargeRate->convert($largeMoney);

        $this->assertSame('10000000000.00', $largeConverted->amount());

        // Test inversion of very large rate produces very small rate
        $inverted = $veryLargeRate->invert();

        $this->assertSame('HYP', $inverted->baseCurrency());
        $this->assertSame('USD', $inverted->quoteCurrency());
        // 1/100000000.0 = 0.00000001 at scale 1 rounds to 0.0
        $this->assertSame('0.0', $inverted->rate());
        $this->assertSame(1, $inverted->scale());

        // Test with higher scale to maintain precision on inversion
        $preciseLarge = ExchangeRate::fromString('USD', 'LARGE', '100000000.00000000', 8);
        $preciseInverted = $preciseLarge->invert();
        $this->assertSame('0.00000001', $preciseInverted->rate());

        // Test extremely large rate at higher precision
        $extremelyLarge = ExchangeRate::fromString('TINY', 'HUGE', '99999999999999999999.0', 1);
        $this->assertSame('99999999999999999999.0', $extremelyLarge->rate());

        $tinyMoney = Money::fromString('TINY', '0.1', 1);
        $hugeResult = $extremelyLarge->convert($tinyMoney);
        $this->assertSame('9999999999999999999.9', $hugeResult->amount());

        // Test large rate comparison
        $largeRate1 = ExchangeRate::fromString('USD', 'BIGA', '100000000.00', 2);
        $largeRate2 = ExchangeRate::fromString('USD', 'BIGA', '200000000.00', 2);

        $testMoney = Money::fromString('USD', '1.00', 2);
        $bigResult1 = $largeRate1->convert($testMoney);
        $bigResult2 = $largeRate2->convert($testMoney);

        $this->assertSame('100000000.00', $bigResult1->amount());
        $this->assertSame('200000000.00', $bigResult2->amount());
        $this->assertTrue($bigResult2->greaterThan($bigResult1));
    }

    /**
     * @test
     */
    public function testConversionWithExtremeRates(): void
    {
        // Test round-trip conversion with very small rate
        $smallRate = ExchangeRate::fromString('BTC', 'MICRO', '0.00000001', 8);
        $smallInverse = $smallRate->invert();

        $originalAmount = Money::fromString('BTC', '1.00000000', 8);
        $converted = $smallRate->convert($originalAmount);
        $roundTrip = $smallInverse->convert($converted);

        // Due to rounding, we might not get exactly back, but should be very close
        $this->assertSame('BTC', $roundTrip->currency());
        $this->assertSame('1.00000000', $roundTrip->amount());

        // Test round-trip conversion with very large rate
        $largeRate = ExchangeRate::fromString('USD', 'MEGA', '10000000.00000000', 8);
        $largeInverse = $largeRate->invert();

        $usdAmount = Money::fromString('USD', '100.00000000', 8);
        $megaConverted = $largeRate->convert($usdAmount);
        $usdRoundTrip = $largeInverse->convert($megaConverted);

        $this->assertSame('USD', $usdRoundTrip->currency());
        $this->assertSame('100.00000000', $usdRoundTrip->amount());

        // Test chained conversions with extreme rates
        $rate1 = ExchangeRate::fromString('ASSETA', 'ASSETB', '0.000001', 6);
        $rate2 = ExchangeRate::fromString('ASSETB', 'ASSETC', '1000000.0', 1);

        $startAmount = Money::fromString('ASSETA', '1.000000', 6);
        $toB = $rate1->convert($startAmount);
        $toC = $rate2->convert($toB, 6);

        // 1 ASSETA * 0.000001 = 0.000001 ASSETB
        $this->assertSame('0.000001', $toB->amount());
        // 0.000001 ASSETB * 1000000.0 = 1.0 ASSETC
        $this->assertSame('1.000000', $toC->amount());
        $this->assertSame('ASSETC', $toC->currency());

        // Test precision preservation with extreme scale
        $highPrecisionRate = ExchangeRate::fromString('PRECA', 'PRECB', '0.123456789012345', 15);
        $highPrecMoney = Money::fromString('PRECA', '1000.000000000000000', 15);
        $highPrecConverted = $highPrecisionRate->convert($highPrecMoney);

        $this->assertSame('123.456789012345000', $highPrecConverted->amount());
        $this->assertSame(15, $highPrecConverted->scale());

        // Test explicit scale override with extreme rates
        $extremeRate = ExchangeRate::fromString('XXX', 'YYY', '0.00000001', 8);
        $xMoney = Money::fromString('XXX', '1000000.00', 2);
        
        // Convert with lower scale
        $yLowScale = $extremeRate->convert($xMoney, 2);
        $this->assertSame('0.01', $yLowScale->amount());
        $this->assertSame(2, $yLowScale->scale());

        // Convert with higher scale
        $yHighScale = $extremeRate->convert($xMoney, 12);
        $this->assertSame('0.010000000000', $yHighScale->amount());
        $this->assertSame(12, $yHighScale->scale());

        // Test multiple small conversions accumulate correctly
        $accumulateRate = ExchangeRate::fromString('BASE', 'TARGET', '0.00001', 5);
        
        $amount1 = Money::fromString('BASE', '10000.00000', 5);
        $amount2 = Money::fromString('BASE', '20000.00000', 5);
        $amount3 = Money::fromString('BASE', '30000.00000', 5);

        $conv1 = $accumulateRate->convert($amount1);
        $conv2 = $accumulateRate->convert($amount2);
        $conv3 = $accumulateRate->convert($amount3);

        $total = $conv1->add($conv2)->add($conv3);

        $this->assertSame('0.60000', $total->amount());
        $this->assertSame(5, $total->scale());

        // Verify conversion of zero with extreme rate
        $zero = Money::fromString('EUR', '0.00', 2);
        $extremeRateForZero = ExchangeRate::fromString('EUR', 'XXX', '999999999.99', 2);
        $convertedZero = $extremeRateForZero->convert($zero);

        $this->assertTrue($convertedZero->isZero());
        $this->assertSame('0.00', $convertedZero->amount());

        // Test that extreme rates maintain consistency
        $rate = ExchangeRate::fromString('AAA', 'BBB', '0.00000000000001', 14);
        $money1 = Money::fromString('AAA', '1.00000000000000', 14);
        $money2 = Money::fromString('AAA', '2.00000000000000', 14);

        $result1 = $rate->convert($money1);
        $result2 = $rate->convert($money2);

        // result2 should be exactly double result1
        $doubled = $result1->multiply('2', 14);
        $this->assertTrue($doubled->equals($result2));
    }
}
