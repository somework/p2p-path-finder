<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

use function strlen;

/**
 * Explicit unit tests for DecimalMath test helper to verify parity with production code.
 */
#[CoversClass(DecimalMath::class)]
final class DecimalMathTest extends TestCase
{
    // ==================== Test Scenario 1: add() vs Money.add() ====================

    public function test_add_produces_same_result_as_money_add(): void
    {
        $moneyA = Money::fromString('USD', '10.5', 2);
        $moneyB = Money::fromString('USD', '20.3', 2);
        $moneyResult = $moneyA->add($moneyB);

        $decimalResult = DecimalMath::add('10.5', '20.3', 2);

        self::assertSame($moneyResult->amount(), $decimalResult);
        self::assertSame('30.80', $decimalResult);
    }

    public function test_add_with_scale_0(): void
    {
        $result = DecimalMath::add('10', '20', 0);

        self::assertSame('30', $result);
    }

    public function test_add_with_scale_18(): void
    {
        $result = DecimalMath::add('1.5', '2.3', 18);

        self::assertSame('3.800000000000000000', $result);
    }

    // ==================== Test Scenario 2: mul() vs Money.multiply() ====================

    public function test_multiply_produces_same_result_as_money_multiply(): void
    {
        $money = Money::fromString('USD', '10.5', 2);
        $moneyResult = $money->multiply('3.0', 2);

        $decimalResult = DecimalMath::mul('10.5', '3.0', 2);

        self::assertSame($moneyResult->amount(), $decimalResult);
        self::assertSame('31.50', $decimalResult);
    }

    public function test_multiply_with_scale_0(): void
    {
        $result = DecimalMath::mul('5', '3', 0);

        self::assertSame('15', $result);
    }

    public function test_multiply_rounds_half_up(): void
    {
        $result = DecimalMath::mul('10.55', '3.33', 2);

        // 10.55 * 3.33 = 35.1315, rounds to 35.13
        self::assertSame('35.13', $result);
    }

    // ==================== Test Scenario 3: div() vs Money.divide() ====================

    public function test_divide_produces_same_result_as_money_divide(): void
    {
        $money = Money::fromString('USD', '10.0', 2);
        $moneyResult = $money->divide('3.0', 2);

        $decimalResult = DecimalMath::div('10.0', '3.0', 2);

        self::assertSame($moneyResult->amount(), $decimalResult);
        self::assertSame('3.33', $decimalResult);
    }

    public function test_divide_rounds_half_up(): void
    {
        // 10 / 3 = 3.333..., at scale 2 with HALF_UP rounds to 3.33
        $result = DecimalMath::div('10', '3', 2);

        self::assertSame('3.33', $result);
    }

    public function test_divide_at_scale_8(): void
    {
        $result = DecimalMath::div('1', '3', 8);

        self::assertSame('0.33333333', $result);
    }

    // ==================== Test Scenario 4: comp() vs Money.compare() ====================

    public function test_compare_returns_zero_for_equal_values(): void
    {
        $result = DecimalMath::comp('10.0', '10.00', 2);

        self::assertSame(0, $result);
    }

    public function test_compare_returns_negative_when_left_is_smaller(): void
    {
        $result = DecimalMath::comp('5.0', '10.0', 2);

        self::assertLessThan(0, $result);
    }

    public function test_compare_returns_positive_when_left_is_larger(): void
    {
        $result = DecimalMath::comp('15.0', '10.0', 2);

        self::assertGreaterThan(0, $result);
    }

    public function test_compare_matches_money_comparison(): void
    {
        $moneyA = Money::fromString('USD', '10.0', 2);
        $moneyB = Money::fromString('USD', '10.00', 2);
        $moneyResult = $moneyA->compare($moneyB);

        $decimalResult = DecimalMath::comp('10.0', '10.00', 2);

        self::assertSame($moneyResult, $decimalResult);
    }

    // ==================== Test Scenario 5: Rounding at +0.5 (HALF_UP) ====================

    public function test_normalize_rounds_half_up_at_positive_half(): void
    {
        self::assertSame('1', DecimalMath::normalize('0.5', 0));
        self::assertSame('2', DecimalMath::normalize('1.5', 0));
        self::assertSame('3', DecimalMath::normalize('2.5', 0));
    }

    public function test_normalize_rounds_0_point_5_to_1(): void
    {
        $result = DecimalMath::normalize('0.5', 0);

        self::assertSame('1', $result);
    }

    public function test_normalize_rounds_2_point_5_to_3(): void
    {
        $result = DecimalMath::normalize('2.5', 0);

        self::assertSame('3', $result);
    }

    // ==================== Test Scenario 6: Rounding at -0.5 (HALF_UP) ====================

    public function test_normalize_rounds_half_up_at_negative_half(): void
    {
        self::assertSame('-1', DecimalMath::normalize('-0.5', 0));
        self::assertSame('-2', DecimalMath::normalize('-1.5', 0));
        self::assertSame('-3', DecimalMath::normalize('-2.5', 0));
    }

    public function test_normalize_rounds_negative_half_away_from_zero(): void
    {
        $result = DecimalMath::normalize('-0.5', 0);

        // HALF_UP rounds -0.5 to -1 (away from zero)
        self::assertSame('-1', $result);
    }

    // ==================== Test Scenario 7: All scales (0, 2, 8, 18) ====================

    public function test_all_operations_work_at_scale_0(): void
    {
        self::assertSame('30', DecimalMath::add('10', '20', 0));
        self::assertSame('10', DecimalMath::sub('30', '20', 0));
        self::assertSame('200', DecimalMath::mul('10', '20', 0));
        self::assertSame('5', DecimalMath::div('10', '2', 0));
    }

    public function test_all_operations_work_at_scale_2(): void
    {
        self::assertSame('30.80', DecimalMath::add('10.50', '20.30', 2));
        self::assertSame('10.20', DecimalMath::sub('30.50', '20.30', 2));
        self::assertSame('31.50', DecimalMath::mul('10.50', '3.00', 2));
        self::assertSame('3.33', DecimalMath::div('10.00', '3.00', 2));
    }

    public function test_all_operations_work_at_scale_8(): void
    {
        self::assertSame('0.30000000', DecimalMath::add('0.10000000', '0.20000000', 8));
        self::assertSame('0.10000000', DecimalMath::sub('0.30000000', '0.20000000', 8));
        self::assertSame('0.02000000', DecimalMath::mul('0.10000000', '0.20000000', 8));
        self::assertSame('0.33333333', DecimalMath::div('1.00000000', '3.00000000', 8));
    }

    public function test_all_operations_work_at_scale_18(): void
    {
        $add = DecimalMath::add('1.5', '2.3', 18);
        $sub = DecimalMath::sub('10.0', '3.5', 18);
        $mul = DecimalMath::mul('2.0', '3.0', 18);
        $div = DecimalMath::div('10.0', '4.0', 18);

        self::assertSame('3.800000000000000000', $add);
        self::assertSame('6.500000000000000000', $sub);
        self::assertSame('6.000000000000000000', $mul);
        self::assertSame('2.500000000000000000', $div);
    }

    // ==================== Test Scenario 8: isNumeric() validation ====================

    public function test_is_numeric_accepts_zero(): void
    {
        self::assertTrue(DecimalMath::isNumeric('0'));
    }

    public function test_is_numeric_accepts_positive_integer(): void
    {
        self::assertTrue(DecimalMath::isNumeric('123'));
    }

    public function test_is_numeric_accepts_negative_integer(): void
    {
        self::assertTrue(DecimalMath::isNumeric('-456'));
    }

    public function test_is_numeric_accepts_positive_decimal(): void
    {
        self::assertTrue(DecimalMath::isNumeric('0.5'));
        self::assertTrue(DecimalMath::isNumeric('123.456'));
    }

    public function test_is_numeric_accepts_negative_decimal(): void
    {
        self::assertTrue(DecimalMath::isNumeric('-0.5'));
        self::assertTrue(DecimalMath::isNumeric('-123.456'));
    }

    public function test_is_numeric_rejects_empty_string(): void
    {
        self::assertFalse(DecimalMath::isNumeric(''));
    }

    public function test_is_numeric_rejects_alphabetic_string(): void
    {
        self::assertFalse(DecimalMath::isNumeric('abc'));
    }

    public function test_is_numeric_rejects_multiple_decimal_points(): void
    {
        self::assertFalse(DecimalMath::isNumeric('1.2.3'));
    }

    public function test_is_numeric_rejects_scientific_notation(): void
    {
        self::assertFalse(DecimalMath::isNumeric('1e5'));
        self::assertFalse(DecimalMath::isNumeric('1.5e-2'));
    }

    public function test_is_numeric_rejects_text_with_numbers(): void
    {
        self::assertFalse(DecimalMath::isNumeric('12abc'));
        self::assertFalse(DecimalMath::isNumeric('abc12'));
    }

    // ==================== Test Scenario 9: ensureNumeric() throws on invalid ====================

    public function test_ensure_numeric_accepts_valid_string(): void
    {
        DecimalMath::ensureNumeric('123');
        DecimalMath::ensureNumeric('0.5', '-456', '123.456');

        // No exception thrown
        $this->expectNotToPerformAssertions();
    }

    public function test_ensure_numeric_throws_on_empty_string(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Value "" is not numeric.');

        DecimalMath::ensureNumeric('');
    }

    public function test_ensure_numeric_throws_on_alphabetic_string(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Value "abc" is not numeric.');

        DecimalMath::ensureNumeric('abc');
    }

    public function test_ensure_numeric_throws_on_scientific_notation(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Value "1e5" is not numeric.');

        DecimalMath::ensureNumeric('1e5');
    }

    public function test_ensure_numeric_throws_on_first_invalid_value_in_variadics(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Value "invalid" is not numeric.');

        DecimalMath::ensureNumeric('123', '456', 'invalid', '789');
    }

    // ==================== Test Scenario 10: normalize() matches production ====================

    public function test_normalize_produces_same_output_as_money(): void
    {
        $money = Money::fromString('USD', '1.5', 18);
        $moneyAmount = $money->amount();

        $decimalAmount = DecimalMath::normalize('1.5', 18);

        self::assertSame($moneyAmount, $decimalAmount);
        self::assertSame('1.500000000000000000', $decimalAmount);
    }

    public function test_normalize_preserves_trailing_zeros(): void
    {
        $result = DecimalMath::normalize('1.5', 18);

        // Should have 18 decimal places with trailing zeros
        self::assertSame('1.500000000000000000', $result);
        self::assertSame(20, strlen($result)); // "1" + "." + 18 digits = 20 chars
    }

    public function test_normalize_rounds_beyond_scale(): void
    {
        $result = DecimalMath::normalize('0.123456789', 8);

        // Should round to 8 decimals using HALF_UP
        self::assertSame('0.12345679', $result);
    }

    // ==================== Additional: Scale validation ====================

    public function test_normalize_rejects_negative_scale(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Scale must be a non-negative integer.');

        DecimalMath::normalize('1.5', -1);
    }

    public function test_add_rejects_negative_scale(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Scale must be a non-negative integer.');

        DecimalMath::add('1', '2', -1);
    }

    // ==================== Additional: decimal() method ====================

    public function test_decimal_returns_big_decimal_at_correct_scale(): void
    {
        $decimal = DecimalMath::decimal('1.5', 2);

        self::assertSame('1.50', $decimal->__toString());
        self::assertSame(2, $decimal->getScale());
    }

    public function test_decimal_rounds_to_specified_scale(): void
    {
        $decimal = DecimalMath::decimal('1.556', 2);

        self::assertSame('1.56', $decimal->__toString());
    }

    // ==================== Additional: sub() operation ====================

    public function test_subtract_basic_operation(): void
    {
        $result = DecimalMath::sub('30.50', '20.30', 2);

        self::assertSame('10.20', $result);
    }

    public function test_subtract_produces_negative_result(): void
    {
        $result = DecimalMath::sub('5.0', '10.0', 2);

        self::assertSame('-5.00', $result);
    }

    // ==================== Additional: Edge cases ====================

    public function test_operations_with_zero(): void
    {
        self::assertSame('10.50', DecimalMath::add('10.50', '0.00', 2));
        self::assertSame('10.50', DecimalMath::sub('10.50', '0.00', 2));
        self::assertSame('0.00', DecimalMath::mul('10.50', '0.00', 2));
    }

    public function test_operations_with_very_small_numbers(): void
    {
        $result = DecimalMath::add('0.000000000000000001', '0.000000000000000002', 18);

        self::assertSame('0.000000000000000003', $result);
    }

    public function test_normalize_with_large_number(): void
    {
        $result = DecimalMath::normalize('999999999.99', 2);

        self::assertSame('999999999.99', $result);
    }

    // ==================== Scale Edge Cases ====================

    public function test_decimal_helper_at_canonical_scale_18(): void
    {
        $normalized = DecimalMath::normalize('1.5', 18);

        self::assertSame('1.500000000000000000', $normalized);
        self::assertSame(20, strlen($normalized)); // "1" + "." + 18 digits
    }

    public function test_decimal_helper_at_max_scale_50(): void
    {
        $normalized = DecimalMath::normalize('2.5', 50);

        self::assertSame(52, strlen($normalized)); // "2" + "." + 50 digits
        self::assertStringStartsWith('2.5', $normalized);
        self::assertStringEndsWith('0', $normalized);
    }

    public function test_operations_at_scale_50(): void
    {
        $add = DecimalMath::add('1.5', '2.5', 50);
        $mul = DecimalMath::mul('1.5', '2.0', 50);
        $div = DecimalMath::div('10.0', '4.0', 50);

        self::assertSame(52, strlen($add));
        self::assertSame(52, strlen($mul));
        self::assertSame(52, strlen($div));

        self::assertStringStartsWith('4.0', $add);
        self::assertStringStartsWith('3.0', $mul);
        self::assertStringStartsWith('2.5', $div);
    }

    public function test_operations_work_at_scale_51(): void
    {
        // DecimalMath is a test helper and may support scales beyond production limits
        $result = DecimalMath::normalize('1.5', 51);

        self::assertSame(53, strlen($result)); // "1" + "." + 51 digits
        self::assertStringStartsWith('1.5', $result);
    }

    public function test_operations_preserve_scale_50_precision(): void
    {
        // Test that operations at scale 50 don't lose precision
        $a = '0.00000000000000000000000000000000000000000000000001';
        $b = '0.00000000000000000000000000000000000000000000000002';

        $sum = DecimalMath::add($a, $b, 50);
        self::assertSame('0.00000000000000000000000000000000000000000000000003', $sum);
    }

    public function test_division_at_scale_50_with_repeating_decimal(): void
    {
        $result = DecimalMath::div('1', '3', 50);

        // 1/3 = 0.333... at scale 50
        self::assertSame('0.33333333333333333333333333333333333333333333333333', $result);
        self::assertSame(52, strlen($result));
    }

    public function test_multiplication_at_high_scale_maintains_accuracy(): void
    {
        $result = DecimalMath::mul('0.123456789012345678', '2.0', 18);

        self::assertSame('0.246913578024691356', $result);
    }

    public function test_scale_transition_from_0_to_50(): void
    {
        $value = '123';
        $scaled = DecimalMath::normalize($value, 50);

        self::assertSame('123.00000000000000000000000000000000000000000000000000', $scaled);
    }

    public function test_scale_transition_from_50_to_0(): void
    {
        $value = '123.99999999999999999999999999999999999999999999999999';
        $scaled = DecimalMath::normalize($value, 0);

        // HALF_UP rounding: should round to 124
        self::assertSame('124', $scaled);
    }

    public function test_operations_with_mixed_extreme_scales(): void
    {
        // Add a very precise number to a whole number
        $precise = '0.123456789012345678';
        $whole = '100';

        $sum = DecimalMath::add($precise, $whole, 18);
        self::assertSame('100.123456789012345678', $sum);
    }

    public function test_very_large_number_at_scale_18(): void
    {
        $large = '999999999999.999999999999999999';
        $normalized = DecimalMath::normalize($large, 18);

        self::assertSame('999999999999.999999999999999999', $normalized);
    }

    public function test_very_small_number_at_scale_18(): void
    {
        $tiny = '0.000000000000000001';
        $normalized = DecimalMath::normalize($tiny, 18);

        self::assertSame('0.000000000000000001', $normalized);
    }

    public function test_comparison_at_different_scales(): void
    {
        // Compare values that are equal but at different implicit precisions
        $a = '1.0';
        $b = '1.00000000000000000';

        $comparison = DecimalMath::comp($a, $b, 18);
        self::assertSame(0, $comparison, 'Values should be equal when normalized to same scale');
    }

    public function test_rounding_consistency_across_scales(): void
    {
        // Test that rounding is consistent regardless of scale
        $value = '1.5555555555555555555555555555555555555555555555555555';

        // Round to various scales
        $s0 = DecimalMath::normalize($value, 0);
        $s2 = DecimalMath::normalize($value, 2);
        $s8 = DecimalMath::normalize($value, 8);
        $s18 = DecimalMath::normalize($value, 18);
        $s50 = DecimalMath::normalize($value, 50);

        self::assertSame('2', $s0);
        self::assertSame('1.56', $s2);
        self::assertSame('1.55555556', $s8);
        self::assertSame('1.555555555555555556', $s18);
        self::assertSame('1.55555555555555555555555555555555555555555555555556', $s50);
    }
}
