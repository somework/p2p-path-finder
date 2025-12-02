<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Domain\Money;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Domain\Money\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Helpers\MoneyAssertions;

/**
 * Comprehensive test suite for BigDecimal scale edge cases and precision boundaries.
 *
 * This test class verifies behavior at scale extremes (0, 18, 50) and validates
 * precision guarantees, rounding consistency, and overflow handling across all
 * value objects that use BigDecimal operations.
 */
#[CoversClass(Money::class)]
final class MoneyBigDecimalScaleEdgeCaseTest extends TestCase
{
    use MoneyAssertions;

    // ==================== Scale Extremes ====================

    public function test_money_at_max_scale_50(): void
    {
        $money = Money::fromString('BTC', '0.00000000000000000000000000000000000000000000000001', 50);

        self::assertSame('BTC', $money->currency());
        self::assertSame(50, $money->scale());
        self::assertSame('0.00000000000000000000000000000000000000000000000001', $money->amount());
    }

    public function test_money_at_scale_0(): void
    {
        $money = Money::fromString('JPY', '1234567890', 0);

        self::assertSame('JPY', $money->currency());
        self::assertSame(0, $money->scale());
        self::assertSame('1234567890', $money->amount());
    }

    public function test_money_at_canonical_scale_18(): void
    {
        $money = Money::fromString('ETH', '1.5', 18);

        self::assertSame('ETH', $money->currency());
        self::assertSame(18, $money->scale());
        self::assertSame('1.500000000000000000', $money->amount());
    }

    public function test_money_rejects_scale_above_max(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Scale cannot exceed 50 decimal places.');

        Money::fromString('USD', '1.00', 51);
    }

    // ==================== Scale Roundtrip ====================

    public function test_money_scale_roundtrip_preserves_value(): void
    {
        $original = Money::fromString('USD', '123.45', 2);

        // Scale up to 18, then back down to 2
        $upscaled = $original->withScale(18);
        self::assertSame('123.450000000000000000', $upscaled->amount());

        $downscaled = $upscaled->withScale(2);
        self::assertSame('123.45', $downscaled->amount());
        self::assertTrue($original->equals($downscaled));
    }

    public function test_money_scale_roundtrip_with_max_scale(): void
    {
        $original = Money::fromString('BTC', '0.00000001', 8);

        // Scale up to max, then back down
        $maxScaled = $original->withScale(50);
        self::assertSame('0.00000001000000000000000000000000000000000000000000', $maxScaled->amount());

        $backToOriginal = $maxScaled->withScale(8);
        self::assertSame('0.00000001', $backToOriginal->amount());
        self::assertTrue($original->equals($backToOriginal));
    }

    public function test_money_scale_roundtrip_with_rounding(): void
    {
        $original = Money::fromString('USD', '1.556', 3);

        // Scale down causes rounding
        $downscaled = $original->withScale(2);
        self::assertSame('1.56', $downscaled->amount());

        // Scale back up preserves rounded value
        $upscaled = $downscaled->withScale(3);
        self::assertSame('1.560', $upscaled->amount());

        // Not equal to original due to rounding
        self::assertFalse($original->equals($upscaled));
    }

    // ==================== Scale Mismatch Operations ====================

    public function test_money_operations_with_scale_mismatch(): void
    {
        $low = Money::fromString('USD', '10.5', 2);
        $high = Money::fromString('USD', '20.333333333333333333', 18);

        $sum = $low->add($high);
        // Result should use higher scale (18)
        self::assertSame(18, $sum->scale());
        self::assertSame('30.833333333333333333', $sum->amount());
    }

    public function test_money_multiply_with_mixed_scales(): void
    {
        $money = Money::fromString('BTC', '0.00000001', 8);

        // Multiply at higher scale
        $result = $money->multiply('1000000', 18);
        self::assertSame(18, $result->scale());
        self::assertSame('0.010000000000000000', $result->amount());
    }

    // ==================== Very Small Values Near Zero ====================

    public function test_very_small_amounts_near_zero(): void
    {
        $tiny = Money::fromString('WEI', '0.000000000000000001', 18);

        self::assertFalse($tiny->isZero());
        self::assertSame('0.000000000000000001', $tiny->amount());
    }

    public function test_operations_with_very_small_amounts(): void
    {
        $tiny1 = Money::fromString('ETH', '0.000000000000000001', 18);
        $tiny2 = Money::fromString('ETH', '0.000000000000000002', 18);

        $sum = $tiny1->add($tiny2);
        self::assertSame('0.000000000000000003', $sum->amount());

        $difference = $tiny2->subtract($tiny1);
        self::assertSame('0.000000000000000001', $difference->amount());
    }

    public function test_very_small_amount_rounds_to_zero(): void
    {
        $tiny = Money::fromString('ETH', '0.000000000000000001', 18);

        // Scale down to 8 causes rounding to zero
        $rounded = $tiny->withScale(8);
        self::assertTrue($rounded->isZero());
        self::assertSame('0.00000000', $rounded->amount());
    }

    // ==================== Very Large Values ====================

    public function test_very_large_amounts_near_max(): void
    {
        $huge = Money::fromString('USD', '999999999999.999999999999999999', 18);

        self::assertSame('USD', $huge->currency());
        self::assertSame('999999999999.999999999999999999', $huge->amount());
    }

    public function test_operations_with_very_large_amounts(): void
    {
        $large1 = Money::fromString('USD', '999999999.99', 2);
        $large2 = Money::fromString('USD', '999999999.99', 2);

        $sum = $large1->add($large2);
        self::assertSame('1999999999.98', $sum->amount());
    }

    public function test_very_large_amount_with_max_scale(): void
    {
        $huge = Money::fromString('HUGE', '999999999999999999.99999999999999999999999999999999', 32);

        self::assertSame(32, $huge->scale());
        self::assertSame('999999999999999999.99999999999999999999999999999999', $huge->amount());
    }

    // ==================== Division with Repeating Decimals ====================

    public function test_division_with_repeating_decimals_at_scale_18(): void
    {
        $money = Money::fromString('USD', '1.000000000000000000', 18);

        $result = $money->divide('3', 18);
        // 1/3 = 0.333... rounded to 18 decimals with HALF_UP
        self::assertSame('0.333333333333333333', $result->amount());
    }

    public function test_division_with_repeating_decimals_at_scale_50(): void
    {
        $money = Money::fromString('USD', '10', 50);

        $result = $money->divide('3', 50);
        // 10/3 = 3.333... at scale 50
        self::assertSame('3.33333333333333333333333333333333333333333333333333', $result->amount());
    }

    public function test_division_produces_accurate_result_at_high_scale(): void
    {
        $money = Money::fromString('BTC', '1', 30);

        $result = $money->divide('7', 30);
        // 1/7 = 0.142857... (repeating pattern)
        self::assertSame('0.142857142857142857142857142857', $result->amount());
    }

    // ==================== Multiplication Near Overflow ====================

    public function test_multiplication_at_high_scale(): void
    {
        $money = Money::fromString('USD', '999999.999999', 6);

        $result = $money->multiply('999999.999999', 12);
        self::assertSame('999999999998.000000000001', $result->amount());
    }

    public function test_multiplication_preserves_precision_at_max_scale(): void
    {
        $money = Money::fromString('TEST', '1.5', 50);

        $result = $money->multiply('2.5', 50);
        self::assertSame('3.75000000000000000000000000000000000000000000000000', $result->amount());
    }

    public function test_multiplication_with_very_small_and_very_large(): void
    {
        $tiny = Money::fromString('BTC', '0.00000001', 8);

        // Multiply by large number
        $result = $tiny->multiply('100000000', 8);
        self::assertSame('1.00000000', $result->amount());
    }

    // ==================== HALF_UP Rounding at Boundaries ====================

    public function test_half_up_rounding_at_positive_half(): void
    {
        $money = Money::fromString('USD', '0.5', 1);

        $rounded = $money->withScale(0);
        self::assertSame('1', $rounded->amount());
    }

    public function test_half_up_rounding_positive_values(): void
    {
        self::assertSame('2', Money::fromString('USD', '1.5', 1)->withScale(0)->amount());
        self::assertSame('3', Money::fromString('USD', '2.5', 1)->withScale(0)->amount());
        self::assertSame('4', Money::fromString('USD', '3.5', 1)->withScale(0)->amount());
    }

    public function test_half_up_rounding_consistency_across_scales(): void
    {
        // Test HALF_UP rounding at different scales
        // Value .5 at last digit should always round up
        $testCases = [
            ['scale' => 0, 'value' => '1.5', 'sourceScale' => 1, 'expected' => '2'],
            ['scale' => 2, 'value' => '1.005', 'sourceScale' => 3, 'expected' => '1.01'],
            ['scale' => 8, 'value' => '1.000000005', 'sourceScale' => 9, 'expected' => '1.00000001'],
            ['scale' => 18, 'value' => '1.0000000000000000005', 'sourceScale' => 19, 'expected' => '1.000000000000000001'],
        ];

        foreach ($testCases as $testCase) {
            $scale = $testCase['scale'];
            $value = $testCase['value'];
            $sourceScale = $testCase['sourceScale'];
            $expected = $testCase['expected'];

            $money = Money::fromString('USD', $value, $sourceScale);
            $rounded = $money->withScale($scale);

            self::assertSame($expected, $rounded->amount(), "Failed at scale $scale");
        }
    }

    // ==================== Exchange Rate Conversion at Scale Extremes ====================

    public function test_exchange_rate_conversion_at_scale_extremes(): void
    {
        // BTC/USD with high precision
        $rate = ExchangeRate::fromString('BTC', 'USD', '65000.00000000', 8);
        $btc = Money::fromString('BTC', '0.00000001', 8);

        $usd = $rate->convert($btc, 8);
        self::assertSame('USD', $usd->currency());
        self::assertSame('0.00065000', $usd->amount());
    }

    public function test_exchange_rate_with_tiny_base_amount(): void
    {
        $rate = ExchangeRate::fromString('WEI', 'ETH', '0.000000000000000001', 18);
        $wei = Money::fromString('WEI', '1000000000000000000', 18);

        $eth = $rate->convert($wei, 18);
        self::assertSame('ETH', $eth->currency());
        self::assertSame('1.000000000000000000', $eth->amount());
    }

    public function test_exchange_rate_with_huge_base_amount(): void
    {
        $rate = ExchangeRate::fromString('USD', 'JPY', '150.00', 2);
        $usd = Money::fromString('USD', '999999999.99', 2);

        $jpy = $rate->convert($usd, 2);
        self::assertSame('JPY', $jpy->currency());
        self::assertSame('149999999998.50', $jpy->amount());
    }

    public function test_exchange_rate_precision_at_scale_18(): void
    {
        $rate = ExchangeRate::fromString('ETH', 'BTC', '0.045678901234567890', 18);
        $eth = Money::fromString('ETH', '1.000000000000000000', 18);

        $btc = $rate->convert($eth, 18);
        self::assertSame('BTC', $btc->currency());
        self::assertSame('0.045678901234567890', $btc->amount());
    }

    public function test_exchange_rate_invert_preserves_precision(): void
    {
        // Use a simple rate for perfect round-trip
        $rate = ExchangeRate::fromString('TOKENA', 'TOKENB', '2.00000000', 8);
        $inverted = $rate->invert();

        self::assertSame('TOKENB', $inverted->baseCurrency());
        self::assertSame('TOKENA', $inverted->quoteCurrency());
        self::assertSame(8, $inverted->scale());

        // 1/2 = 0.5
        self::assertSame('0.50000000', $inverted->rate());

        // Convert 2 TOKENB should give 1 TOKENA
        $tokenB = Money::fromString('TOKENB', '2.00000000', 8);
        $tokenA = $inverted->convert($tokenB, 8);

        self::assertTrue($tokenA->equals(Money::fromString('TOKENA', '1.00000000', 8)));
    }

    // ==================== Scale Transition Stability ====================

    public function test_scale_transitions_maintain_value_equality(): void
    {
        $original = Money::fromString('USD', '123.45', 2);

        // Multiple scale transitions
        $s6 = $original->withScale(6);
        $s18 = $s6->withScale(18);
        $s8 = $s18->withScale(8);
        $s2 = $s8->withScale(2);

        self::assertTrue($original->equals($s2));
        self::assertSame('123.45', $s2->amount());
    }

    public function test_scale_zero_to_max_transition(): void
    {
        $zero = Money::fromString('JPY', '1000', 0);
        $max = $zero->withScale(50);

        self::assertSame('1000.00000000000000000000000000000000000000000000000000', $max->amount());

        $backToZero = $max->withScale(0);
        self::assertSame('1000', $backToZero->amount());
        self::assertTrue($zero->equals($backToZero));
    }

    // ==================== Comparison Operations at Different Scales ====================

    public function test_comparison_works_across_scales(): void
    {
        $low = Money::fromString('USD', '10.5', 2);
        $high = Money::fromString('USD', '10.500000000000000000', 18);

        self::assertTrue($low->equals($high));
        self::assertFalse($low->greaterThan($high));
        self::assertFalse($low->lessThan($high));
    }

    public function test_comparison_with_max_scale_difference(): void
    {
        $min = Money::fromString('TEST', '100', 0);
        $max = Money::fromString('TEST', '100.00000000000000000000000000000000000000000000000000', 50);

        self::assertTrue($min->equals($max));
    }

    // ==================== Operations with Zero ====================

    public function test_addition_with_zero_at_different_scales(): void
    {
        $value = Money::fromString('USD', '123.45', 2);
        $zero = Money::fromString('USD', '0', 0);

        $result = $value->add($zero);
        self::assertTrue($result->equals($value));

        // Test with zero at higher scale
        $zeroHigh = Money::fromString('USD', '0.000000000000000000', 18);
        $resultHigh = $value->add($zeroHigh, 18);
        self::assertSame(18, $resultHigh->scale());
        self::assertSame('123.450000000000000000', $resultHigh->amount());
    }

    public function test_subtraction_with_zero_at_different_scales(): void
    {
        $value = Money::fromString('USD', '123.45', 2);
        $zero = Money::fromString('USD', '0', 0);

        $result = $value->subtract($zero);
        self::assertTrue($result->equals($value));

        // Test with zero at higher scale
        $zeroHigh = Money::fromString('USD', '0.000000000000000000', 18);
        $resultHigh = $value->subtract($zeroHigh, 18);
        self::assertSame(18, $resultHigh->scale());
        self::assertSame('123.450000000000000000', $resultHigh->amount());
    }

    public function test_multiplication_by_zero(): void
    {
        $value = Money::fromString('USD', '123.45', 2);

        $result = $value->multiply('0', 2);
        self::assertTrue($result->isZero());
        self::assertSame('0.00', $result->amount());

        // Test at maximum scale
        $resultMax = $value->multiply('0', 50);
        self::assertTrue($resultMax->isZero());
        self::assertSame(50, $resultMax->scale());
    }

    public function test_multiplication_by_one(): void
    {
        $value = Money::fromString('USD', '123.45', 2);

        $result = $value->multiply('1', 2);
        self::assertTrue($result->equals($value));

        // Test at higher scale
        $resultHigh = $value->multiply('1', 18);
        self::assertSame(18, $resultHigh->scale());
        self::assertSame('123.450000000000000000', $resultHigh->amount());
    }

    // ==================== Division by Very Small Numbers ====================

    public function test_division_by_very_small_number(): void
    {
        $value = Money::fromString('USD', '1.00', 2);

        // Divide by very small number at high scale
        $result = $value->divide('0.000000000000000001', 18);
        // 1.00 / 0.000000000000000001 = 1,000,000,000,000,000,000
        self::assertSame('1000000000000000000.000000000000000000', $result->amount());
    }

    public function test_division_resulting_in_very_large_number(): void
    {
        $small = Money::fromString('BTC', '0.00000001', 8);

        $result = $small->divide('0.000000000000000001', 18);
        // Very large result
        self::assertSame(18, $result->scale());
    }

    // ==================== Subtraction Precision Edge Cases ====================

    public function test_subtraction_of_nearly_equal_values(): void
    {
        // Values that are nearly equal but differ in least significant digits
        $a = Money::fromString('USD', '100.00000001', 8);
        $b = Money::fromString('USD', '100.00000000', 8);

        $result = $a->subtract($b);
        self::assertSame('0.00000001', $result->amount());

        // Test with maximum scale
        $aMax = Money::fromString('PREC', '1.00000000000000000000000000000000000000000000000000', 50);
        $bMax = Money::fromString('PREC', '1.00000000000000000000000000000000000000000000000001', 50);

        $resultMax = $aMax->subtract($bMax);
        self::assertSame('-0.00000000000000000000000000000000000000000000000001', $resultMax->amount());
    }

    public function test_subtraction_resulting_in_zero_at_high_scale(): void
    {
        $a = Money::fromString('ETH', '1.000000000000000000', 18);
        $b = Money::fromString('ETH', '1.000000000000000000', 18);

        $result = $a->subtract($b);
        self::assertTrue($result->isZero());
        self::assertSame('0.000000000000000000', $result->amount());
    }

    // ==================== Complex Arithmetic Chains ====================

    public function test_complex_arithmetic_chain_at_scale_boundaries(): void
    {
        $initial = Money::fromString('CALC', '10.00', 2);

        // Chain: multiply -> divide -> add -> subtract
        $step1 = $initial->multiply('3', 2); // 30.00
        $step2 = $step1->divide('2', 2); // 15.00
        $step3 = $step2->add(Money::fromString('CALC', '5.00', 2), 2); // 20.00
        $step4 = $step3->subtract(Money::fromString('CALC', '10.00', 2), 2); // 10.00

        self::assertTrue($step4->equals($initial));
    }

    public function test_arithmetic_chain_preserves_precision_at_max_scale(): void
    {
        $initial = Money::fromString('PREC', '1.00000000000000000000000000000000000000000000000000', 50);

        // Chain operations at max scale
        $step1 = $initial->multiply('2', 50);
        $step2 = $step1->divide('4', 50);
        $step3 = $step2->add(Money::fromString('PREC', '0.50000000000000000000000000000000000000000000000000', 50), 50);

        // Should equal: (1 * 2 / 4) + 0.5 = 0.5 + 0.5 = 1.0
        self::assertTrue($step3->equals($initial));
    }

    // ==================== Exchange Rate Extreme Cases ====================

    public function test_exchange_rate_with_very_large_rate(): void
    {
        // Rate of 1e18 (quintillion)
        $rate = ExchangeRate::fromString('TINY', 'HUGE', '1000000000000000000', 0);
        $tiny = Money::fromString('TINY', '1', 0);

        $huge = $rate->convert($tiny, 0);
        self::assertSame('HUGE', $huge->currency());
        self::assertSame('1000000000000000000', $huge->amount());
    }

    public function test_exchange_rate_with_very_small_rate(): void
    {
        // Rate of 1e-18
        $rate = ExchangeRate::fromString('HUGE', 'TINY', '0.000000000000000001', 18);
        $huge = Money::fromString('HUGE', '1000000000000000000', 0);

        $tiny = $rate->convert($huge, 18);
        self::assertSame('TINY', $tiny->currency());
        self::assertSame('1.000000000000000000', $tiny->amount());
    }

    public function test_exchange_rate_inversion_of_extreme_values(): void
    {
        // Very large rate - need higher scale to represent the inversion
        $largeRate = ExchangeRate::fromString('AAA', 'BBB', '1000000000', 9);
        $inverted = $largeRate->invert();

        self::assertSame('BBB', $inverted->baseCurrency());
        self::assertSame('AAA', $inverted->quoteCurrency());
        self::assertSame('0.000000001', $inverted->rate());

        // Very small rate
        $smallRate = ExchangeRate::fromString('CCC', 'DDD', '0.000000001', 9);
        $invertedSmall = $smallRate->invert();

        self::assertSame('DDD', $invertedSmall->baseCurrency());
        self::assertSame('CCC', $invertedSmall->quoteCurrency());
        self::assertSame('1000000000.000000000', $invertedSmall->rate());
    }

    public function test_exchange_rate_double_inversion_precision(): void
    {
        // Test that rate->invert()->invert() preserves value within rounding
        $original = ExchangeRate::fromString('XXX', 'YYY', '1.5', 2);
        $doubleInverted = $original->invert()->invert();

        self::assertSame('XXX', $doubleInverted->baseCurrency());
        self::assertSame('YYY', $doubleInverted->quoteCurrency());
        // Due to rounding in intermediate steps, may not be exactly 1.50
        // But should be very close (within 0.01 of original)
        $rateValue = (float) $doubleInverted->rate();
        self::assertGreaterThanOrEqual(1.49, $rateValue);
        self::assertLessThanOrEqual(1.51, $rateValue);
    }

    // ==================== Rounding Edge Cases ====================

    public function test_half_up_rounding_at_max_scale_precision(): void
    {
        // Test rounding when scaling down from max scale
        $value = Money::fromString('MAX', '1.00000000000000000000000000000000000000000000000005', 50);

        // Scale to 49 to test rounding behavior
        $rounded = $value->withScale(49);
        self::assertSame(49, $rounded->scale());
        // Should round up due to the '5' at the 50th decimal place
        self::assertSame('1.0000000000000000000000000000000000000000000000001', $rounded->amount());
    }

    public function test_rounding_consistency_across_operations(): void
    {
        $a = Money::fromString('USD', '1.005', 3);
        $b = Money::fromString('USD', '1.004', 3);

        // Both should round to 1.01 when scaled to 2
        $aRounded = $a->withScale(2);
        $bRounded = $b->withScale(2);

        self::assertSame('1.01', $aRounded->amount()); // 1.005 -> 1.01 (rounds up)
        self::assertSame('1.00', $bRounded->amount()); // 1.004 -> 1.00 (rounds down)
    }
}
