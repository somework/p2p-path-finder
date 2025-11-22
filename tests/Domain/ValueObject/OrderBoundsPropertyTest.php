<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use SomeWork\P2PPathFinder\Domain\ValueObject\OrderBounds;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Application\Support\Generator\ProvidesRandomizedValues;
use SomeWork\P2PPathFinder\Tests\Support\InfectionIterationLimiter;

use function count;

/**
 * Property-based tests for OrderBounds value object.
 */
final class OrderBoundsPropertyTest extends TestCase
{
    use InfectionIterationLimiter;
    use MoneyAssertions;
    use ProvidesRandomizedValues;

    private Randomizer $randomizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->randomizer = new Randomizer(new Xoshiro256StarStar());
    }

    protected function randomizer(): Randomizer
    {
        return $this->randomizer;
    }

    /**
     * Property: For any OrderBounds, min() is always <= max().
     */
    public function test_min_is_always_less_than_or_equal_to_max(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_ORDER_BOUNDS_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $currency = $this->randomCurrencyCode();
            $scale = $this->randomScale();

            // Generate two amounts, sort them to ensure min <= max
            $amount1 = $this->randomUnits($scale);
            $amount2 = $this->randomUnits($scale);

            $minUnits = min($amount1, $amount2);
            $maxUnits = max($amount1, $amount2);

            $min = $this->money($currency, $this->formatUnits($minUnits, $scale), $scale);
            $max = $this->money($currency, $this->formatUnits($maxUnits, $scale), $scale);

            $bounds = OrderBounds::from($min, $max);

            // Invariant: min <= max
            self::assertFalse($bounds->min()->greaterThan($bounds->max()));
        }
    }

    /**
     * Property: contains() respects boundaries inclusively - value at min/max should be contained.
     */
    public function test_contains_respects_boundaries_inclusively(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_ORDER_BOUNDS_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $currency = $this->randomCurrencyCode();
            $scale = $this->randomScale();

            $minUnits = $this->randomUnits($scale);
            $maxUnits = $minUnits + $this->randomizer->getInt(100, 10000);

            $min = $this->money($currency, $this->formatUnits($minUnits, $scale), $scale);
            $max = $this->money($currency, $this->formatUnits($maxUnits, $scale), $scale);
            $bounds = OrderBounds::from($min, $max);

            // Property: min and max values are contained (inclusive)
            self::assertTrue($bounds->contains($min));
            self::assertTrue($bounds->contains($max));

            // Property: value strictly below min is not contained
            if ($minUnits > 0) {
                $belowMin = $this->money($currency, $this->formatUnits($minUnits - 1, $scale), $scale);
                self::assertFalse($bounds->contains($belowMin));
            }

            // Property: value strictly above max is not contained
            $aboveMax = $this->money($currency, $this->formatUnits($maxUnits + 1, $scale), $scale);
            self::assertFalse($bounds->contains($aboveMax));
        }
    }

    /**
     * Property: contains() returns true for any value between min and max.
     */
    public function test_contains_includes_all_values_between_bounds(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_ORDER_BOUNDS_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $currency = $this->randomCurrencyCode();
            $scale = $this->randomScale();

            $minUnits = $this->randomUnits($scale);
            $maxUnits = $minUnits + $this->randomizer->getInt(1000, 100000);

            $min = $this->money($currency, $this->formatUnits($minUnits, $scale), $scale);
            $max = $this->money($currency, $this->formatUnits($maxUnits, $scale), $scale);
            $bounds = OrderBounds::from($min, $max);

            // Test several values between min and max
            for ($j = 0; $j < 5; ++$j) {
                $midUnits = $this->randomizer->getInt($minUnits, $maxUnits);
                $mid = $this->money($currency, $this->formatUnits($midUnits, $scale), $scale);

                self::assertTrue($bounds->contains($mid));
            }
        }
    }

    /**
     * Property: Currency must match across min, max, and tested amounts.
     */
    public function test_currency_must_match_across_bounds(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_ORDER_BOUNDS_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $currency1 = $this->randomCurrencyCode();
            $currency2 = $this->randomDistinctCurrency($currency1);
            $scale = $this->randomScale();

            $min = $this->money($currency1, '10.00', $scale);
            $max = $this->money($currency1, '100.00', $scale);
            $bounds = OrderBounds::from($min, $max);

            // Property: Currency mismatch should throw on contains()
            $wrongCurrency = $this->money($currency2, '50.00', $scale);

            try {
                $bounds->contains($wrongCurrency);
                self::fail('Expected InvalidInput for currency mismatch');
            } catch (InvalidInput $e) {
                self::assertStringContainsString('currency must match', $e->getMessage());
            }

            // Property: Currency mismatch should throw on clamp()
            try {
                $bounds->clamp($wrongCurrency);
                self::fail('Expected InvalidInput for currency mismatch on clamp');
            } catch (InvalidInput $e) {
                self::assertStringContainsString('currency must match', $e->getMessage());
            }
        }
    }

    /**
     * Property: Creating bounds with mismatched currencies should always fail.
     */
    public function test_bounds_creation_requires_currency_consistency(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_ORDER_BOUNDS_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $currency1 = $this->randomCurrencyCode();
            $currency2 = $this->randomDistinctCurrency($currency1);
            $scale = $this->randomScale();

            $min = $this->money($currency1, '10.00', $scale);
            $max = $this->money($currency2, '100.00', $scale);

            try {
                OrderBounds::from($min, $max);
                self::fail('Expected InvalidInput for currency mismatch in bounds creation');
            } catch (InvalidInput $e) {
                self::assertStringContainsString('same currency', $e->getMessage());
            }
        }
    }

    /**
     * Property: Creating bounds with min > max should always fail.
     */
    public function test_bounds_creation_requires_min_less_than_or_equal_max(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_ORDER_BOUNDS_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $currency = $this->randomCurrencyCode();
            $scale = $this->randomScale();

            $amount1 = $this->randomUnits($scale);
            $amount2 = $amount1 + $this->randomizer->getInt(1, 10000);

            // Intentionally swap to make min > max
            $min = $this->money($currency, $this->formatUnits($amount2, $scale), $scale);
            $max = $this->money($currency, $this->formatUnits($amount1, $scale), $scale);

            try {
                OrderBounds::from($min, $max);
                self::fail('Expected InvalidInput for min > max');
            } catch (InvalidInput $e) {
                self::assertStringContainsString('cannot exceed the maximum', $e->getMessage());
            }
        }
    }

    /**
     * Property: clamp() always returns a value within bounds.
     */
    public function test_clamp_always_returns_value_within_bounds(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_ORDER_BOUNDS_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $currency = $this->randomCurrencyCode();
            $scale = $this->randomScale();

            $minUnits = $this->randomUnits($scale);
            $maxUnits = $minUnits + $this->randomizer->getInt(1000, 100000);

            $min = $this->money($currency, $this->formatUnits($minUnits, $scale), $scale);
            $max = $this->money($currency, $this->formatUnits($maxUnits, $scale), $scale);
            $bounds = OrderBounds::from($min, $max);

            // Test clamping values below, within, and above bounds
            $testCases = [
                max(0, $minUnits - $this->randomizer->getInt(1, 1000)), // Below
                $this->randomizer->getInt($minUnits, $maxUnits), // Within
                $maxUnits + $this->randomizer->getInt(1, 1000), // Above
            ];

            foreach ($testCases as $testUnits) {
                $testAmount = $this->money($currency, $this->formatUnits($testUnits, $scale), $scale);
                $clamped = $bounds->clamp($testAmount);

                // Property: clamped value is always within bounds
                self::assertTrue($bounds->contains($clamped));
                self::assertFalse($clamped->lessThan($bounds->min()));
                self::assertFalse($clamped->greaterThan($bounds->max()));
            }
        }
    }

    /**
     * Property: clamp() is idempotent - clamping a clamped value returns same value.
     */
    public function test_clamp_is_idempotent(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_ORDER_BOUNDS_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $currency = $this->randomCurrencyCode();
            $scale = $this->randomScale();

            $minUnits = $this->randomUnits($scale);
            $maxUnits = $minUnits + $this->randomizer->getInt(1000, 100000);

            $min = $this->money($currency, $this->formatUnits($minUnits, $scale), $scale);
            $max = $this->money($currency, $this->formatUnits($maxUnits, $scale), $scale);
            $bounds = OrderBounds::from($min, $max);

            $amount = $this->money($currency, $this->formatUnits($this->randomUnits($scale), $scale), $scale);

            $clamped1 = $bounds->clamp($amount);
            $clamped2 = $bounds->clamp($clamped1);

            // Property: clamp(clamp(x)) == clamp(x)
            self::assertTrue($clamped1->equals($clamped2));
        }
    }

    /**
     * Property: Bounds with different scales are normalized to max scale.
     */
    public function test_different_scales_normalized_to_max_scale(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_ORDER_BOUNDS_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $currency = $this->randomCurrencyCode();

            // Use different scales
            $scale1 = $this->randomizer->getInt(0, 8);
            $scale2 = $this->randomizer->getInt(9, 18);

            $min = $this->money($currency, '10.00', $scale1);
            $max = $this->money($currency, '100.00', $scale2);

            $bounds = OrderBounds::from($min, $max);

            // Property: both min and max should have the higher scale
            $expectedScale = max($scale1, $scale2);
            self::assertSame($expectedScale, $bounds->min()->scale());
            self::assertSame($expectedScale, $bounds->max()->scale());
        }
    }

    /**
     * Property: contains() handles different scales correctly through normalization.
     */
    public function test_contains_normalizes_scales_for_comparison(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_ORDER_BOUNDS_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $currency = $this->randomCurrencyCode();
            $boundsScale = $this->randomizer->getInt(2, 8);

            $min = $this->money($currency, '10.00', $boundsScale);
            $max = $this->money($currency, '100.00', $boundsScale);
            $bounds = OrderBounds::from($min, $max);

            // Test with different scale
            $testScale = $this->randomizer->getInt(0, 18);
            $testValue = $this->money($currency, '50.00', $testScale);

            // Property: contains should work regardless of scale mismatch
            self::assertTrue($bounds->contains($testValue));
        }
    }

    /**
     * Property: Equal bounds (single point) only contain exactly that value.
     */
    public function test_equal_bounds_form_single_point(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_ORDER_BOUNDS_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $currency = $this->randomCurrencyCode();
            $scale = $this->randomScale();

            $units = $this->randomUnits($scale);
            $amount = $this->money($currency, $this->formatUnits($units, $scale), $scale);

            $bounds = OrderBounds::from($amount, $amount);

            // Property: only the exact value is contained
            self::assertTrue($bounds->contains($amount));

            // Values above or below should not be contained
            if ($units > 0) {
                $below = $this->money($currency, $this->formatUnits($units - 1, $scale), $scale);
                self::assertFalse($bounds->contains($below));
            }

            $above = $this->money($currency, $this->formatUnits($units + 1, $scale), $scale);
            self::assertFalse($bounds->contains($above));
        }
    }

    private function randomScale(): int
    {
        // Bias towards common scales
        $commonScales = [0, 2, 8, 18];
        if (0 === $this->randomizer->getInt(0, 1)) {
            return $commonScales[$this->randomizer->getInt(0, count($commonScales) - 1)];
        }

        return $this->randomizer->getInt(0, 18);
    }

    private function randomUnits(int $scale): int
    {
        $upperBound = min($this->safeUnitsUpperBound($scale), 1000000 * $this->powerOfTen($scale));

        return $this->randomizer->getInt(0, $upperBound);
    }

    private function randomDistinctCurrency(string $existing): string
    {
        do {
            $currency = $this->randomCurrencyCode();
        } while (strtoupper($currency) === strtoupper($existing));

        return $currency;
    }
}
