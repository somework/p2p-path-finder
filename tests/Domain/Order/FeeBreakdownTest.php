<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Domain\Order;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Domain\Order\FeeBreakdown;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

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
}
