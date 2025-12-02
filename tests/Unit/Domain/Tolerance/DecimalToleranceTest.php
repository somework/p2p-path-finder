<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Domain\Tolerance;

use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Domain\Tolerance\DecimalTolerance;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Helpers\DecimalMath;
use SomeWork\P2PPathFinder\Tests\Helpers\Generator\NumericStringGenerator;

use function max;
use function sprintf;
use function strlen;

#[CoversClass(DecimalTolerance::class)]
final class DecimalToleranceTest extends TestCase
{
    public function test_it_normalizes_ratio_to_default_scale(): void
    {
        $tolerance = DecimalTolerance::fromNumericString('0.1');

        self::assertSame('0.100000000000000000', $tolerance->ratio());
        self::assertSame(18, $tolerance->scale());
        self::assertFalse($tolerance->isZero());
    }

    public function test_it_preserves_precision_for_custom_scale(): void
    {
        $tolerance = DecimalTolerance::fromNumericString('0.12345678901234567890', 20);

        self::assertSame('0.12345678901234567890', $tolerance->ratio());
        self::assertSame(20, $tolerance->scale());
    }

    /**
     * @dataProvider provideToleranceRatios
     */
    public function test_normalized_ratio_stays_within_bounds(string $ratio, int $scale): void
    {
        $tolerance = DecimalTolerance::fromNumericString($ratio, $scale);
        $comparisonScale = max($scale, 18);
        $expected = DecimalMath::decimal($ratio, $scale);

        self::assertSame($expected->__toString(), $tolerance->ratio());

        $ratioDecimal = DecimalMath::decimal($tolerance->ratio(), $comparisonScale);
        self::assertGreaterThanOrEqual(0, $ratioDecimal->compareTo(BigDecimal::zero()));
        self::assertLessThanOrEqual(0, $ratioDecimal->compareTo(BigDecimal::one()));
    }

    public function test_zero_factory_provides_normalized_ratio(): void
    {
        $zero = DecimalTolerance::zero();

        self::assertTrue($zero->isZero());
        self::assertSame('0.000000000000000000', $zero->ratio());
        self::assertSame(18, $zero->scale());
    }

    public function test_compare_honours_requested_scale(): void
    {
        $tolerance = DecimalTolerance::fromNumericString('0.125', 3);

        self::assertSame(0, $tolerance->compare('0.1250', 4));
        self::assertGreaterThan(0, $tolerance->compare('0.1249', 4));
        self::assertLessThan(0, $tolerance->compare('0.1252', 4));
    }

    public function test_compare_uses_internal_scale_when_not_provided(): void
    {
        $tolerance = DecimalTolerance::fromNumericString('0.333333333333333333');

        self::assertSame(0, $tolerance->compare('0.333333333333333333'));
        self::assertGreaterThan(0, $tolerance->compare('0.333333333333333332'));
        self::assertLessThan(0, $tolerance->compare('0.333333333333333334'));
    }

    public function test_compare_throws_when_negative_scale_is_provided(): void
    {
        $tolerance = DecimalTolerance::fromNumericString('0.5');

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Scale must be a non-negative integer.');

        $tolerance->compare('0.4', -1);
    }

    public function test_percentage_representation_is_rounded_to_requested_scale(): void
    {
        $tolerance = DecimalTolerance::fromNumericString('0.123456789', 9);

        self::assertSame('12.345679', $tolerance->percentage(6));
        self::assertSame('12.35', $tolerance->percentage(2));
    }

    public function test_percentage_throws_when_scale_is_negative(): void
    {
        $tolerance = DecimalTolerance::fromNumericString('0.123');

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Scale must be a non-negative integer.');

        $tolerance->percentage(-1);
    }

    public function test_from_numeric_string_rejects_out_of_range_values(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Residual tolerance must be a value between 0 and 1 inclusive.');

        DecimalTolerance::fromNumericString('1.2');
    }

    public function test_from_numeric_string_accepts_boundary_values(): void
    {
        $zero = DecimalTolerance::fromNumericString('0');
        $one = DecimalTolerance::fromNumericString('1');

        self::assertTrue($zero->isZero());
        self::assertTrue($one->isGreaterThanOrEqual('1'));
        self::assertSame('1.000000000000000000', $one->ratio());
    }

    public function test_from_numeric_string_rejects_negative_scale(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Scale must be a non-negative integer.');

        DecimalTolerance::fromNumericString('0.5', -1);
    }

    public function test_comparison_helpers_cover_both_directions(): void
    {
        $tolerance = DecimalTolerance::fromNumericString('0.25');

        self::assertTrue($tolerance->isGreaterThanOrEqual('0.249999999999999999'));
        self::assertTrue($tolerance->isLessThanOrEqual('0.25'));
        self::assertFalse($tolerance->isLessThanOrEqual('0.249'));
        self::assertFalse($tolerance->isGreaterThanOrEqual('0.251'));
    }

    public function test_from_numeric_string_rejects_empty_string(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Value "" is not numeric.');

        DecimalTolerance::fromNumericString('');
    }

    public function test_from_numeric_string_rejects_non_numeric_strings(): void
    {
        $invalidInputs = ['abc', 'hello world', 'not-a-number', '12.34.56', '1,234'];

        foreach ($invalidInputs as $invalid) {
            try {
                DecimalTolerance::fromNumericString($invalid);
                self::fail('Expected InvalidInput for non-numeric string: '.$invalid);
            } catch (InvalidInput $e) {
                self::assertStringContainsString('not numeric', $e->getMessage());
            }
        }
    }

    public function test_from_numeric_string_handles_scientific_notation(): void
    {
        // Test scientific notation that results in valid tolerance values
        $tolerance = DecimalTolerance::fromNumericString('5e-2'); // 0.05

        self::assertSame('0.050000000000000000', $tolerance->ratio());
        self::assertSame(18, $tolerance->scale());

        $smallTolerance = DecimalTolerance::fromNumericString('1e-18'); // Very small but valid
        self::assertSame('0.000000000000000001', $smallTolerance->ratio());
    }

    public function test_from_numeric_string_rejects_scientific_notation_out_of_range(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Residual tolerance must be a value between 0 and 1 inclusive.');

        DecimalTolerance::fromNumericString('1.5e0'); // 1.5, which is > 1.0
    }

    public function test_from_numeric_string_rejects_unicode_characters(): void
    {
        $this->expectException(InvalidInput::class);

        DecimalTolerance::fromNumericString('0.5€');
    }

    public function test_from_numeric_string_rejects_control_characters(): void
    {
        $this->expectException(InvalidInput::class);

        DecimalTolerance::fromNumericString('0.5'."\x00");
    }

    public function test_from_numeric_string_handles_very_long_numeric_strings(): void
    {
        // Test with very long decimal strings (should work if valid)
        $longDecimal = '0.'.str_repeat('1', 50);
        $tolerance = DecimalTolerance::fromNumericString($longDecimal);

        // Should normalize and be valid (less than 1.0)
        self::assertGreaterThanOrEqual(0, (float) $tolerance->ratio());
        self::assertLessThanOrEqual(1, (float) $tolerance->ratio());
    }

    public function test_from_numeric_string_rejects_extremely_long_invalid_strings(): void
    {
        $longInvalid = str_repeat('a', 1000);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Value "'.$longInvalid.'" is not numeric.');

        DecimalTolerance::fromNumericString($longInvalid);
    }

    public function test_handles_extreme_scales(): void
    {
        // Test with maximum allowed scale (50)
        $tolerance = DecimalTolerance::fromNumericString('0.5', 50);

        self::assertSame(50, $tolerance->scale());
        // The ratio should be formatted with the specified scale
        self::assertSame('0.50000000000000000000000000000000000000000000000000', $tolerance->ratio());

        // Test with scale = 0
        $zeroScaleTolerance = DecimalTolerance::fromNumericString('0', 0);
        self::assertSame(0, $zeroScaleTolerance->scale());
        self::assertSame('0', $zeroScaleTolerance->ratio());
    }

    public function test_from_numeric_string_handles_scale_zero_boundary_cases(): void
    {
        // Scale 0 should work for values that round to 0 or 1
        $zeroTolerance = DecimalTolerance::fromNumericString('0', 0);
        self::assertSame('0', $zeroTolerance->ratio());
        self::assertSame(0, $zeroTolerance->scale());

        $oneTolerance = DecimalTolerance::fromNumericString('1', 0);
        self::assertSame('1', $oneTolerance->ratio());
        self::assertSame(0, $oneTolerance->scale());

        // Values that round to 0 should work
        $almostZero = DecimalTolerance::fromNumericString('0.4', 0); // Rounds to 0
        self::assertSame('0', $almostZero->ratio());

        // Values that round to 1 should work
        $almostOne = DecimalTolerance::fromNumericString('0.6', 0); // Rounds to 1
        self::assertSame('1', $almostOne->ratio());
    }

    public function test_values_rounding_to_exactly_zero(): void
    {
        // Test values that round to exactly 0.0 at different scales
        $tolerance = DecimalTolerance::fromNumericString('0.000000000000000000499', 18);

        self::assertSame('0.000000000000000000', $tolerance->ratio());
        self::assertTrue($tolerance->isZero());
    }

    public function test_values_rounding_to_exactly_one(): void
    {
        // Test values that round to exactly 1.0
        $tolerance = DecimalTolerance::fromNumericString('0.9999999999999999995', 18);

        self::assertSame('1.000000000000000000', $tolerance->ratio());
        self::assertTrue($tolerance->isGreaterThanOrEqual('1'));
    }

    public function test_precision_boundary_rounding_behavior(): void
    {
        // Test rounding at the very edge of precision
        $almostOne = '0.9999999999999999994'; // Should round down to 0.999999999999999999
        $tolerance = DecimalTolerance::fromNumericString($almostOne, 18);

        self::assertSame('0.999999999999999999', $tolerance->ratio());
        self::assertTrue($tolerance->isLessThanOrEqual('0.999999999999999999'));
        self::assertFalse($tolerance->isGreaterThanOrEqual('1'));
    }

    public function test_percentage_with_extreme_scales(): void
    {
        $tolerance = DecimalTolerance::fromNumericString('0.123456789012345678');

        // Test percentage with very high precision
        $highPrecision = $tolerance->percentage(10);
        self::assertSame('12.3456789012', $highPrecision);

        // Test percentage with zero scale (integer percentage)
        $zeroScale = $tolerance->percentage(0);
        self::assertSame('12', $zeroScale);

        // Test percentage with high scale (within limits)
        $highScale = $tolerance->percentage(20);
        self::assertStringStartsWith('12.', $highScale);
        self::assertSame(23, strlen($highScale)); // 12. + 20 digits + decimal point
    }

    public function test_percentage_rounding_behavior(): void
    {
        // Test that percentage rounding uses HALF_UP as documented
        $tolerance = DecimalTolerance::fromNumericString('0.005000000000000000'); // 0.5%

        $percentage = $tolerance->percentage(1);
        self::assertSame('0.5', $percentage); // 0.005 * 100 = 0.5, rounds to 0.5 at scale 1

        $tolerance2 = DecimalTolerance::fromNumericString('0.004000000000000000'); // 0.4%
        $percentage2 = $tolerance2->percentage(1);
        self::assertSame('0.4', $percentage2); // Should not round up

        // Test rounding at boundary
        $tolerance3 = DecimalTolerance::fromNumericString('0.005500000000000000'); // 0.55%
        $percentage3 = $tolerance3->percentage(1);
        self::assertSame('0.6', $percentage3); // Should round up 0.55 to 0.6
    }

    public function test_compare_with_extreme_scale_differences(): void
    {
        $tolerance = DecimalTolerance::fromNumericString('0.5', 2);

        // Compare with much higher scale
        $result = $tolerance->compare('0.500000000000000000', 18);
        self::assertSame(0, $result);

        // Compare with much lower scale
        $result2 = $tolerance->compare('0.5', 0);
        self::assertSame(0, $result2);
    }

    public function test_compare_at_precision_boundaries(): void
    {
        $tolerance = DecimalTolerance::fromNumericString('0.123456789012345678');

        // Compare with value that differs at the last digit
        self::assertSame(0, $tolerance->compare('0.123456789012345678'));
        self::assertGreaterThan(0, $tolerance->compare('0.123456789012345677'));
        self::assertLessThan(0, $tolerance->compare('0.123456789012345679'));
    }

    public function test_comparison_helpers_with_null_scale(): void
    {
        $tolerance = DecimalTolerance::fromNumericString('0.25');

        // Test that null scale uses internal scale
        self::assertTrue($tolerance->isGreaterThanOrEqual('0.249999999999999999', null));
        self::assertTrue($tolerance->isLessThanOrEqual('0.250000000000000001', null));
    }

    public function test_from_numeric_string_with_null_scale_uses_default(): void
    {
        $tolerance = DecimalTolerance::fromNumericString('0.5', null);

        self::assertSame(18, $tolerance->scale());
        self::assertSame('0.500000000000000000', $tolerance->ratio());
    }

    public function test_zero_tolerance_edge_cases(): void
    {
        // Test various representations of zero
        $zeros = ['0', '0.0', '0.000', '0e-10', '0.000000000000000000'];

        foreach ($zeros as $zero) {
            $tolerance = DecimalTolerance::fromNumericString($zero);
            self::assertTrue($tolerance->isZero());
            self::assertSame('0.000000000000000000', $tolerance->ratio());
        }
    }

    public function test_one_tolerance_edge_cases(): void
    {
        // Test values that round to exactly 1.0
        $ones = ['1', '1.0', '0.9999999999999999995'];

        foreach ($ones as $one) {
            $tolerance = DecimalTolerance::fromNumericString($one);
            self::assertTrue($tolerance->isGreaterThanOrEqual('1'));
            self::assertTrue($tolerance->isLessThanOrEqual('1'));
        }
    }

    public function test_scale_validation_edge_cases(): void
    {
        // Test maximum allowed scale (50)
        $tolerance = DecimalTolerance::fromNumericString('0.5', 50);
        self::assertSame(50, $tolerance->scale());

        // Test that scale validation works for all methods
        $tolerance = DecimalTolerance::fromNumericString('0.5');

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Scale must be a non-negative integer.');

        $tolerance->percentage(-1);
    }

    /**
     * @return iterable<string, array{numeric-string, int}>
     */
    public static function provideToleranceRatios(): iterable
    {
        $case = 0;

        foreach (NumericStringGenerator::toleranceRatios() as [$ratio, $scale]) {
            yield sprintf('ratio-%d', $case++) => [$ratio, $scale];
        }
    }
}
