<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Domain\Money;

use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Tests\Helpers\Generator\ProvidesRandomizedValues;
use SomeWork\P2PPathFinder\Tests\Helpers\InfectionIterationLimiter;
use SomeWork\P2PPathFinder\Tests\Helpers\MoneyAssertions;

use function count;

/**
 * Property-based tests for Money value object algebraic properties.
 *
 * Tests verify fundamental algebraic invariants:
 * - Commutativity: a + b = b + a
 * - Associativity: (a + b) + c = a + (b + c)
 * - Subtraction inverse: (a + b) - b = a
 * - Identity element: a + 0 = a
 * - Multiplication properties
 */
final class MoneyPropertyTest extends TestCase
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
     * Property: Addition is commutative: a + b = b + a.
     *
     * For any two Money values a and b with the same currency,
     * a + b should equal b + a.
     */
    public function test_money_addition_is_commutative(): void
    {
        $limit = $this->iterationLimit(100, 10, 'P2P_MONEY_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $currency = $this->randomCurrencyCode();
            $scale = $this->randomScale();

            $amountA = $this->randomUnits($scale);
            $amountB = $this->randomUnits($scale);

            $a = self::money($currency, $this->formatUnits($amountA, $scale), $scale);
            $b = self::money($currency, $this->formatUnits($amountB, $scale), $scale);

            $leftToRight = $a->add($b);
            $rightToLeft = $b->add($a);

            // Property: a + b = b + a
            self::assertTrue(
                $leftToRight->equals($rightToLeft),
                "Commutativity failed: {$a->amount()} + {$b->amount()} != {$b->amount()} + {$a->amount()}"
            );
            self::assertSame($leftToRight->amount(), $rightToLeft->amount());
            self::assertSame($leftToRight->currency(), $rightToLeft->currency());
        }
    }

    /**
     * Property: Addition is associative: (a + b) + c = a + (b + c).
     *
     * For any three Money values a, b, and c with the same currency,
     * grouping doesn't matter for addition.
     */
    public function test_money_addition_is_associative(): void
    {
        $limit = $this->iterationLimit(100, 10, 'P2P_MONEY_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $currency = $this->randomCurrencyCode();
            $scale = $this->randomScale();

            $amountA = $this->randomUnits($scale);
            $amountB = $this->randomUnits($scale);
            $amountC = $this->randomUnits($scale);

            $a = self::money($currency, $this->formatUnits($amountA, $scale), $scale);
            $b = self::money($currency, $this->formatUnits($amountB, $scale), $scale);
            $c = self::money($currency, $this->formatUnits($amountC, $scale), $scale);

            $leftAssociative = $a->add($b)->add($c);
            $rightAssociative = $a->add($b->add($c));

            // Property: (a + b) + c = a + (b + c)
            self::assertTrue(
                $leftAssociative->equals($rightAssociative),
                "Associativity failed: ({$a->amount()} + {$b->amount()}) + {$c->amount()} != {$a->amount()} + ({$b->amount()} + {$c->amount()})"
            );
            self::assertSame($leftAssociative->amount(), $rightAssociative->amount());
        }
    }

    /**
     * Property: Subtraction is the inverse of addition: (a + b) - b = a.
     *
     * For any two Money values a and b with the same currency,
     * adding b to a and then subtracting b should yield a.
     */
    public function test_money_subtraction_inverse(): void
    {
        $limit = $this->iterationLimit(100, 10, 'P2P_MONEY_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $currency = $this->randomCurrencyCode();
            $scale = $this->randomScale();

            $amountA = $this->randomUnits($scale);
            $amountB = $this->randomUnits($scale);

            $a = self::money($currency, $this->formatUnits($amountA, $scale), $scale);
            $b = self::money($currency, $this->formatUnits($amountB, $scale), $scale);

            $sum = $a->add($b);
            $difference = $sum->subtract($b);

            // Property: (a + b) - b = a
            self::assertTrue(
                $difference->equals($a),
                "Subtraction inverse failed: ({$a->amount()} + {$b->amount()}) - {$b->amount()} != {$a->amount()}, got {$difference->amount()}"
            );
            self::assertSame($a->amount(), $difference->amount());
        }
    }

    /**
     * Property: Zero is the additive identity: a + 0 = a.
     *
     * Adding zero to any Money value should return an equivalent value.
     */
    public function test_zero_is_additive_identity(): void
    {
        $limit = $this->iterationLimit(100, 10, 'P2P_MONEY_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $currency = $this->randomCurrencyCode();
            $scale = $this->randomScale();

            $amount = $this->randomUnits($scale);
            $a = self::money($currency, $this->formatUnits($amount, $scale), $scale);
            $zero = Money::zero($currency, $scale);

            $result = $a->add($zero);

            // Property: a + 0 = a
            self::assertTrue(
                $result->equals($a),
                "Identity failed: {$a->amount()} + 0 != {$a->amount()}, got {$result->amount()}"
            );
            self::assertSame($a->amount(), $result->amount());
        }
    }

    /**
     * Property: Subtraction of self yields zero: a - a = 0.
     *
     * Subtracting a Money value from itself should yield zero.
     */
    public function test_subtraction_of_self_yields_zero(): void
    {
        $limit = $this->iterationLimit(100, 10, 'P2P_MONEY_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $currency = $this->randomCurrencyCode();
            $scale = $this->randomScale();

            $amount = $this->randomUnits($scale);
            $a = self::money($currency, $this->formatUnits($amount, $scale), $scale);

            $result = $a->subtract($a);

            // Property: a - a = 0
            self::assertTrue(
                $result->isZero(),
                "Self subtraction failed: {$a->amount()} - {$a->amount()} != 0, got {$result->amount()}"
            );
        }
    }

    /**
     * Property: Multiplication distributes over addition: a * (b + c) ≈ (a * b) + (a * c).
     *
     * For scalar multiplication, distribution over addition should hold approximately.
     * Note: Exact equality may not hold due to rounding in intermediate calculations,
     * but the results should be very close.
     */
    public function test_multiplication_distributes_over_addition(): void
    {
        $limit = $this->iterationLimit(100, 10, 'P2P_MONEY_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $currency = $this->randomCurrencyCode();
            $scale = $this->randomScale();

            $amountB = $this->randomUnits($scale);
            $amountC = $this->randomUnits($scale);

            $b = self::money($currency, $this->formatUnits($amountB, $scale), $scale);
            $c = self::money($currency, $this->formatUnits($amountC, $scale), $scale);

            // Use integer scalar to ensure exact distribution
            $scalar = (string) $this->randomizer->getInt(2, 10);

            $sum = $b->add($c);
            $multiplySum = $sum->multiply($scalar);

            $multiplyB = $b->multiply($scalar);
            $multiplyC = $c->multiply($scalar);
            $sumProducts = $multiplyB->add($multiplyC);

            // Property: scalar * (b + c) = (scalar * b) + (scalar * c)
            // With integer scalars, this should hold exactly
            self::assertTrue(
                $multiplySum->equals($sumProducts),
                "Distributivity failed: {$scalar} * ({$b->amount()} + {$c->amount()}) != ({$scalar} * {$b->amount()}) + ({$scalar} * {$c->amount()})"
            );
        }
    }

    /**
     * Property: Multiplication by one is identity: a * 1 = a.
     *
     * Multiplying by one should return an equivalent value.
     */
    public function test_multiplication_by_one_is_identity(): void
    {
        $limit = $this->iterationLimit(100, 10, 'P2P_MONEY_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $currency = $this->randomCurrencyCode();
            $scale = $this->randomScale();

            $amount = $this->randomUnits($scale);
            $a = self::money($currency, $this->formatUnits($amount, $scale), $scale);

            $result = $a->multiply('1');

            // Property: a * 1 = a
            self::assertTrue(
                $result->equals($a),
                "Multiplicative identity failed: {$a->amount()} * 1 != {$a->amount()}, got {$result->amount()}"
            );
        }
    }

    /**
     * Property: Multiplication by zero yields zero: a * 0 = 0.
     *
     * Multiplying any amount by zero should yield zero.
     */
    public function test_multiplication_by_zero_yields_zero(): void
    {
        $limit = $this->iterationLimit(100, 10, 'P2P_MONEY_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $currency = $this->randomCurrencyCode();
            $scale = $this->randomScale();

            $amount = $this->randomUnits($scale);
            $a = self::money($currency, $this->formatUnits($amount, $scale), $scale);

            $result = $a->multiply('0');

            // Property: a * 0 = 0
            self::assertTrue(
                $result->isZero(),
                "Zero multiplication failed: {$a->amount()} * 0 != 0, got {$result->amount()}"
            );
        }
    }

    /**
     * Property: Division is inverse of multiplication: (a * b) / b = a.
     *
     * For non-zero scalar b, multiplying by b and then dividing by b
     * should yield approximately the original value (within rounding).
     */
    public function test_division_is_inverse_of_multiplication(): void
    {
        $limit = $this->iterationLimit(100, 10, 'P2P_MONEY_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $currency = $this->randomCurrencyCode();
            $scale = $this->randomScale();

            $amount = $this->randomUnits($scale);
            $a = self::money($currency, $this->formatUnits($amount, $scale), $scale);

            // Use a small non-zero scalar
            $scalar = $this->formatUnits($this->randomizer->getInt(2, 10), 0);

            $product = $a->multiply($scalar);
            $quotient = $product->divide($scalar);

            // Property: (a * b) / b ≈ a (within rounding error)
            // We allow minor differences due to rounding in division
            self::assertTrue(
                $quotient->equals($a),
                "Division inverse failed: ({$a->amount()} * {$scalar}) / {$scalar} != {$a->amount()}, got {$quotient->amount()}"
            );
        }
    }

    /**
     * Property: Comparison is consistent with arithmetic: if a < b, then a + c < b + c.
     *
     * Adding the same value to both sides preserves ordering.
     */
    public function test_comparison_consistent_with_addition(): void
    {
        $limit = $this->iterationLimit(100, 10, 'P2P_MONEY_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $currency = $this->randomCurrencyCode();
            $scale = $this->randomScale();

            $amountA = $this->randomUnits($scale);
            $amountB = $amountA + $this->randomizer->getInt(1, 1000);
            $amountC = $this->randomUnits($scale);

            $a = self::money($currency, $this->formatUnits($amountA, $scale), $scale);
            $b = self::money($currency, $this->formatUnits($amountB, $scale), $scale);
            $c = self::money($currency, $this->formatUnits($amountC, $scale), $scale);

            // a < b by construction
            self::assertTrue($a->lessThan($b));

            $aPlusC = $a->add($c);
            $bPlusC = $b->add($c);

            // Property: if a < b, then a + c < b + c
            self::assertTrue(
                $aPlusC->lessThan($bPlusC),
                "Comparison consistency failed: if {$a->amount()} < {$b->amount()}, then {$a->amount()} + {$c->amount()} should be < {$b->amount()} + {$c->amount()}"
            );
        }
    }

    /**
     * Property: Scale normalization to higher scale preserves value equality.
     *
     * Converting to a higher scale should preserve the underlying value exactly.
     * Note: Converting to a lower scale may involve rounding, so equality is only
     * guaranteed when increasing scale.
     */
    public function test_scale_normalization_preserves_equality(): void
    {
        $limit = $this->iterationLimit(100, 10, 'P2P_MONEY_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $currency = $this->randomCurrencyCode();
            $scale1 = $this->randomizer->getInt(0, 8);
            // Always scale up to avoid rounding issues
            $scale2 = $this->randomizer->getInt($scale1, 18);

            $amount = $this->randomUnits($scale1);
            $a = self::money($currency, $this->formatUnits($amount, $scale1), $scale1);

            $rescaled = $a->withScale($scale2);

            // Property: Scaling up preserves equality
            self::assertTrue(
                $a->equals($rescaled),
                "Scale normalization failed: {$a->amount()} at scale {$scale1} != {$rescaled->amount()} at scale {$scale2}"
            );
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
}
