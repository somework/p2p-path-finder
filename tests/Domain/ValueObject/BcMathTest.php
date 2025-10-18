<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

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
        $this->expectException(InvalidInput::class);

        BcMath::div('1', '0', 4);
    }

    public function test_round_half_up_behaviour_for_positive_and_negative_values(): void
    {
        self::assertSame('1.235', BcMath::round('1.2345', 3));
        self::assertSame('-1.235', BcMath::round('-1.2345', 3));
        self::assertSame('1', BcMath::round('0.5', 0));
        self::assertSame('-1', BcMath::round('-0.5', 0));
        self::assertSame('3', BcMath::round('2.5', 0));
        self::assertSame('-3', BcMath::round('-2.5', 0));
    }

    public function test_normalize_rounds_and_validates_values(): void
    {
        self::assertSame('123.4568', BcMath::normalize('123.456789', 4));

        try {
            BcMath::normalize('not-a-number', 2);
            self::fail('An exception should be thrown for invalid numeric input.');
        } catch (InvalidInput $exception) {
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
        $this->expectException(InvalidInput::class);

        BcMath::ensureNumeric('123', 'abc');
    }

    public function test_operations_reject_negative_scale(): void
    {
        $this->expectException(InvalidInput::class);

        BcMath::add('1', '1', -1);
    }

    public function test_is_numeric_rejects_empty_strings(): void
    {
        self::assertFalse(BcMath::isNumeric(''));
    }

    public function test_working_scale_helpers_cover_all_private_strategies(): void
    {
        $addition = new ReflectionMethod(BcMath::class, 'workingScaleForAddition');
        $addition->setAccessible(true);
        self::assertSame(5, $addition->invoke(null, '1.234', '9.87654', 3));

        $multiplication = new ReflectionMethod(BcMath::class, 'workingScaleForMultiplication');
        $multiplication->setAccessible(true);
        self::assertSame(9, $multiplication->invoke(null, '1.23', '4.5678', 3));

        $division = new ReflectionMethod(BcMath::class, 'workingScaleForDivision');
        $division->setAccessible(true);
        self::assertSame(10, $division->invoke(null, '12.34', '0.0567', 4));

        $comparison = new ReflectionMethod(BcMath::class, 'workingScaleForComparison');
        $comparison->setAccessible(true);
        self::assertSame(4, $comparison->invoke(null, '123.4500', '-0.000100', 2));
    }

    public function test_scale_of_trims_trailing_zeroes_and_signs(): void
    {
        $method = new ReflectionMethod(BcMath::class, 'scaleOf');
        $method->setAccessible(true);

        self::assertSame(0, $method->invoke(null, '42'));
        self::assertSame(2, $method->invoke(null, '-0.0100'));
        self::assertSame(4, $method->invoke(null, '+123.4567000'));
    }

    public function test_constructor_invocation_via_reflection_provides_coverage(): void
    {
        $reflection = new \ReflectionClass(BcMath::class);
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);
        $constructor->setAccessible(true);

        $instance = $reflection->newInstanceWithoutConstructor();
        $constructor->invoke($instance);

        self::assertInstanceOf(BcMath::class, $instance);
    }
}
