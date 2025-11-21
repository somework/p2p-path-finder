<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Domain\Order;

use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use SomeWork\P2PPathFinder\Domain\Order\FeeBreakdown;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Tests\Application\Support\Generator\ProvidesRandomizedValues;
use SomeWork\P2PPathFinder\Tests\Domain\ValueObject\MoneyAssertions;
use SomeWork\P2PPathFinder\Tests\Support\InfectionIterationLimiter;

use function count;

/**
 * Property-based tests for FeeBreakdown value object.
 */
final class FeeBreakdownPropertyTest extends TestCase
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
     * Property: Fee amounts are never negative.
     */
    public function test_fees_are_never_negative(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_FEE_BREAKDOWN_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $baseCurrency = $this->randomCurrencyCode();
            $quoteCurrency = $this->randomDistinctCurrency($baseCurrency);
            $scale = $this->randomScale();

            // Generate positive fee amounts
            $baseFee = $this->randomPositiveMoney($baseCurrency, $scale);
            $quoteFee = $this->randomPositiveMoney($quoteCurrency, $scale);

            $breakdown = FeeBreakdown::of($baseFee, $quoteFee);

            // Property: fees are non-negative (>= 0)
            $zeroBase = $this->money($baseCurrency, '0', $scale);
            $zeroQuote = $this->money($quoteCurrency, '0', $scale);

            if (null !== $breakdown->baseFee()) {
                self::assertFalse($breakdown->baseFee()->lessThan($zeroBase));
            }

            if (null !== $breakdown->quoteFee()) {
                self::assertFalse($breakdown->quoteFee()->lessThan($zeroQuote));
            }
        }
    }

    /**
     * Property: FeeBreakdown::none() always returns zero breakdown.
     */
    public function test_none_always_produces_zero_breakdown(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_FEE_BREAKDOWN_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $breakdown = FeeBreakdown::none();

            // Property: none() has no fees
            self::assertNull($breakdown->baseFee());
            self::assertNull($breakdown->quoteFee());
            self::assertFalse($breakdown->hasBaseFee());
            self::assertFalse($breakdown->hasQuoteFee());
            self::assertTrue($breakdown->isZero());
        }
    }

    /**
     * Property: FeeBreakdown with zero amounts is equivalent to none.
     */
    public function test_zero_fees_behave_like_none(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_FEE_BREAKDOWN_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $baseCurrency = $this->randomCurrencyCode();
            $quoteCurrency = $this->randomDistinctCurrency($baseCurrency);
            $scale = $this->randomScale();

            $zeroBase = $this->money($baseCurrency, '0', $scale);
            $zeroQuote = $this->money($quoteCurrency, '0', $scale);

            // Test all zero combinations
            $breakdowns = [
                FeeBreakdown::of($zeroBase, $zeroQuote),
                FeeBreakdown::forBase($zeroBase),
                FeeBreakdown::forQuote($zeroQuote),
                FeeBreakdown::of($zeroBase, null),
                FeeBreakdown::of(null, $zeroQuote),
            ];

            foreach ($breakdowns as $breakdown) {
                // Property: zero fees behave like none
                self::assertTrue($breakdown->isZero());
                self::assertFalse($breakdown->hasBaseFee());
                self::assertFalse($breakdown->hasQuoteFee());
            }
        }
    }

    /**
     * Property: Immutability - repeated access returns same values.
     */
    public function test_immutability_of_fee_values(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_FEE_BREAKDOWN_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $baseCurrency = $this->randomCurrencyCode();
            $quoteCurrency = $this->randomDistinctCurrency($baseCurrency);
            $scale = $this->randomScale();

            $baseFee = $this->randomPositiveMoney($baseCurrency, $scale);
            $quoteFee = $this->randomPositiveMoney($quoteCurrency, $scale);

            $breakdown = FeeBreakdown::of($baseFee, $quoteFee);

            // Access multiple times
            $base1 = $breakdown->baseFee();
            $base2 = $breakdown->baseFee();
            $base3 = $breakdown->baseFee();

            $quote1 = $breakdown->quoteFee();
            $quote2 = $breakdown->quoteFee();
            $quote3 = $breakdown->quoteFee();

            // Property: repeated access returns identical objects
            self::assertSame($base1, $base2);
            self::assertSame($base2, $base3);
            self::assertSame($quote1, $quote2);
            self::assertSame($quote2, $quote3);
        }
    }

    /**
     * Property: merge() is commutative when there's no overlap in fee types.
     */
    public function test_merge_is_commutative_for_disjoint_fees(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_FEE_BREAKDOWN_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $baseCurrency = $this->randomCurrencyCode();
            $quoteCurrency = $this->randomDistinctCurrency($baseCurrency);
            $scale = $this->randomScale();

            // One has only base fee, other has only quote fee
            $breakdown1 = FeeBreakdown::forBase($this->randomPositiveMoney($baseCurrency, $scale));
            $breakdown2 = FeeBreakdown::forQuote($this->randomPositiveMoney($quoteCurrency, $scale));

            $merged1 = $breakdown1->merge($breakdown2);
            $merged2 = $breakdown2->merge($breakdown1);

            // Property: merge is commutative for disjoint fees
            self::assertTrue($merged1->baseFee()?->equals($merged2->baseFee()) ?? false);
            self::assertTrue($merged1->quoteFee()?->equals($merged2->quoteFee()) ?? false);
        }
    }

    /**
     * Property: merge() is associative.
     */
    public function test_merge_is_associative(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_FEE_BREAKDOWN_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $baseCurrency = $this->randomCurrencyCode();
            $quoteCurrency = $this->randomDistinctCurrency($baseCurrency);
            $scale = $this->randomScale();

            $fee1 = FeeBreakdown::of(
                $this->randomPositiveMoney($baseCurrency, $scale),
                $this->randomPositiveMoney($quoteCurrency, $scale)
            );
            $fee2 = FeeBreakdown::of(
                $this->randomPositiveMoney($baseCurrency, $scale),
                $this->randomPositiveMoney($quoteCurrency, $scale)
            );
            $fee3 = FeeBreakdown::of(
                $this->randomPositiveMoney($baseCurrency, $scale),
                $this->randomPositiveMoney($quoteCurrency, $scale)
            );

            // (a + b) + c
            $left = $fee1->merge($fee2)->merge($fee3);

            // a + (b + c)
            $right = $fee1->merge($fee2->merge($fee3));

            // Property: (a + b) + c == a + (b + c)
            self::assertTrue($left->baseFee()?->equals($right->baseFee()) ?? false);
            self::assertTrue($left->quoteFee()?->equals($right->quoteFee()) ?? false);
        }
    }

    /**
     * Property: merge() with none() is identity.
     */
    public function test_merge_with_none_is_identity(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_FEE_BREAKDOWN_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $baseCurrency = $this->randomCurrencyCode();
            $quoteCurrency = $this->randomDistinctCurrency($baseCurrency);
            $scale = $this->randomScale();

            $breakdown = FeeBreakdown::of(
                $this->randomPositiveMoney($baseCurrency, $scale),
                $this->randomPositiveMoney($quoteCurrency, $scale)
            );

            $none = FeeBreakdown::none();

            $merged1 = $breakdown->merge($none);
            $merged2 = $none->merge($breakdown);

            // Property: x + none == x and none + x == x
            self::assertTrue($merged1->baseFee()?->equals($breakdown->baseFee()) ?? false);
            self::assertTrue($merged1->quoteFee()?->equals($breakdown->quoteFee()) ?? false);
            self::assertTrue($merged2->baseFee()?->equals($breakdown->baseFee()) ?? false);
            self::assertTrue($merged2->quoteFee()?->equals($breakdown->quoteFee()) ?? false);
        }
    }

    /**
     * Property: merge() produces cumulative fee amounts.
     */
    public function test_merge_produces_cumulative_amounts(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_FEE_BREAKDOWN_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $baseCurrency = $this->randomCurrencyCode();
            $quoteCurrency = $this->randomDistinctCurrency($baseCurrency);
            $scale = $this->randomScale();

            $baseFee1 = $this->randomPositiveMoney($baseCurrency, $scale);
            $quoteFee1 = $this->randomPositiveMoney($quoteCurrency, $scale);

            $baseFee2 = $this->randomPositiveMoney($baseCurrency, $scale);
            $quoteFee2 = $this->randomPositiveMoney($quoteCurrency, $scale);

            $breakdown1 = FeeBreakdown::of($baseFee1, $quoteFee1);
            $breakdown2 = FeeBreakdown::of($baseFee2, $quoteFee2);

            $merged = $breakdown1->merge($breakdown2);

            // Property: merged fees equal sum of individual fees
            $expectedBase = $baseFee1->add($baseFee2);
            $expectedQuote = $quoteFee1->add($quoteFee2);

            self::assertTrue($merged->baseFee()?->equals($expectedBase) ?? false);
            self::assertTrue($merged->quoteFee()?->equals($expectedQuote) ?? false);
        }
    }

    /**
     * Property: hasBaseFee/hasQuoteFee correctly reflect non-zero fees.
     */
    public function test_has_fee_predicates_reflect_non_zero_amounts(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_FEE_BREAKDOWN_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $baseCurrency = $this->randomCurrencyCode();
            $quoteCurrency = $this->randomDistinctCurrency($baseCurrency);
            $scale = $this->randomScale();

            $positiveFee = $this->randomPositiveMoney($baseCurrency, $scale);
            $zeroFee = $this->money($baseCurrency, '0', $scale);

            // Positive fee -> hasBaseFee() is true
            $withPositive = FeeBreakdown::forBase($positiveFee);
            self::assertTrue($withPositive->hasBaseFee());

            // Zero fee -> hasBaseFee() is false
            $withZero = FeeBreakdown::forBase($zeroFee);
            self::assertFalse($withZero->hasBaseFee());
        }
    }

    /**
     * Property: isZero() is true only when both fees are absent or zero.
     */
    public function test_is_zero_reflects_absence_of_non_zero_fees(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_FEE_BREAKDOWN_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $baseCurrency = $this->randomCurrencyCode();
            $quoteCurrency = $this->randomDistinctCurrency($baseCurrency);
            $scale = $this->randomScale();

            $positiveFee = $this->randomPositiveMoney($baseCurrency, $scale);
            $zeroFee = $this->money($baseCurrency, '0', $scale);

            // Only positive base fee -> not zero
            $onlyBase = FeeBreakdown::forBase($positiveFee);
            self::assertFalse($onlyBase->isZero());

            // Only positive quote fee -> not zero
            $onlyQuote = FeeBreakdown::forQuote($this->randomPositiveMoney($quoteCurrency, $scale));
            self::assertFalse($onlyQuote->isZero());

            // Both positive -> not zero
            $both = FeeBreakdown::of($positiveFee, $this->randomPositiveMoney($quoteCurrency, $scale));
            self::assertFalse($both->isZero());

            // Both zero -> is zero
            $bothZero = FeeBreakdown::of($zeroFee, $this->money($quoteCurrency, '0', $scale));
            self::assertTrue($bothZero->isZero());

            // None -> is zero
            self::assertTrue(FeeBreakdown::none()->isZero());
        }
    }

    /**
     * Property: forBase/forQuote ensure only one fee component is present.
     */
    public function test_for_base_and_for_quote_ensure_single_component(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_FEE_BREAKDOWN_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $baseCurrency = $this->randomCurrencyCode();
            $quoteCurrency = $this->randomDistinctCurrency($baseCurrency);
            $scale = $this->randomScale();

            $baseFee = $this->randomPositiveMoney($baseCurrency, $scale);
            $quoteFee = $this->randomPositiveMoney($quoteCurrency, $scale);

            // forBase -> only base fee present
            $baseOnly = FeeBreakdown::forBase($baseFee);
            self::assertNotNull($baseOnly->baseFee());
            self::assertNull($baseOnly->quoteFee());

            // forQuote -> only quote fee present
            $quoteOnly = FeeBreakdown::forQuote($quoteFee);
            self::assertNull($quoteOnly->baseFee());
            self::assertNotNull($quoteOnly->quoteFee());
        }
    }

    /**
     * Property: of() with null, null is equivalent to none().
     */
    public function test_of_null_null_equals_none(): void
    {
        $limit = $this->iterationLimit(25, 5, 'P2P_FEE_BREAKDOWN_PROPERTY_ITERATIONS');

        for ($i = 0; $i < $limit; ++$i) {
            $fromNulls = FeeBreakdown::of(null, null);
            $fromNone = FeeBreakdown::none();

            // Property: both should be zero
            self::assertTrue($fromNulls->isZero());
            self::assertTrue($fromNone->isZero());

            self::assertNull($fromNulls->baseFee());
            self::assertNull($fromNulls->quoteFee());
            self::assertNull($fromNone->baseFee());
            self::assertNull($fromNone->quoteFee());
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

    private function randomPositiveMoney(string $currency, int $scale): Money
    {
        $upperBound = min($this->safeUnitsUpperBound($scale), 1000000 * $this->powerOfTen($scale));
        $units = $this->randomizer->getInt(1, $upperBound);

        return $this->money($currency, $this->formatUnits($units, $scale), $scale);
    }

    private function randomDistinctCurrency(string $existing): string
    {
        do {
            $currency = $this->randomCurrencyCode();
        } while (strtoupper($currency) === strtoupper($existing));

        return $currency;
    }
}
