<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Domain\Tolerance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\RandomException;
use SomeWork\P2PPathFinder\Domain\Tolerance\ToleranceWindow;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Helpers\Generator\NumericStringGenerator;

use function random_int;
use function sprintf;

#[CoversClass(ToleranceWindow::class)]
final class ToleranceWindowTest extends TestCase
{
    public function test_from_strings_normalizes_bounds_and_selects_maximum_heuristic(): void
    {
        $window = ToleranceWindow::fromStrings('0.01', '0.025');

        self::assertSame('0.010000000000000000', $window->minimum());
        self::assertSame('0.025000000000000000', $window->maximum());
        self::assertSame('0.025000000000000000', $window->heuristicTolerance());
        self::assertSame('maximum', $window->heuristicSource());
    }

    public function test_equal_bounds_use_minimum_for_heuristic(): void
    {
        $window = ToleranceWindow::fromStrings('0.015', '0.015');

        self::assertSame('0.015000000000000000', $window->minimum());
        self::assertSame('0.015000000000000000', $window->heuristicTolerance());
        self::assertSame('minimum', $window->heuristicSource());
    }

    public function test_normalize_tolerance_rejects_out_of_range_values(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Minimum tolerance must be in the [0, 1) range.');

        ToleranceWindow::normalizeTolerance('1.2', 'Minimum tolerance');
    }

    public function test_normalize_tolerance_returns_canonical_string(): void
    {
        self::assertSame(
            '0.123456789012345678',
            ToleranceWindow::normalizeTolerance('0.1234567890123456784', 'any'),
        );
    }

    public function test_from_strings_rejects_inverted_bounds(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Minimum tolerance must be less than or equal to maximum tolerance.');

        ToleranceWindow::fromStrings('0.5', '0.1');
    }

    public function test_zero_window_is_valid(): void
    {
        $window = ToleranceWindow::fromStrings('0', '0');

        self::assertSame('0.000000000000000000', $window->minimum());
        self::assertSame('0.000000000000000000', $window->maximum());
        self::assertSame('0.000000000000000000', $window->heuristicTolerance());
        self::assertSame('minimum', $window->heuristicSource());
    }

    public function test_window_starting_at_zero(): void
    {
        $window = ToleranceWindow::fromStrings('0', '0.5');

        self::assertSame('0.000000000000000000', $window->minimum());
        self::assertSame('0.500000000000000000', $window->maximum());
        self::assertSame('0.500000000000000000', $window->heuristicTolerance());
        self::assertSame('maximum', $window->heuristicSource());
    }

    public function test_window_near_upper_bound(): void
    {
        $window = ToleranceWindow::fromStrings('0.5', '0.999999999999999999');

        self::assertSame('0.500000000000000000', $window->minimum());
        self::assertSame('0.999999999999999999', $window->maximum());
        self::assertSame('0.999999999999999999', $window->heuristicTolerance());
    }

    public function test_rejects_minimum_equal_to_one(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Minimum tolerance must be in the [0, 1) range.');

        ToleranceWindow::fromStrings('1.0', '1.0');
    }

    public function test_rejects_maximum_equal_to_one(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Maximum tolerance must be in the [0, 1) range.');

        ToleranceWindow::fromStrings('0.5', '1.0');
    }

    public function test_rejects_minimum_greater_than_one(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Minimum tolerance must be in the [0, 1) range.');

        ToleranceWindow::fromStrings('1.5', '2.0');
    }

    public function test_rejects_negative_minimum(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Minimum tolerance must be in the [0, 1) range.');

        ToleranceWindow::fromStrings('-0.1', '0.5');
    }

    public function test_rejects_negative_maximum(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Maximum tolerance must be in the [0, 1) range.');

        ToleranceWindow::fromStrings('0.1', '-0.5');
    }

    public function test_handles_scientific_notation(): void
    {
        $window = ToleranceWindow::fromStrings('1e-3', '5e-2');

        self::assertSame('0.001000000000000000', $window->minimum());
        self::assertSame('0.050000000000000000', $window->maximum());
    }

    public function test_handles_integer_string_input(): void
    {
        $window = ToleranceWindow::fromStrings('0', '0');

        self::assertSame('0.000000000000000000', $window->minimum());
        self::assertSame('0.000000000000000000', $window->maximum());
    }

    public function test_normalizes_trailing_zeros(): void
    {
        $window = ToleranceWindow::fromStrings('0.100000', '0.500000');

        self::assertSame('0.100000000000000000', $window->minimum());
        self::assertSame('0.500000000000000000', $window->maximum());
    }

    public function test_scale_returns_canonical_scale(): void
    {
        self::assertSame(18, ToleranceWindow::scale());
    }

    public function test_very_small_tolerances(): void
    {
        $window = ToleranceWindow::fromStrings('0.000000000000000001', '0.000000000000000002');

        self::assertSame('0.000000000000000001', $window->minimum());
        self::assertSame('0.000000000000000002', $window->maximum());
        self::assertSame('0.000000000000000002', $window->heuristicTolerance());
        self::assertSame('maximum', $window->heuristicSource());
    }

    public function test_rounds_values_beyond_canonical_scale(): void
    {
        $window = ToleranceWindow::fromStrings(
            '0.123456789012345678499',
            '0.987654321098765432111'
        );

        // Values should be rounded to 18 decimal places
        self::assertSame('0.123456789012345678', $window->minimum());
        self::assertSame('0.987654321098765432', $window->maximum());
    }

    public function test_normalize_tolerance_with_zero(): void
    {
        self::assertSame(
            '0.000000000000000000',
            ToleranceWindow::normalizeTolerance('0', 'Test tolerance'),
        );
    }

    public function test_normalize_tolerance_rejects_one(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Test tolerance must be in the [0, 1) range.');

        ToleranceWindow::normalizeTolerance('1.0', 'Test tolerance');
    }

    public function test_normalize_tolerance_rejects_negative(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Test tolerance must be in the [0, 1) range.');

        ToleranceWindow::normalizeTolerance('-0.1', 'Test tolerance');
    }

    // ==================== Additional Boundary Edge Case Tests ====================

    public function test_zero_tolerance_window(): void
    {
        // Test zero tolerance window (min = 0, max = 0)
        $window = ToleranceWindow::fromStrings('0.0', '0.0');

        self::assertSame('0.000000000000000000', $window->minimum());
        self::assertSame('0.000000000000000000', $window->maximum());
        self::assertSame('0.000000000000000000', $window->heuristicTolerance());
        self::assertSame('minimum', $window->heuristicSource());

        // Test with explicit zero strings
        $window2 = ToleranceWindow::fromStrings('0', '0.00');

        self::assertSame('0.000000000000000000', $window2->minimum());
        self::assertSame('0.000000000000000000', $window2->maximum());

        // Test with scientific notation zero
        $window3 = ToleranceWindow::fromStrings('0e-10', '0.0e5');

        self::assertSame('0.000000000000000000', $window3->minimum());
        self::assertSame('0.000000000000000000', $window3->maximum());
    }

    public function test_wide_tolerance_window(): void
    {
        // Test very wide tolerance window approaching upper bound (1.0)
        $window = ToleranceWindow::fromStrings('0.0', '0.999999999999999999');

        self::assertSame('0.000000000000000000', $window->minimum());
        self::assertSame('0.999999999999999999', $window->maximum());
        self::assertSame('0.999999999999999999', $window->heuristicTolerance());
        self::assertSame('maximum', $window->heuristicSource());

        // Test with small minimum and near-1 maximum
        $window2 = ToleranceWindow::fromStrings('0.000000000000000001', '0.999999999999999999');

        self::assertSame('0.000000000000000001', $window2->minimum());
        self::assertSame('0.999999999999999999', $window2->maximum());

        // Test maximum at the edge (one less than 1.0 at scale 18)
        $window3 = ToleranceWindow::fromStrings('0.5', '0.999999999999999999');

        self::assertSame('0.500000000000000000', $window3->minimum());
        self::assertSame('0.999999999999999999', $window3->maximum());
    }

    public function test_min_equals_max(): void
    {
        // Test when min equals max (single point tolerance)
        $window = ToleranceWindow::fromStrings('0.5', '0.5');

        self::assertSame('0.500000000000000000', $window->minimum());
        self::assertSame('0.500000000000000000', $window->maximum());
        self::assertSame('0.500000000000000000', $window->heuristicTolerance());
        self::assertSame('minimum', $window->heuristicSource());

        // Test with small equal values
        $window2 = ToleranceWindow::fromStrings('0.001', '0.001');

        self::assertSame('0.001000000000000000', $window2->minimum());
        self::assertSame('0.001000000000000000', $window2->maximum());
        self::assertSame('minimum', $window2->heuristicSource());

        // Test with high precision equal values
        $window3 = ToleranceWindow::fromStrings(
            '0.123456789012345678',
            '0.123456789012345678'
        );

        self::assertSame('0.123456789012345678', $window3->minimum());
        self::assertSame('0.123456789012345678', $window3->maximum());
        self::assertSame('0.123456789012345678', $window3->heuristicTolerance());
        self::assertSame('minimum', $window3->heuristicSource());

        // Test that min = max uses 'minimum' for heuristic source
        self::assertSame('minimum', $window->heuristicSource());
        self::assertSame('minimum', $window2->heuristicSource());
        self::assertSame('minimum', $window3->heuristicSource());
    }

    public function test_min_greater_than_max_throws_exception(): void
    {
        // Test that min > max is rejected with proper error
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Minimum tolerance must be less than or equal to maximum tolerance.');

        ToleranceWindow::fromStrings('0.6', '0.4');
    }

    public function test_min_greater_than_max_various_scenarios(): void
    {
        // Test min > max with small difference
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Minimum tolerance must be less than or equal to maximum tolerance.');

        ToleranceWindow::fromStrings('0.501', '0.5');
    }

    public function test_min_greater_than_max_at_extremes(): void
    {
        // Test min > max at extreme values
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Minimum tolerance must be less than or equal to maximum tolerance.');

        ToleranceWindow::fromStrings('0.999999999999999999', '0.000000000000000001');
    }

    public function test_boundary_at_upper_limit(): void
    {
        // Test values very close to 1.0 but still valid
        $window = ToleranceWindow::fromStrings('0.999999999999999998', '0.999999999999999999');

        self::assertSame('0.999999999999999998', $window->minimum());
        self::assertSame('0.999999999999999999', $window->maximum());
        self::assertSame('0.999999999999999999', $window->heuristicTolerance());
        self::assertSame('maximum', $window->heuristicSource());
    }

    public function test_boundary_just_below_one(): void
    {
        // Test that 1.0 minus smallest representable value is valid
        // At scale 18, the smallest increment is 0.000000000000000001
        // So 1.0 - 0.000000000000000001 = 0.999999999999999999
        $window = ToleranceWindow::fromStrings('0.0', '0.999999999999999999');

        self::assertSame('0.999999999999999999', $window->maximum());

        // Verify that adding one more would exceed 1.0 and be rejected
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Maximum tolerance must be in the [0, 1) range.');

        // This would round to 1.0 at scale 18
        ToleranceWindow::fromStrings('0.0', '1.0');
    }

    public function test_very_narrow_window(): void
    {
        // Test very narrow tolerance windows
        $window = ToleranceWindow::fromStrings(
            '0.500000000000000000',
            '0.500000000000000001'
        );

        self::assertSame('0.500000000000000000', $window->minimum());
        self::assertSame('0.500000000000000001', $window->maximum());
        self::assertSame('0.500000000000000001', $window->heuristicTolerance());
        self::assertSame('maximum', $window->heuristicSource());

        // Test at the smallest possible scale
        $window2 = ToleranceWindow::fromStrings(
            '0.000000000000000001',
            '0.000000000000000002'
        );

        self::assertSame('0.000000000000000001', $window2->minimum());
        self::assertSame('0.000000000000000002', $window2->maximum());
    }

    public function test_heuristic_source_selection(): void
    {
        // Test that heuristic source is 'maximum' when min != max
        $window1 = ToleranceWindow::fromStrings('0.1', '0.2');
        self::assertSame('maximum', $window1->heuristicSource());
        self::assertSame('0.200000000000000000', $window1->heuristicTolerance());

        // Test that heuristic source is 'minimum' when min == max
        $window2 = ToleranceWindow::fromStrings('0.15', '0.15');
        self::assertSame('minimum', $window2->heuristicSource());
        self::assertSame('0.150000000000000000', $window2->heuristicTolerance());

        // Test with very small window
        $window3 = ToleranceWindow::fromStrings('0.1', '0.100000000000000001');
        self::assertSame('maximum', $window3->heuristicSource());
        self::assertSame('0.100000000000000001', $window3->heuristicTolerance());
    }

    public function test_rounding_at_boundaries(): void
    {
        // Test that values rounding to exactly 1.0 are rejected
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Maximum tolerance must be in the [0, 1) range.');

        // This would round to 1.0 at scale 18
        ToleranceWindow::fromStrings('0.0', '0.9999999999999999999');
    }

    public function test_rounding_near_zero(): void
    {
        // Test that very small values round correctly
        $window = ToleranceWindow::fromStrings('0.00000000000000000049', '0.5');

        // Should round down to 0 at scale 18
        self::assertSame('0.000000000000000000', $window->minimum());
        self::assertSame('0.500000000000000000', $window->maximum());
    }

    // ==================== Input Validation Edge Cases ====================

    public function test_rejects_empty_string_minimum(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Value "" is not numeric.');

        ToleranceWindow::fromStrings('', '0.5');
    }

    public function test_rejects_empty_string_maximum(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Value "" is not numeric.');

        ToleranceWindow::fromStrings('0.1', '');
    }

    public function test_rejects_non_numeric_minimum(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Value "abc" is not numeric.');

        ToleranceWindow::fromStrings('abc', '0.5');
    }

    public function test_rejects_non_numeric_maximum(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Value "xyz" is not numeric.');

        ToleranceWindow::fromStrings('0.1', 'xyz');
    }

    public function test_rejects_malformed_decimal_minimum(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Value "1.2.3" is not numeric.');

        ToleranceWindow::fromStrings('1.2.3', '0.5');
    }

    public function test_rejects_malformed_decimal_maximum(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Value "1,5" is not numeric.');

        ToleranceWindow::fromStrings('0.1', '1,5');
    }

    public function test_rejects_string_with_invalid_characters(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Value "1.5abc" is not numeric.');

        ToleranceWindow::fromStrings('1.5abc', '0.8');
    }

    public function test_rejects_unicode_characters(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Value "0.5€" is not numeric.');

        ToleranceWindow::fromStrings('0.5€', '0.8');
    }

    public function test_handles_very_long_numeric_strings(): void
    {
        // Test with very long decimal strings (should still work if valid)
        $longDecimal = '0.'.str_repeat('1', 50);
        $window = ToleranceWindow::fromStrings($longDecimal, '0.5');

        self::assertSame('0.500000000000000000', $window->maximum());
        // The minimum should be normalized/truncated to canonical scale
        self::assertStringStartsWith('0.', $window->minimum());
    }

    public function test_rejects_extremely_long_invalid_strings(): void
    {
        // Very long non-numeric string should fail
        $longInvalid = str_repeat('a', 1000);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Value "'.$longInvalid.'" is not numeric.');

        ToleranceWindow::fromStrings($longInvalid, '0.5');
    }

    // ==================== Precision Boundary Edge Cases ====================

    public function test_values_rounding_to_exactly_zero(): void
    {
        // Test values that round to exactly 0.0 at canonical scale
        $window = ToleranceWindow::fromStrings('0.000000000000000000499', '0.1');

        self::assertSame('0.000000000000000000', $window->minimum());
        self::assertSame('0.100000000000000000', $window->maximum());
        self::assertSame('0.100000000000000000', $window->heuristicTolerance());
        self::assertSame('maximum', $window->heuristicSource());
    }

    public function test_values_rounding_to_boundary_values(): void
    {
        // Test values very close to the upper boundary that don't round to 1.0
        $window = ToleranceWindow::fromStrings('0.999999999999999998', '0.999999999999999998');

        self::assertSame('0.999999999999999998', $window->minimum());
        self::assertSame('0.999999999999999998', $window->maximum());
        self::assertSame('0.999999999999999998', $window->heuristicTolerance());
        self::assertSame('minimum', $window->heuristicSource());
    }

    public function test_normalization_preserves_small_differences(): void
    {
        // Test that small differences are preserved when they don't round away
        $window = ToleranceWindow::fromStrings(
            '0.123456789012345678',
            '0.123456789012345679'
        );

        self::assertSame('0.123456789012345678', $window->minimum());
        self::assertSame('0.123456789012345679', $window->maximum());
        self::assertSame('0.123456789012345679', $window->heuristicTolerance());
        self::assertSame('maximum', $window->heuristicSource());
    }

    public function test_normalization_eliminates_tiny_differences(): void
    {
        // Test that tiny differences get rounded away
        $window = ToleranceWindow::fromStrings(
            '0.500000000000000000',
            '0.5000000000000000004' // This rounds to the same value
        );

        self::assertSame('0.500000000000000000', $window->minimum());
        self::assertSame('0.500000000000000000', $window->maximum());
        self::assertSame('0.500000000000000000', $window->heuristicTolerance());
        self::assertSame('minimum', $window->heuristicSource());
    }

    // ==================== Comparison and Heuristic Edge Cases ====================

    public function test_heuristic_selection_with_rounded_values(): void
    {
        // Test heuristic selection when values round to become equal
        $window = ToleranceWindow::fromStrings(
            '0.333333333333333333',
            '0.3333333333333333334' // Rounds to same as minimum
        );

        self::assertSame('0.333333333333333333', $window->minimum());
        self::assertSame('0.333333333333333333', $window->maximum());
        self::assertSame('0.333333333333333333', $window->heuristicTolerance());
        self::assertSame('minimum', $window->heuristicSource());
    }

    public function test_comparison_edge_cases(): void
    {
        // Test comparison logic at the precision boundary
        $window1 = ToleranceWindow::fromStrings(
            '0.999999999999999998',
            '0.999999999999999999'
        );

        self::assertSame('maximum', $window1->heuristicSource());

        // Test when minimum rounds to be equal to maximum
        $window2 = ToleranceWindow::fromStrings(
            '0.9999999999999999985', // Rounds to 0.999999999999999999
            '0.999999999999999999'
        );

        self::assertSame('0.999999999999999999', $window2->minimum());
        self::assertSame('0.999999999999999999', $window2->maximum());
        self::assertSame('minimum', $window2->heuristicSource());
    }

    // ==================== Scale and Rounding Edge Cases ====================

    public function test_scale_edge_cases(): void
    {
        // Test that scale is always 18
        self::assertSame(18, ToleranceWindow::scale());

        // Test that all returned values have exactly 18 decimal places
        $window = ToleranceWindow::fromStrings('0.1', '0.9');

        self::assertMatchesRegularExpression('/^\d\.\d{18}$/', $window->minimum());
        self::assertMatchesRegularExpression('/^\d\.\d{18}$/', $window->maximum());
        self::assertMatchesRegularExpression('/^\d\.\d{18}$/', $window->heuristicTolerance());
    }

    public function test_rounding_at_precision_limits(): void
    {
        // Test rounding at the very edge of representable precision
        $window = ToleranceWindow::fromStrings(
            '0.000000000000000001',
            '0.999999999999999999'
        );

        // Values should be preserved exactly
        self::assertSame('0.000000000000000001', $window->minimum());
        self::assertSame('0.999999999999999999', $window->maximum());
    }

    // ==================== Invariant Verification ====================

    public function test_class_invariants_are_maintained(): void
    {
        // Test with different bounds to verify invariants
        $testCases = [
            ['0.0', '0.0'],
            ['0.1', '0.1'],
            ['0.123456789012345678', '0.987654321098765432'],
        ];

        foreach ($testCases as [$min, $max]) {
            $window = ToleranceWindow::fromStrings($min, $max);

            // Invariant: 0 <= minimum < 1
            self::assertGreaterThanOrEqual(0, strcmp($window->minimum(), '0.000000000000000000'));
            self::assertGreaterThan(0, strcmp('1.000000000000000000', $window->minimum()));

            // Invariant: 0 <= maximum < 1
            self::assertGreaterThanOrEqual(0, strcmp($window->maximum(), '0.000000000000000000'));
            self::assertGreaterThan(0, strcmp('1.000000000000000000', $window->maximum()));

            // Invariant: minimum <= maximum
            self::assertGreaterThanOrEqual(0, strcmp($window->maximum(), $window->minimum()));

            // Invariant: scale = 18
            self::assertSame(18, ToleranceWindow::scale());

            // Invariant: heuristicTolerance = (min == max) ? min : max
            if (0 === strcmp($window->minimum(), $window->maximum())) {
                self::assertSame($window->minimum(), $window->heuristicTolerance());
                self::assertSame('minimum', $window->heuristicSource());
            } else {
                self::assertSame($window->maximum(), $window->heuristicTolerance());
                self::assertSame('maximum', $window->heuristicSource());
            }
        }
    }

    public function test_all_values_have_correct_format(): void
    {
        $window = ToleranceWindow::fromStrings('0.001', '0.999999999999999999');

        // All returned strings should be numeric strings with exactly 18 decimal places
        self::assertMatchesRegularExpression('/^\d\.\d{18}$/', $window->minimum());
        self::assertMatchesRegularExpression('/^\d\.\d{18}$/', $window->maximum());
        self::assertMatchesRegularExpression('/^\d\.\d{18}$/', $window->heuristicTolerance());

        // Heuristic source should be either 'minimum' or 'maximum'
        self::assertContains($window->heuristicSource(), ['minimum', 'maximum']);
    }

    // ==================== Property-Based Tests ====================

    /**
     * @param numeric-string $ratio
     */
    #[DataProvider('provideToleranceRatios')]
    public function test_normalize_tolerance_property_based(string $ratio, int $scale): void
    {
        // Skip values that would be >= 1.0 as they're invalid for tolerance
        if ((float) $ratio >= 1.0) {
            $this->expectException(InvalidInput::class);
            ToleranceWindow::normalizeTolerance($ratio, 'Test tolerance');

            return;
        }

        $normalized = ToleranceWindow::normalizeTolerance($ratio, 'Test tolerance');

        // Normalized value should always have exactly 18 decimal places
        self::assertMatchesRegularExpression('/^\d\.\d{18}$/', $normalized);

        // Normalized value should be in [0, 1) range
        self::assertGreaterThanOrEqual('0.000000000000000000', $normalized);
        self::assertLessThan('1.000000000000000000', $normalized);
    }

    /**
     * @param numeric-string $minRatio
     * @param numeric-string $maxRatio
     */
    #[DataProvider('provideValidTolerancePairs')]
    public function test_from_strings_property_based(string $minRatio, string $maxRatio): void
    {
        $window = ToleranceWindow::fromStrings($minRatio, $maxRatio);

        // Verify all returned values have correct format
        self::assertMatchesRegularExpression('/^\d\.\d{18}$/', $window->minimum());
        self::assertMatchesRegularExpression('/^\d\.\d{18}$/', $window->maximum());
        self::assertMatchesRegularExpression('/^\d\.\d{18}$/', $window->heuristicTolerance());

        // Verify bounds using string comparison (precision-safe)
        // minimum >= 0.0
        self::assertGreaterThanOrEqual(0, strcmp($window->minimum(), '0.000000000000000000'));
        // minimum < 1.0
        self::assertGreaterThan(0, strcmp('1.000000000000000000', $window->minimum()));
        // maximum >= 0.0
        self::assertGreaterThanOrEqual(0, strcmp($window->maximum(), '0.000000000000000000'));
        // maximum < 1.0
        self::assertGreaterThan(0, strcmp('1.000000000000000000', $window->maximum()));
        // minimum <= maximum
        self::assertGreaterThanOrEqual(0, strcmp($window->maximum(), $window->minimum()));

        // Verify heuristic logic
        if (0 === strcmp($window->minimum(), $window->maximum())) {
            self::assertSame($window->minimum(), $window->heuristicTolerance());
            self::assertSame('minimum', $window->heuristicSource());
        } else {
            self::assertSame($window->maximum(), $window->heuristicTolerance());
            self::assertSame('maximum', $window->heuristicSource());
        }
    }

    // ==================== Data Providers ====================

    /**
     * @return iterable<string, array{numeric-string, int}>
     */
    public static function provideToleranceRatios(): iterable
    {
        $case = 0;

        foreach (NumericStringGenerator::toleranceRatios(16) as [$ratio, $scale]) {
            // Only yield valid tolerance ratios (< 1.0)
            if ((float) $ratio < 1.0) {
                yield sprintf('tolerance-ratio-%d', $case++) => [$ratio, $scale];
            }
        }
    }

    /**
     * @throws RandomException
     *
     * @return iterable<string, array{numeric-string, numeric-string}>
     */
    public static function provideValidTolerancePairs(): iterable
    {
        $case = 0;

        // Generate valid tolerance pairs manually since NumericStringGenerator doesn't guarantee valid ranges
        $validPairs = [
            ['0.0', '0.0'],
            ['0.0', '0.1'],
            ['0.0', '0.999999999999999999'],
            ['0.1', '0.1'],
            ['0.1', '0.2'],
            ['0.5', '0.5'],
            ['0.5', '0.8'],
            ['0.999999999999999998', '0.999999999999999999'],
            ['0.000000000000000001', '0.000000000000000002'],
            ['0.123456789012345678', '0.987654321098765432'],
        ];

        foreach ($validPairs as [$min, $max]) {
            yield sprintf('tolerance-pair-%d', $case++) => [$min, $max];
        }

        // Add a few more random valid pairs ensuring min <= max
        for ($i = 0; $i < 8; ++$i) {
            $minValue = random_int(0, 999999999999999999);
            $maxValue = random_int($minValue, 999999999999999999); // Ensure max >= min
            $min = sprintf('0.%018d', $minValue);
            $max = sprintf('0.%018d', $maxValue);
            yield sprintf('random-pair-%d', $i) => [$min, $max];
        }
    }
}
