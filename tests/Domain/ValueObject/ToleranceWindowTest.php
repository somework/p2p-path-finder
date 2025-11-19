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
}
