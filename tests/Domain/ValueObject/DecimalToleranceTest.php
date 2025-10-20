<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\DecimalTolerance;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

use function max;
use function sprintf;

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
        $normalizedInput = BcMath::normalize($ratio, $scale);

        self::assertSame($normalizedInput, $tolerance->ratio());
        self::assertSame($tolerance->ratio(), BcMath::normalize($tolerance->ratio(), $scale));
        self::assertGreaterThanOrEqual(0, BcMath::comp($tolerance->ratio(), '0', $comparisonScale));
        self::assertLessThanOrEqual(0, BcMath::comp($tolerance->ratio(), '1', $comparisonScale));
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

    public function test_json_serialization_returns_ratio_string(): void
    {
        $tolerance = DecimalTolerance::fromNumericString('0.045');

        self::assertSame('0.045000000000000000', $tolerance->jsonSerialize());
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

    public function test_from_numeric_string_requires_bcmath_extension(): void
    {
        $detector = new ReflectionProperty(BcMath::class, 'extensionDetector');
        $detector->setAccessible(true);
        $verified = new ReflectionProperty(BcMath::class, 'extensionVerified');
        $verified->setAccessible(true);

        $previousDetector = $detector->getValue();
        $previousVerified = $verified->getValue();

        $detector->setValue(null, static fn (string $extension): bool => false);
        $verified->setValue(null, false);

        $this->expectException(PrecisionViolation::class);
        $this->expectExceptionMessage('The BCMath extension (ext-bcmath) is required. Install it or require symfony/polyfill-bcmath when the extension cannot be loaded.');

        try {
            DecimalTolerance::fromNumericString('0.1');
        } finally {
            $detector->setValue(null, $previousDetector);
            $verified->setValue(null, $previousVerified);
        }
    }

    public function test_comparison_helpers_cover_both_directions(): void
    {
        $tolerance = DecimalTolerance::fromNumericString('0.25');

        self::assertTrue($tolerance->isGreaterThanOrEqual('0.249999999999999999'));
        self::assertTrue($tolerance->isLessThanOrEqual('0.25'));
        self::assertFalse($tolerance->isLessThanOrEqual('0.249'));
        self::assertFalse($tolerance->isGreaterThanOrEqual('0.251'));
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
