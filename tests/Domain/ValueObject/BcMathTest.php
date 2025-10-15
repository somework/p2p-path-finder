<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Domain\ValueObject;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;

final class BcMathTest extends TestCase
{
    public function test_arithmetic_operations_preserve_large_precision(): void
    {
        self::assertSame('1234567890.12345677', BcMath::add('1234567890.12345678', '-0.00000001', 8));
        self::assertSame('-51.000', BcMath::sub('-50.005', '0.995', 3));
        self::assertSame('97.408019', BcMath::mul('-12.3456', '-7.8901', 6));
    }

    public function test_division_handles_negative_numbers_and_high_scale(): void
    {
        self::assertSame('-3.1250000000', BcMath::div('-10.000000000', '3.2', 10));
        self::assertSame('3.333333', BcMath::div('10', '3', 6));
    }

    public function test_division_by_zero_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BcMath::div('1', '0', 4);
    }

    public function test_round_half_up_behaviour_for_positive_and_negative_values(): void
    {
        self::assertSame('1.235', BcMath::round('1.2345', 3));
        self::assertSame('-1.235', BcMath::round('-1.2345', 3));
        self::assertSame('3', BcMath::round('2.5', 0));
        self::assertSame('-3', BcMath::round('-2.5', 0));
    }

    public function test_normalize_rounds_and_validates_values(): void
    {
        self::assertSame('123.4568', BcMath::normalize('123.456789', 4));

        try {
            BcMath::normalize('not-a-number', 2);
            self::fail('An exception should be thrown for invalid numeric input.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Value "not-a-number" is not numeric.', $exception->getMessage());
        }
    }

    public function test_comparison_respects_operand_precision_when_fallback_is_small(): void
    {
        self::assertSame(1, BcMath::comp('0.000123450', '0.000123449', 2));
        self::assertSame(0, BcMath::comp('5.120000', '5.12', 0));
        self::assertSame(-1, BcMath::comp('-10.0001', '-10.0000', 2));
    }

    public function test_scale_for_comparison_matches_highest_fractional_precision(): void
    {
        self::assertSame(4, BcMath::scaleForComparison('123.4500', '-0.000100', 2));
    }

    public function test_ensure_numeric_accepts_multiple_valid_inputs(): void
    {
        self::expectNotToPerformAssertions();

        BcMath::ensureNumeric('0', '-10.5', '123456', '0.000001');
    }

    public function test_ensure_numeric_throws_for_invalid_values(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BcMath::ensureNumeric('123', 'abc');
    }

    public function test_operations_reject_negative_scale(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BcMath::add('1', '1', -1);
    }
}
