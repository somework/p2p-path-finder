<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Math;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Math\BrickDecimalMath;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

final class BrickDecimalMathTest extends TestCase
{
    private BrickDecimalMath $math;

    protected function setUp(): void
    {
        parent::setUp();

        $this->math = new BrickDecimalMath();
    }

    public function test_arithmetic_operations_follow_half_up_rounding(): void
    {
        self::assertSame('3.333333', $this->math->div('10', '3', 6));
        self::assertSame('1234567890.12345677', $this->math->add('1234567890.12345678', '-0.00000001', 8));
        self::assertSame('-51.000', $this->math->sub('-50.005', '0.995', 3));
        self::assertSame('97.408019', $this->math->mul('-12.3456', '-7.8901', 6));
    }

    public function test_round_half_up_behaviour_matches_bcmath(): void
    {
        self::assertSame('1.235', $this->math->round('1.2345', 3));
        self::assertSame('-1.235', $this->math->round('-1.2345', 3));
        self::assertSame('1', $this->math->round('0.5', 0));
        self::assertSame('-1', $this->math->round('-0.5', 0));
    }

    public function test_normalize_rounds_to_requested_scale(): void
    {
        self::assertSame('0.123456789012345679', $this->math->normalize('0.1234567890123456789', 18));
        self::assertSame('123.46', $this->math->normalize('123.456', 2));
    }

    public function test_division_by_zero_throws_exception(): void
    {
        $this->expectException(InvalidInput::class);

        $this->math->div('1', '0', 4);
    }

    public function test_scale_for_comparison_matches_highest_fractional_precision(): void
    {
        self::assertSame(4, $this->math->scaleForComparison('123.4500', '-0.000100', 2));
    }

    public function test_comparison_respects_precision(): void
    {
        self::assertSame(1, $this->math->comp('0.000123450', '0.000123449', 2));
        self::assertSame(0, $this->math->comp('5.120000', '5.12', 0));
        self::assertSame(-1, $this->math->comp('-10.0001', '-10.0000', 2));
    }
}
