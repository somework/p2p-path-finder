<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Domain\ValueObject;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Domain\ValueObject\DecimalTolerance;

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

    public function test_compare_throws_when_negative_scale_is_provided(): void
    {
        $tolerance = DecimalTolerance::fromNumericString('0.5');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Scale must be a non-negative integer.');

        $tolerance->compare('0.4', -1);
    }

    public function test_percentage_representation_is_rounded_to_requested_scale(): void
    {
        $tolerance = DecimalTolerance::fromNumericString('0.123456789', 9);

        self::assertSame('12.345679', $tolerance->percentage(6));
        self::assertSame('12.35', $tolerance->percentage(2));
    }

    public function test_json_serialization_returns_ratio_string(): void
    {
        $tolerance = DecimalTolerance::fromNumericString('0.045');

        self::assertSame('0.045000000000000000', $tolerance->jsonSerialize());
    }

    public function test_from_numeric_string_rejects_out_of_range_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Residual tolerance must be a value between 0 and 1 inclusive.');

        DecimalTolerance::fromNumericString('1.2');
    }

    public function test_from_numeric_string_rejects_negative_scale(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Scale must be a non-negative integer.');

        DecimalTolerance::fromNumericString('0.5', -1);
    }
}
