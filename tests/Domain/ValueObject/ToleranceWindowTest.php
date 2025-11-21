<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Domain\ValueObject\ToleranceWindow;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

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
}
