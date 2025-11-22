<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SomeWork\P2PPathFinder\Application\PathFinder\PathFinder;

/**
 * Tests the tolerance amplifier calculation and its mathematical correctness.
 *
 * The tolerance amplifier is used to determine the maximum allowed cost for paths
 * during the search when a tolerance is configured. The formula is:
 *
 * - amplifier = 1 / (1 - tolerance)
 *
 * This allows paths with costs up to (bestCost * amplifier) to be explored.
 *
 * @internal
 */
#[CoversClass(PathFinder::class)]
final class ToleranceAmplifierTest extends TestCase
{
    private const SCALE = 18; // PathFinder::SCALE

    /**
     * @testdox Tolerance amplifier is 1.0 when tolerance is zero (no amplification)
     */
    public function testToleranceAmplifierWithZeroTolerance(): void
    {
        $pathFinder = new PathFinder(maxHops: 2, tolerance: '0');
        $amplifier = $this->getToleranceAmplifier($pathFinder);

        self::assertSame('1.000000000000000000', $amplifier->toScale(self::SCALE, RoundingMode::HALF_UP)->__toString());
    }

    /**
     * @testdox Tolerance amplifier calculation is mathematically correct for various tolerance values
     *
     * @param numeric-string $tolerance      Input tolerance (0 ≤ tolerance < 1)
     * @param numeric-string $expectedValue  Expected amplifier value: 1 / (1 - tolerance)
     *
     * @dataProvider provideToleranceAndExpectedAmplifiers
     */
    #[DataProvider('provideToleranceAndExpectedAmplifiers')]
    public function testToleranceAmplifierCalculation(string $tolerance, string $expectedValue): void
    {
        $pathFinder = new PathFinder(maxHops: 2, tolerance: $tolerance);
        $amplifier = $this->getToleranceAmplifier($pathFinder);

        // Verify the formula: amplifier = 1 / (1 - tolerance)
        $expected = BigDecimal::of($expectedValue);
        $actual = $amplifier;

        // Allow small rounding differences at scale 18
        $diff = $expected->minus($actual)->abs();
        $epsilon = BigDecimal::of('0.000000000100000000'); // 1e-10 (allows for repeating decimal rounding)

        self::assertTrue(
            $diff->isLessThan($epsilon) || $diff->isEqualTo($epsilon),
            sprintf(
                'Expected amplifier %s for tolerance %s, got %s (diff: %s)',
                $expected->toScale(self::SCALE, RoundingMode::HALF_UP)->__toString(),
                $tolerance,
                $actual->toScale(self::SCALE, RoundingMode::HALF_UP)->__toString(),
                $diff->toScale(self::SCALE, RoundingMode::HALF_UP)->__toString()
            )
        );
    }

    /**
     * @testdox Tolerance amplifier with medium tolerance (50%) produces correct amplification
     */
    public function testToleranceAmplifierWithMediumTolerance(): void
    {
        // tolerance = 0.5 → amplifier = 1 / (1 - 0.5) = 1 / 0.5 = 2.0
        $pathFinder = new PathFinder(maxHops: 2, tolerance: '0.5');
        $amplifier = $this->getToleranceAmplifier($pathFinder);

        $expected = BigDecimal::of('2.0');
        $actual = $amplifier;

        self::assertSame(
            $expected->toScale(self::SCALE, RoundingMode::HALF_UP)->__toString(),
            $actual->toScale(self::SCALE, RoundingMode::HALF_UP)->__toString()
        );
    }

    /**
     * @testdox Tolerance amplifier with high tolerance (90%) produces large amplification
     */
    public function testToleranceAmplifierWithHighTolerance(): void
    {
        // tolerance = 0.9 → amplifier = 1 / (1 - 0.9) = 1 / 0.1 = 10.0
        $pathFinder = new PathFinder(maxHops: 2, tolerance: '0.9');
        $amplifier = $this->getToleranceAmplifier($pathFinder);

        $expected = BigDecimal::of('10.0');
        $actual = $amplifier;

        self::assertSame(
            $expected->toScale(self::SCALE, RoundingMode::HALF_UP)->__toString(),
            $actual->toScale(self::SCALE, RoundingMode::HALF_UP)->__toString()
        );
    }

    /**
     * @testdox Tolerance amplifier with near-maximum tolerance (0.999...) produces very large amplification
     */
    public function testToleranceAmplifierWithNearMaxTolerance(): void
    {
        // tolerance = 0.999999999999999999 → amplifier = 1 / (1 - 0.999999999999999999) = very large
        $tolerance = '0.999999999999999999';
        $pathFinder = new PathFinder(maxHops: 2, tolerance: $tolerance);
        $amplifier = $this->getToleranceAmplifier($pathFinder);

        // With tolerance approaching 1, amplifier should approach infinity
        // At tolerance = 0.999999999999999999, amplifier ≈ 1e18
        $expected = BigDecimal::of('1000000000000000000');
        $actual = $amplifier;

        // Just verify it's a very large number (≥ 1e18)
        self::assertTrue(
            $actual->isGreaterThan($expected) || $actual->isEqualTo($expected),
            sprintf(
                'Expected amplifier ≥ %s for tolerance %s, got %s',
                $expected->toScale(self::SCALE, RoundingMode::HALF_UP)->__toString(),
                $tolerance,
                $actual->toScale(self::SCALE, RoundingMode::HALF_UP)->__toString()
            )
        );
    }

    /**
     * @testdox Tolerance amplifier respects upper bound constraint to prevent overflow
     */
    public function testToleranceAmplifierWithUpperBoundConstraint(): void
    {
        // PathFinder clamps tolerance to toleranceUpperBound (1 - epsilon)
        // This test verifies extreme tolerance values are handled safely
        $epsilon = '0.000000000000000001';
        $maxTolerance = BigDecimal::of('1.0')
            ->minus($epsilon, self::SCALE, RoundingMode::HALF_UP)
            ->__toString();

        $pathFinder = new PathFinder(maxHops: 2, tolerance: $maxTolerance);
        $amplifier = $this->getToleranceAmplifier($pathFinder);

        // Amplifier should be finite and calculable
        self::assertFalse($amplifier->isZero());
        self::assertTrue($amplifier->isPositive());
    }

    /**
     * @testdox maxAllowedCost correctly applies amplifier to best target cost
     */
    public function testMaxAllowedCostWithAmplifier(): void
    {
        // tolerance = 0.2 → amplifier = 1 / (1 - 0.2) = 1.25
        $pathFinder = new PathFinder(maxHops: 2, tolerance: '0.2');

        $maxAllowedCostMethod = new ReflectionMethod($pathFinder, 'maxAllowedCost');
        $maxAllowedCostMethod->setAccessible(true);

        $bestCost = BigDecimal::of('100');
        $maxCost = $maxAllowedCostMethod->invoke($pathFinder, $bestCost);

        // maxCost should be 100 * 1.25 = 125
        $expected = BigDecimal::of('125');

        self::assertSame(
            $expected->toScale(self::SCALE, RoundingMode::HALF_UP)->__toString(),
            $maxCost->toScale(self::SCALE, RoundingMode::HALF_UP)->__toString()
        );
    }

    /**
     * @testdox maxAllowedCost returns best cost when tolerance is zero (no amplification)
     */
    public function testMaxAllowedCostWithZeroTolerance(): void
    {
        $pathFinder = new PathFinder(maxHops: 2, tolerance: '0');

        $maxAllowedCostMethod = new ReflectionMethod($pathFinder, 'maxAllowedCost');
        $maxAllowedCostMethod->setAccessible(true);

        $bestCost = BigDecimal::of('100');
        $maxCost = $maxAllowedCostMethod->invoke($pathFinder, $bestCost);

        // With zero tolerance, maxCost should equal bestCost
        self::assertSame(
            $bestCost->toScale(self::SCALE, RoundingMode::HALF_UP)->__toString(),
            $maxCost->toScale(self::SCALE, RoundingMode::HALF_UP)->__toString()
        );
    }

    /**
     * @testdox maxAllowedCost returns null when no best cost exists yet
     */
    public function testMaxAllowedCostWithNoBestCost(): void
    {
        $pathFinder = new PathFinder(maxHops: 2, tolerance: '0.1');

        $maxAllowedCostMethod = new ReflectionMethod($pathFinder, 'maxAllowedCost');
        $maxAllowedCostMethod->setAccessible(true);

        $maxCost = $maxAllowedCostMethod->invoke($pathFinder, null);

        // No pruning should occur when no best cost is known
        self::assertNull($maxCost);
    }

    /**
     * @return iterable<string, array{tolerance: numeric-string, expectedValue: numeric-string}>
     */
    public static function provideToleranceAndExpectedAmplifiers(): iterable
    {
        // Formula: amplifier = 1 / (1 - tolerance)

        yield 'tolerance 0% → amplifier 1.0' => [
            'tolerance' => '0.0',
            'expectedValue' => '1.0',
        ];

        yield 'tolerance 10% → amplifier 1.111...' => [
            'tolerance' => '0.1',
            'expectedValue' => '1.111111111111111111',
        ];

        yield 'tolerance 20% → amplifier 1.25' => [
            'tolerance' => '0.2',
            'expectedValue' => '1.25',
        ];

        yield 'tolerance 25% → amplifier 1.333...' => [
            'tolerance' => '0.25',
            'expectedValue' => '1.333333333333333333',
        ];

        yield 'tolerance 33.33% → amplifier 1.5' => [
            'tolerance' => '0.3333333333',
            'expectedValue' => '1.500000000000000000',
        ];

        yield 'tolerance 50% → amplifier 2.0' => [
            'tolerance' => '0.5',
            'expectedValue' => '2.0',
        ];

        yield 'tolerance 75% → amplifier 4.0' => [
            'tolerance' => '0.75',
            'expectedValue' => '4.0',
        ];

        yield 'tolerance 80% → amplifier 5.0' => [
            'tolerance' => '0.8',
            'expectedValue' => '5.0',
        ];

        yield 'tolerance 90% → amplifier 10.0' => [
            'tolerance' => '0.9',
            'expectedValue' => '10.0',
        ];

        yield 'tolerance 95% → amplifier 20.0' => [
            'tolerance' => '0.95',
            'expectedValue' => '20.0',
        ];

        yield 'tolerance 99% → amplifier 100.0' => [
            'tolerance' => '0.99',
            'expectedValue' => '100.0',
        ];

        yield 'tolerance 99.9% → amplifier 1000.0' => [
            'tolerance' => '0.999',
            'expectedValue' => '1000.0',
        ];
    }

    /**
     * Helper method to access private toleranceAmplifier property via reflection.
     */
    private function getToleranceAmplifier(PathFinder $pathFinder): BigDecimal
    {
        $reflection = new \ReflectionProperty($pathFinder, 'toleranceAmplifier');
        $reflection->setAccessible(true);

        /** @var BigDecimal $amplifier */
        $amplifier = $reflection->getValue($pathFinder);

        return $amplifier;
    }
}

