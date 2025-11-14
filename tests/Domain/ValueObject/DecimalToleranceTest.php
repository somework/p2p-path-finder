<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Math\BrickDecimalMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\DecimalTolerance;
use SomeWork\P2PPathFinder\Domain\ValueObject\MathAdapterFactory;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;
use SomeWork\P2PPathFinder\Internal\Math\BcMathDecimalMath;

use function max;
use function sprintf;

final class DecimalToleranceTest extends TestCase
{
    public function test_it_normalizes_ratio_to_default_scale(): void
    {
        foreach (self::mathAdapters() as $adapterName => $math) {
            $tolerance = DecimalTolerance::fromNumericString('0.1', null, $math);

            self::assertSame('0.100000000000000000', $tolerance->ratio(), $adapterName);
            self::assertSame(18, $tolerance->scale(), $adapterName);
            self::assertFalse($tolerance->isZero(), $adapterName);
        }
    }

    public function test_it_preserves_precision_for_custom_scale(): void
    {
        foreach (self::mathAdapters() as $adapterName => $math) {
            $tolerance = DecimalTolerance::fromNumericString('0.12345678901234567890', 20, $math);

            self::assertSame('0.12345678901234567890', $tolerance->ratio(), $adapterName);
            self::assertSame(20, $tolerance->scale(), $adapterName);
        }
    }

    /**
     * @dataProvider provideToleranceRatios
     */
    public function test_normalized_ratio_stays_within_bounds(string $ratio, int $scale): void
    {
        foreach (self::mathAdapters() as $adapterName => $math) {
            $tolerance = DecimalTolerance::fromNumericString($ratio, $scale, $math);
            $comparisonScale = max($scale, 18);
            $normalizedInput = $math->normalize($ratio, $scale);

            self::assertSame($normalizedInput, $tolerance->ratio(), $adapterName);
            self::assertSame($tolerance->ratio(), $math->normalize($tolerance->ratio(), $scale), $adapterName);
            self::assertGreaterThanOrEqual(0, $math->comp($tolerance->ratio(), '0', $comparisonScale), $adapterName);
            self::assertLessThanOrEqual(0, $math->comp($tolerance->ratio(), '1', $comparisonScale), $adapterName);
        }
    }

    public function test_zero_factory_provides_normalized_ratio(): void
    {
        foreach (self::mathAdapters() as $adapterName => $math) {
            $zero = DecimalTolerance::zero($math);

            self::assertTrue($zero->isZero(), $adapterName);
            self::assertSame('0.000000000000000000', $zero->ratio(), $adapterName);
            self::assertSame(18, $zero->scale(), $adapterName);
        }
    }

    public function test_compare_honours_requested_scale(): void
    {
        foreach (self::mathAdapters() as $adapterName => $math) {
            $tolerance = DecimalTolerance::fromNumericString('0.125', 3, $math);

            self::assertSame(0, $tolerance->compare('0.1250', 4), $adapterName);
            self::assertGreaterThan(0, $tolerance->compare('0.1249', 4), $adapterName);
            self::assertLessThan(0, $tolerance->compare('0.1252', 4), $adapterName);
        }
    }

    public function test_compare_uses_internal_scale_when_not_provided(): void
    {
        foreach (self::mathAdapters() as $adapterName => $math) {
            $tolerance = DecimalTolerance::fromNumericString('0.333333333333333333', null, $math);

            self::assertSame(0, $tolerance->compare('0.333333333333333333'), $adapterName);
            self::assertGreaterThan(0, $tolerance->compare('0.333333333333333332'), $adapterName);
            self::assertLessThan(0, $tolerance->compare('0.333333333333333334'), $adapterName);
        }
    }

    public function test_compare_throws_when_negative_scale_is_provided(): void
    {
        foreach (self::mathAdapters() as $adapterName => $math) {
            $tolerance = DecimalTolerance::fromNumericString('0.5', null, $math);

            $this->assertInvalidInput(
                static fn () => $tolerance->compare('0.4', -1),
                $adapterName,
            );
        }
    }

    public function test_percentage_representation_is_rounded_to_requested_scale(): void
    {
        foreach (self::mathAdapters() as $adapterName => $math) {
            $tolerance = DecimalTolerance::fromNumericString('0.123456789', 9, $math);

            self::assertSame('12.345679', $tolerance->percentage(6), $adapterName);
            self::assertSame('12.35', $tolerance->percentage(2), $adapterName);
        }
    }

    public function test_percentage_throws_when_scale_is_negative(): void
    {
        foreach (self::mathAdapters() as $adapterName => $math) {
            $tolerance = DecimalTolerance::fromNumericString('0.123', null, $math);

            $this->assertInvalidInput(
                static fn () => $tolerance->percentage(-1),
                $adapterName,
            );
        }
    }

    public function test_json_serialization_returns_ratio_string(): void
    {
        foreach (self::mathAdapters() as $adapterName => $math) {
            $tolerance = DecimalTolerance::fromNumericString('0.045', null, $math);

            self::assertSame('0.045000000000000000', $tolerance->jsonSerialize(), $adapterName);
        }
    }

    public function test_from_numeric_string_rejects_out_of_range_values(): void
    {
        foreach (self::mathAdapters() as $adapterName => $math) {
            $this->assertInvalidInput(
                static fn () => DecimalTolerance::fromNumericString('1.2', null, $math),
                $adapterName,
            );
        }
    }

    public function test_from_numeric_string_accepts_boundary_values(): void
    {
        foreach (self::mathAdapters() as $adapterName => $math) {
            $zero = DecimalTolerance::fromNumericString('0', null, $math);
            $one = DecimalTolerance::fromNumericString('1', null, $math);

            self::assertTrue($zero->isZero(), $adapterName);
            self::assertTrue($one->isGreaterThanOrEqual('1'), $adapterName);
            self::assertSame('1.000000000000000000', $one->ratio(), $adapterName);
        }
    }

    public function test_from_numeric_string_rejects_negative_scale(): void
    {
        foreach (self::mathAdapters() as $adapterName => $math) {
            $this->assertInvalidInput(
                static fn () => DecimalTolerance::fromNumericString('0.5', -1, $math),
                $adapterName,
            );
        }
    }

    public function test_from_numeric_string_requires_bcmath_extension(): void
    {
        $math = new BcMathDecimalMath();
        $math->setExtensionDetector(static fn (string $extension): bool => false);

        $this->expectException(PrecisionViolation::class);
        $this->expectExceptionMessage('The BCMath extension (ext-bcmath) is required. Install it or require symfony/polyfill-bcmath when the extension cannot be loaded.');

        try {
            DecimalTolerance::fromNumericString('0.1', null, $math);
        } finally {
            $math->setExtensionDetector(null);
        }
    }

    public function test_comparison_helpers_cover_both_directions(): void
    {
        foreach (self::mathAdapters() as $adapterName => $math) {
            $tolerance = DecimalTolerance::fromNumericString('0.25', null, $math);

            self::assertTrue($tolerance->isGreaterThanOrEqual('0.249999999999999999'), $adapterName);
            self::assertTrue($tolerance->isLessThanOrEqual('0.25'), $adapterName);
            self::assertFalse($tolerance->isLessThanOrEqual('0.249'), $adapterName);
            self::assertFalse($tolerance->isGreaterThanOrEqual('0.251'), $adapterName);
        }
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

    /**
     * @return iterable<string, \SomeWork\P2PPathFinder\Domain\Math\DecimalMathInterface>
     */
    private static function mathAdapters(): iterable
    {
        yield 'bc-math' => MathAdapterFactory::default();
        yield 'brick-decimal' => new BrickDecimalMath();
    }

    /**
     * @param callable():void $callback
     */
    private function assertInvalidInput(callable $callback, string $adapterName): void
    {
        try {
            $callback();
            self::fail(sprintf('[%s] Expected InvalidInput exception to be thrown.', $adapterName));
        } catch (InvalidInput $exception) {
            self::assertInstanceOf(InvalidInput::class, $exception, $adapterName);
        }
    }
}
