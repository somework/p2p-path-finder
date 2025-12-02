<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Domain\Money;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Domain\Money\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Helpers\DecimalMath;
use SomeWork\P2PPathFinder\Tests\Helpers\MoneyAssertions;

#[CoversClass(ExchangeRate::class)]
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

    public function test_very_small_exchange_rate(): void
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

    public function test_very_large_exchange_rate(): void
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

    public function test_conversion_with_extreme_rates(): void
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

    // ==================== Inversion Edge Cases ====================

    public function test_double_inversion(): void
    {
        // Test that rate.invert().invert() ≈ rate (within precision tolerance)
        // Precision loss is expected due to rounding in the inversion process

        // Test with a simple rate at moderate scale
        $rate1 = ExchangeRate::fromString('USD', 'EUR', '1.23456789', 8);
        $inverted1 = $rate1->invert();
        $doubleInverted1 = $inverted1->invert();

        // The double-inverted rate should be very close to original
        // Due to rounding, we expect some precision loss
        $this->assertSame('USD', $doubleInverted1->baseCurrency());
        $this->assertSame('EUR', $doubleInverted1->quoteCurrency());
        $this->assertSame(8, $doubleInverted1->scale());

        // With scale 8, double inversion should preserve most precision
        // Original: 1.23456789
        // Inverted: 1 / 1.23456789 = 0.81000082... → 0.81000082 (rounded at scale+1, then to scale)
        // Double:   1 / 0.81000082 = 1.23456789... → 1.23456789 (rounded)
        // The implementation uses scale+1 for intermediate calculation, preserving precision
        $this->assertSame('1.23456789', $doubleInverted1->rate());

        // Test with higher precision to minimize loss
        $rate2 = ExchangeRate::fromString('BTC', 'ETH', '15.5', 12);
        $doubleInverted2 = $rate2->invert()->invert();

        // Higher scale should give better precision
        $this->assertSame('BTC', $doubleInverted2->baseCurrency());
        $this->assertSame('ETH', $doubleInverted2->quoteCurrency());
        // Should be very close to 15.5
        $this->assertSame('15.500000000062', $doubleInverted2->rate());

        // Test with scale 2 (lower precision, more rounding)
        $rate3 = ExchangeRate::fromString('GBP', 'CHF', '1.33', 2);
        $doubleInverted3 = $rate3->invert()->invert();

        $this->assertSame('GBP', $doubleInverted3->baseCurrency());
        $this->assertSame('CHF', $doubleInverted3->quoteCurrency());
        // With low scale, expect more rounding error
        $this->assertSame('1.33', $doubleInverted3->rate());

        // Test with very precise rate at high scale
        $rate4 = ExchangeRate::fromString('ASSETA', 'ASSETB', '2.718281828459', 12);
        $doubleInverted4 = $rate4->invert()->invert();

        // Document precision tolerance: at scale 12, expect ~1e-11 error
        // Original: 2.718281828459
        // Should be within reasonable tolerance
        $original = $rate4->decimal();
        $recovered = $doubleInverted4->decimal();
        $difference = $original->minus($recovered)->abs();

        // Tolerance: difference should be very small (< 0.000001)
        $this->assertTrue($difference->isLessThan('0.000001'));

        // Test with rate close to 1
        $rate5 = ExchangeRate::fromString('EUR', 'USD', '1.05', 8);
        $doubleInverted5 = $rate5->invert()->invert();

        $this->assertSame('1.05000000', $doubleInverted5->rate());
    }

    public function test_identity_rate_inversion(): void
    {
        // Test that a rate of 1.0 inverts to itself (within precision)
        $identityRate = ExchangeRate::fromString('USD', 'USDT', '1.0', 8);

        $inverted = $identityRate->invert();

        $this->assertSame('USDT', $inverted->baseCurrency());
        $this->assertSame('USD', $inverted->quoteCurrency());
        $this->assertSame('1.00000000', $inverted->rate());
        $this->assertSame(8, $inverted->scale());

        // Double inversion should also be 1.0
        $doubleInverted = $inverted->invert();
        $this->assertSame('USD', $doubleInverted->baseCurrency());
        $this->assertSame('USDT', $doubleInverted->quoteCurrency());
        $this->assertSame('1.00000000', $doubleInverted->rate());

        // Test with different scales
        $identity2 = ExchangeRate::fromString('EUR', 'EURX', '1', 2);
        $inverted2 = $identity2->invert();
        $this->assertSame('1.00', $inverted2->rate());

        $identity3 = ExchangeRate::fromString('GBP', 'GBPX', '1.000000', 6);
        $inverted3 = $identity3->invert();
        $this->assertSame('1.000000', $inverted3->rate());

        // Test conversion with identity rate
        $money = Money::fromString('USD', '100.00', 2);
        $converted = $identityRate->convert($money);
        $this->assertSame('100.00000000', $converted->amount());
        $this->assertSame('USDT', $converted->currency());

        // Convert back with inverted identity rate
        $convertedBack = $inverted->convert($converted, 2);
        $this->assertSame('100.00', $convertedBack->amount());
        $this->assertSame('USD', $convertedBack->currency());
    }

    public function test_near_zero_rate_inversion(): void
    {
        // Test rates very close to zero and their inversions
        // Note: Rates cannot be zero or negative per validation

        // Test very small rate (close to zero but valid)
        $nearZeroRate = ExchangeRate::fromString('LARGE', 'SMALL', '0.00000001', 8);

        $this->assertSame('0.00000001', $nearZeroRate->rate());

        // Inversion should produce a very large rate
        $inverted = $nearZeroRate->invert();

        $this->assertSame('SMALL', $inverted->baseCurrency());
        $this->assertSame('LARGE', $inverted->quoteCurrency());
        $this->assertSame('100000000.00000000', $inverted->rate());

        // Test conversion with near-zero rate
        $largeMoney = Money::fromString('LARGE', '1000000.00000000', 8);
        $smallConverted = $nearZeroRate->convert($largeMoney);
        $this->assertSame('0.01000000', $smallConverted->amount());

        // Test conversion with inverted (large) rate
        $smallMoney = Money::fromString('SMALL', '1.00000000', 8);
        $largeConverted = $inverted->convert($smallMoney);
        $this->assertSame('100000000.00000000', $largeConverted->amount());

        // Test even smaller rate at higher scale
        $veryNearZero = ExchangeRate::fromString('BIG', 'TINY', '0.0000000001', 10);
        $veryInverted = $veryNearZero->invert();

        $this->assertSame('10000000000.0000000000', $veryInverted->rate());

        // Document precision behavior: at very low scales, inversion may round
        $lowScaleNearZero = ExchangeRate::fromString('XXX', 'YYY', '0.001', 3);
        $lowScaleInverted = $lowScaleNearZero->invert();

        // 1 / 0.001 = 1000
        $this->assertSame('1000.000', $lowScaleInverted->rate());

        // Test that double inversion with near-zero rates has more precision loss
        $doubleInverted = $nearZeroRate->invert()->invert();

        // With very small rates, expect more rounding error
        // Original: 0.00000001
        // Inverted: 100000000.00000000
        // Double:   1 / 100000000.00000000 = 0.00000001
        $this->assertSame('0.00000001', $doubleInverted->rate());

        // Test minimum positive rate (essentially the smallest representable)
        $minRate = ExchangeRate::fromString('MAX', 'MIN', '0.00000000000001', 14);
        $minInverted = $minRate->invert();

        // Should produce a very large number
        $this->assertSame('100000000000000.00000000000000', $minInverted->rate());
    }

    // ==================== Additional Input Validation Edge Cases ====================

    public function test_rejects_empty_rate(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Value "" is not numeric.');

        ExchangeRate::fromString('BTC', 'USD', '', 2);
    }

    public function test_rejects_non_numeric_rates(): void
    {
        $invalidRates = ['abc', 'not-a-number', '1.2.3', 'rate$', 'NaN'];

        foreach ($invalidRates as $invalidRate) {
            try {
                ExchangeRate::fromString('BTC', 'USD', $invalidRate, 2);
                self::fail('Expected InvalidInput for non-numeric rate: '.$invalidRate);
            } catch (InvalidInput $e) {
                self::assertStringContainsString('not numeric', $e->getMessage());
            }
        }
    }

    public function test_rejects_rates_with_invalid_characters(): void
    {
        $invalidRates = ['1.5$', '2@3', '4#5', '6%7', '8&9'];

        foreach ($invalidRates as $invalidRate) {
            try {
                ExchangeRate::fromString('BTC', 'USD', $invalidRate, 2);
                self::fail('Expected InvalidInput for rate with invalid characters: '.$invalidRate);
            } catch (InvalidInput $e) {
                self::assertStringContainsString('not numeric', $e->getMessage());
            }
        }
    }

    public function test_rejects_unicode_characters_in_rates(): void
    {
        $this->expectException(InvalidInput::class);

        ExchangeRate::fromString('BTC', 'USD', '1.5€', 2);
    }

    public function test_rejects_control_characters_in_rates(): void
    {
        $this->expectException(InvalidInput::class);

        ExchangeRate::fromString('BTC', 'USD', '1.5'."\x00", 2);
    }

    public function test_handles_very_long_rate_strings(): void
    {
        // Test with very long but valid rate string
        $longRate = '123456789012345678901234567890.123456789';
        $rate = ExchangeRate::fromString('BTC', 'USD', $longRate, 9);

        self::assertSame('BTC', $rate->baseCurrency());
        self::assertSame('USD', $rate->quoteCurrency());
        // Should be normalized/truncated to scale
        self::assertSame('123456789012345678901234567890.123456789', $rate->rate());
    }

    public function test_rejects_extremely_long_invalid_rate_strings(): void
    {
        $longInvalid = str_repeat('a', 1000);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Value "'.$longInvalid.'" is not numeric.');

        ExchangeRate::fromString('BTC', 'USD', $longInvalid, 2);
    }

    // ==================== Scale and Precision Edge Cases ====================

    public function test_handles_scale_zero(): void
    {
        $rate = ExchangeRate::fromString('JPY', 'KRW', '150', 0);

        self::assertSame(0, $rate->scale());
        self::assertSame('150', $rate->rate());

        $money = Money::fromString('JPY', '1000', 0);
        $converted = $rate->convert($money, 0);

        self::assertSame('KRW', $converted->currency());
        self::assertSame('150000', $converted->amount());
    }

    public function test_normalizes_rates_to_scale(): void
    {
        // Test that rates are normalized to the specified scale
        $rate = ExchangeRate::fromString('BTC', 'USD', '65000.12345678901234567890123', 8);

        self::assertSame(8, $rate->scale());
        self::assertSame('65000.12345679', $rate->rate());

        $rate2 = ExchangeRate::fromString('BTC', 'USD', '65000.12345678901234567890123', 2);
        self::assertSame(2, $rate2->scale());
        self::assertSame('65000.12', $rate2->rate());
    }

    // ==================== Currency Edge Cases ====================

    public function test_accepts_maximum_currency_length(): void
    {
        $longBase = 'VERYLONG';
        $longQuote = 'CRYPTOCOIN';

        $rate = ExchangeRate::fromString($longBase, $longQuote, '1.5', 2);

        self::assertSame($longBase, $rate->baseCurrency());
        self::assertSame($longQuote, $rate->quoteCurrency());
    }

    public function test_rejects_currencies_too_long(): void
    {
        $this->expectException(InvalidInput::class);

        // 13 characters is too long
        ExchangeRate::fromString('THIRTEENCHARS', 'USD', '1.0', 2);
    }

    public function test_rejects_currencies_with_invalid_characters(): void
    {
        $invalidCurrencies = ['BTC!', 'ETH@', 'USD#', 'EUR$', 'JPY%'];

        foreach ($invalidCurrencies as $invalid) {
            try {
                ExchangeRate::fromString($invalid, 'VALID', '1.0', 2);
                self::fail('Expected InvalidInput for currency with invalid characters: '.$invalid);
            } catch (InvalidInput) {
                $this->addToAssertionCount(1);
            }

            try {
                ExchangeRate::fromString('VALID', $invalid, '1.0', 2);
                self::fail('Expected InvalidInput for currency with invalid characters: '.$invalid);
            } catch (InvalidInput) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_normalizes_currency_case_in_conversion(): void
    {
        $rate = ExchangeRate::fromString('btc', 'Usd', '65000.00', 2);

        self::assertSame('BTC', $rate->baseCurrency());
        self::assertSame('USD', $rate->quoteCurrency());

        $money = Money::fromString('BTC', '1.00', 2);
        $converted = $rate->convert($money);

        self::assertSame('USD', $converted->currency());
    }

    // ==================== Rate Boundary Edge Cases ====================

    public function test_accepts_rates_very_close_to_zero(): void
    {
        // Test rate very close to zero (but still positive)
        $tinyRate = ExchangeRate::fromString('MICRO', 'NANO', '0.00000001', 8);

        self::assertSame('0.00000001', $tinyRate->rate());

        $money = Money::fromString('MICRO', '1000000.00', 2);
        $converted = $tinyRate->convert($money, 8);

        self::assertSame('NANO', $converted->currency());
        self::assertSame('0.01000000', $converted->amount());
    }

    public function test_accepts_large_but_reasonable_rates(): void
    {
        // Test large rate (hyperinflation scenario)
        $largeRate = ExchangeRate::fromString('HYPER', 'STABLE', '1000000.00', 2);

        self::assertSame('1000000.00', $largeRate->rate());

        $money = Money::fromString('HYPER', '1.00', 2);
        $converted = $largeRate->convert($money);

        self::assertSame('STABLE', $converted->currency());
        self::assertSame('1000000.00', $converted->amount());
    }

    // ==================== Conversion Edge Cases ====================

    public function test_converts_with_explicit_scale_override(): void
    {
        $rate = ExchangeRate::fromString('BTC', 'USD', '65000.12345678', 8);

        $money = Money::fromString('BTC', '1.00000000', 8);

        // Convert with lower scale
        $lowScale = $rate->convert($money, 2);
        self::assertSame('65000.12', $lowScale->amount());
        self::assertSame(2, $lowScale->scale());

        // Convert with higher scale
        $highScale = $rate->convert($money, 10);
        self::assertSame('65000.1234567800', $highScale->amount());
        self::assertSame(10, $highScale->scale());
    }

    public function test_handles_conversion_of_zero_amount(): void
    {
        $rate = ExchangeRate::fromString('BTC', 'USD', '65000.00', 2);

        $zero = Money::fromString('BTC', '0.00', 2);
        $converted = $rate->convert($zero);

        self::assertTrue($converted->isZero());
        self::assertSame('USD', $converted->currency());
    }

    public function test_preserves_scale_in_zero_conversion(): void
    {
        $rate = ExchangeRate::fromString('BTC', 'USD', '65000.00', 2);

        $zero = Money::fromString('BTC', '0.00000000', 8);
        $converted = $rate->convert($zero, 8);

        self::assertTrue($converted->isZero());
        self::assertSame('USD', $converted->currency());
        self::assertSame(8, $converted->scale());
    }

    // ==================== Inversion Edge Cases ====================

    public function test_inverts_rates_at_different_scales(): void
    {
        $testCases = [
            ['BTC', 'USD', '65000.00', 2],
            ['ETH', 'BTC', '0.04567890', 8],
            ['JPY', 'KRW', '10.0', 1],
        ];

        foreach ($testCases as [$base, $quote, $rateValue, $scale]) {
            $rate = ExchangeRate::fromString($base, $quote, $rateValue, $scale);
            $inverted = $rate->invert();

            self::assertSame($quote, $inverted->baseCurrency());
            self::assertSame($base, $inverted->quoteCurrency());
            self::assertSame($scale, $inverted->scale());
        }
    }

    public function test_inverts_identity_rates(): void
    {
        $identity = ExchangeRate::fromString('USD', 'USDT', '1.00', 2);
        $inverted = $identity->invert();

        self::assertSame('USDT', $inverted->baseCurrency());
        self::assertSame('USD', $inverted->quoteCurrency());
        self::assertSame('1.00', $inverted->rate());
    }

    // ==================== Complex Error Scenarios ====================

    public function test_provides_specific_error_for_zero_rate(): void
    {
        try {
            ExchangeRate::fromString('BTC', 'USD', '0', 2);
            self::fail('Expected InvalidInput for zero rate');
        } catch (InvalidInput $e) {
            self::assertStringContainsString('greater than zero', $e->getMessage());
        }
    }

    public function test_provides_specific_error_for_negative_rate(): void
    {
        try {
            ExchangeRate::fromString('BTC', 'USD', '-1.5', 2);
            self::fail('Expected InvalidInput for negative rate');
        } catch (InvalidInput $e) {
            self::assertStringContainsString('greater than zero', $e->getMessage());
        }
    }

    public function test_provides_specific_error_for_identical_currencies(): void
    {
        try {
            ExchangeRate::fromString('USD', 'usd', '1.5', 2);
            self::fail('Expected InvalidInput for identical currencies');
        } catch (InvalidInput $e) {
            self::assertStringContainsString('distinct currencies', $e->getMessage());
        }
    }

    public function test_provides_specific_error_for_invalid_currencies(): void
    {
        try {
            ExchangeRate::fromString('US$', 'USD', '1.5', 2);
            self::fail('Expected InvalidInput for invalid currency');
        } catch (InvalidInput $e) {
            // The error comes from Money validation, so we just verify an exception is thrown
            $this->addToAssertionCount(1);
        }
    }
}
