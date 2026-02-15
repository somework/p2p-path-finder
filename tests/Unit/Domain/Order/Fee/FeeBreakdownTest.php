<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Domain\Order\Fee;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\Fee\FeeBreakdown;

#[CoversClass(FeeBreakdown::class)]
final class FeeBreakdownTest extends TestCase
{
    #[TestDox('none() creates breakdown where both fees are null and isZero is true')]
    public function test_none_creates_fee_breakdown_without_base_or_quote_components(): void
    {
        $breakdown = FeeBreakdown::none();

        self::assertNull($breakdown->baseFee());
        self::assertNull($breakdown->quoteFee());
        self::assertFalse($breakdown->hasBaseFee());
        self::assertFalse($breakdown->hasQuoteFee());
        self::assertTrue($breakdown->isZero());
    }

    #[TestDox('forBase() sets baseFee and leaves quoteFee null')]
    public function test_for_base_wraps_a_single_base_fee_and_marks_quote_fee_absent(): void
    {
        $baseFee = Money::fromString('BTC', '0.025', 3);

        $breakdown = FeeBreakdown::forBase($baseFee);

        self::assertSame($baseFee, $breakdown->baseFee());
        self::assertNull($breakdown->quoteFee());
        self::assertTrue($breakdown->hasBaseFee());
        self::assertFalse($breakdown->hasQuoteFee());
        self::assertFalse($breakdown->isZero());
    }

    #[TestDox('forQuote() sets quoteFee and leaves baseFee null')]
    public function test_for_quote_wraps_a_single_quote_fee_and_marks_base_fee_absent(): void
    {
        $quoteFee = Money::fromString('USD', '12.500', 3);

        $breakdown = FeeBreakdown::forQuote($quoteFee);

        self::assertNull($breakdown->baseFee());
        self::assertSame($quoteFee, $breakdown->quoteFee());
        self::assertFalse($breakdown->hasBaseFee());
        self::assertTrue($breakdown->hasQuoteFee());
        self::assertFalse($breakdown->isZero());
    }

    #[TestDox('of() retains both base and quote fee components')]
    public function test_of_supports_combined_base_and_quote_components(): void
    {
        $baseFee = Money::fromString('BTC', '0.010', 3);
        $quoteFee = Money::fromString('USD', '15.000', 3);

        $breakdown = FeeBreakdown::of($baseFee, $quoteFee);

        self::assertSame($baseFee, $breakdown->baseFee());
        self::assertSame($quoteFee, $breakdown->quoteFee());
        self::assertTrue($breakdown->hasBaseFee());
        self::assertTrue($breakdown->hasQuoteFee());
        self::assertFalse($breakdown->isZero());
    }

    #[TestDox('of() with both nulls behaves like none()')]
    public function test_of_without_components_devolves_to_none(): void
    {
        $fromNulls = FeeBreakdown::of(null, null);

        self::assertNull($fromNulls->baseFee());
        self::assertNull($fromNulls->quoteFee());
        self::assertTrue($fromNulls->isZero());
    }

    #[TestDox('isZero() returns true when fees are zero Money values rather than null')]
    public function test_of_zero_amounts_equate_to_explicitly_declaring_no_fees(): void
    {
        $zeroBaseFee = Money::fromString('BTC', '0', 8);
        $zeroQuoteFee = Money::fromString('USD', '0', 2);

        $breakdown = FeeBreakdown::of($zeroBaseFee, $zeroQuoteFee);

        self::assertSame($zeroBaseFee, $breakdown->baseFee());
        self::assertSame($zeroQuoteFee, $breakdown->quoteFee());
        self::assertFalse($breakdown->hasBaseFee());
        self::assertFalse($breakdown->hasQuoteFee());
        self::assertTrue($breakdown->isZero());
    }

    #[TestDox('forBase() with zero amount behaves like absent fee')]
    public function test_for_base_zero_amount_behaves_like_absent_fee_components(): void
    {
        $zeroBaseFee = Money::fromString('BTC', '0', 8);

        $breakdown = FeeBreakdown::forBase($zeroBaseFee);

        self::assertSame($zeroBaseFee, $breakdown->baseFee());
        self::assertNull($breakdown->quoteFee());
        self::assertFalse($breakdown->hasBaseFee());
        self::assertFalse($breakdown->hasQuoteFee());
        self::assertTrue($breakdown->isZero());
    }

    #[TestDox('forQuote() with zero amount behaves like absent fee')]
    public function test_for_quote_zero_amount_behaves_like_absent_fee_components(): void
    {
        $zeroQuoteFee = Money::fromString('USD', '0.000', 3);

        $breakdown = FeeBreakdown::forQuote($zeroQuoteFee);

        self::assertNull($breakdown->baseFee());
        self::assertSame($zeroQuoteFee, $breakdown->quoteFee());
        self::assertFalse($breakdown->hasBaseFee());
        self::assertFalse($breakdown->hasQuoteFee());
        self::assertTrue($breakdown->isZero());
    }

    #[TestDox('merge() sums both base and quote fees component-wise')]
    public function test_merge_accumulates_combined_base_and_quote_fees(): void
    {
        $first = FeeBreakdown::of(
            Money::fromString('BTC', '0.005', 3),
            Money::fromString('USD', '7.500', 3),
        );
        $second = FeeBreakdown::of(
            Money::fromString('BTC', '0.010', 3),
            Money::fromString('USD', '2.500', 3),
        );

        $merged = $first->merge($second);

        self::assertNotNull($merged->baseFee());
        self::assertTrue($merged->baseFee()->equals(Money::fromString('BTC', '0.015', 3)));
        self::assertNotNull($merged->quoteFee());
        self::assertTrue($merged->quoteFee()->equals(Money::fromString('USD', '10.000', 3)));
        self::assertTrue($merged->hasBaseFee());
        self::assertTrue($merged->hasQuoteFee());
    }

    #[TestDox('merge() sums base fees when both breakdowns have only base fees')]
    public function test_merge_sums_base_fees_when_both_have_base_only(): void
    {
        $first = FeeBreakdown::forBase(Money::fromString('BTC', '0.003', 3));
        $second = FeeBreakdown::forBase(Money::fromString('BTC', '0.007', 3));

        $merged = $first->merge($second);

        self::assertNotNull($merged->baseFee());
        self::assertTrue($merged->baseFee()->equals(Money::fromString('BTC', '0.010', 3)));
        self::assertNull($merged->quoteFee());
    }

    #[TestDox('merge() sums quote fees when both breakdowns have only quote fees')]
    public function test_merge_sums_quote_fees_when_both_have_quote_only(): void
    {
        $first = FeeBreakdown::forQuote(Money::fromString('USD', '3.000', 3));
        $second = FeeBreakdown::forQuote(Money::fromString('USD', '2.000', 3));

        $merged = $first->merge($second);

        self::assertNull($merged->baseFee());
        self::assertNotNull($merged->quoteFee());
        self::assertTrue($merged->quoteFee()->equals(Money::fromString('USD', '5.000', 3)));
    }

    #[TestDox('merge() adopts base fee from other when original has none')]
    public function test_merge_adopts_missing_base_fee_from_other_breakdown(): void
    {
        $initial = FeeBreakdown::forQuote(Money::fromString('USD', '2.500', 3));
        $other = FeeBreakdown::forBase(Money::fromString('BTC', '0.010', 3));

        $merged = $initial->merge($other);

        self::assertNotNull($merged->baseFee());
        self::assertTrue($merged->baseFee()->equals(Money::fromString('BTC', '0.010', 3)));
        self::assertNotNull($merged->quoteFee());
        self::assertTrue($merged->quoteFee()->equals(Money::fromString('USD', '2.500', 3)));
    }

    #[TestDox('merge() adopts quote fee from other when original has none')]
    public function test_merge_adopts_missing_quote_fee_from_other_breakdown(): void
    {
        $initial = FeeBreakdown::forBase(Money::fromString('BTC', '0.004', 3));
        $other = FeeBreakdown::forQuote(Money::fromString('USD', '1.250', 3));

        $merged = $initial->merge($other);

        self::assertNotNull($merged->baseFee());
        self::assertTrue($merged->baseFee()->equals(Money::fromString('BTC', '0.004', 3)));
        self::assertNotNull($merged->quoteFee());
        self::assertTrue($merged->quoteFee()->equals(Money::fromString('USD', '1.250', 3)));
    }

    #[TestDox('merge() with none() preserves original fees unchanged')]
    public function test_merge_with_none_preserves_original(): void
    {
        $original = FeeBreakdown::of(
            Money::fromString('BTC', '0.005', 3),
            Money::fromString('USD', '7.500', 3),
        );

        $merged = $original->merge(FeeBreakdown::none());

        self::assertNotNull($merged->baseFee());
        self::assertTrue($merged->baseFee()->equals(Money::fromString('BTC', '0.005', 3)));
        self::assertNotNull($merged->quoteFee());
        self::assertTrue($merged->quoteFee()->equals(Money::fromString('USD', '7.500', 3)));
    }

    #[TestDox('merge() of none() with fees adopts all fees from other')]
    public function test_merge_none_with_fees_adopts_other(): void
    {
        $other = FeeBreakdown::of(
            Money::fromString('BTC', '0.008', 3),
            Money::fromString('USD', '4.000', 3),
        );

        $merged = FeeBreakdown::none()->merge($other);

        self::assertNotNull($merged->baseFee());
        self::assertTrue($merged->baseFee()->equals(Money::fromString('BTC', '0.008', 3)));
        self::assertNotNull($merged->quoteFee());
        self::assertTrue($merged->quoteFee()->equals(Money::fromString('USD', '4.000', 3)));
    }
}
