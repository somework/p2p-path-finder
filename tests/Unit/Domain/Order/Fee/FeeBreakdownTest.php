<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Domain\Order\Fee;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\Fee\FeeBreakdown;

final class FeeBreakdownTest extends TestCase
{
    /**
     * Ensures FeeBreakdown::of retains simultaneous base- and quote-denominated components so callers can model dual-fee flows.
     */
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

    /**
     * Guards the convenience constructor that should collapse null components into the canonical none() instance.
     */
    public function test_of_without_components_devolves_to_none(): void
    {
        $fromNulls = FeeBreakdown::of(null, null);

        self::assertNull($fromNulls->baseFee());
        self::assertNull($fromNulls->quoteFee());
        self::assertTrue($fromNulls->isZero());
    }

    /**
     * Demonstrates the none() constructor when order flows explicitly suppress both base and quote adjustments.
     */
    public function test_none_creates_fee_breakdown_without_base_or_quote_components(): void
    {
        $breakdown = FeeBreakdown::none();

        self::assertNull($breakdown->baseFee());
        self::assertNull($breakdown->quoteFee());
        self::assertFalse($breakdown->hasBaseFee());
        self::assertFalse($breakdown->hasQuoteFee());
        self::assertTrue($breakdown->isZero());
    }

    /**
     * Captures the forBase() named constructor for integrations that only apply maker/taker fees against the asset being bought or sold.
     */
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

    /**
     * Captures the forQuote() named constructor for desks that deduct service fees from the fiat leg only.
     */
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

    /**
     * Guards against zero-value base fees slipping through and keeps policy consumers relying on hasBaseFee()/isZero() semantics.
     */
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

    /**
     * Guards against zero-value quote fees by ensuring observers still recognise the breakdown as representing “no fee”.
     */
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

    /**
     * Documents that passing zero-valued Money objects to of() is equivalent to constructing an explicit FeeBreakdown::none().
     */
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

    /**
     * Verifies merge() accumulates both fee components so downstream aggregations keep base surcharges and quote deductions aligned.
     */
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

        self::assertTrue($merged->baseFee()?->equals(Money::fromString('BTC', '0.015', 3)) ?? false);
        self::assertTrue($merged->quoteFee()?->equals(Money::fromString('USD', '10.000', 3)) ?? false);
        self::assertTrue($merged->hasBaseFee());
        self::assertTrue($merged->hasQuoteFee());
    }

    public function test_merge_adopts_missing_base_fee_from_other_breakdown(): void
    {
        $initial = FeeBreakdown::forQuote(Money::fromString('USD', '2.500', 3));
        $other = FeeBreakdown::forBase(Money::fromString('BTC', '0.010', 3));

        $merged = $initial->merge($other);

        self::assertTrue($merged->baseFee()?->equals(Money::fromString('BTC', '0.010', 3)) ?? false);
        self::assertTrue($merged->quoteFee()?->equals(Money::fromString('USD', '2.500', 3)) ?? false);
    }

    public function test_merge_adopts_missing_quote_fee_from_other_breakdown(): void
    {
        $initial = FeeBreakdown::forBase(Money::fromString('BTC', '0.004', 3));
        $other = FeeBreakdown::forQuote(Money::fromString('USD', '1.250', 3));

        $merged = $initial->merge($other);

        self::assertTrue($merged->baseFee()?->equals(Money::fromString('BTC', '0.004', 3)) ?? false);
        self::assertTrue($merged->quoteFee()?->equals(Money::fromString('USD', '1.250', 3)) ?? false);
    }
}
