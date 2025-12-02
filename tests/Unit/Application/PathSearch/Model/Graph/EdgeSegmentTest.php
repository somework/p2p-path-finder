<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Model\Graph;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\EdgeCapacity;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\EdgeSegment;
use SomeWork\P2PPathFinder\Domain\Money\Money;

#[CoversClass(EdgeSegment::class)]
final class EdgeSegmentTest extends TestCase
{
    public function test_is_mandatory_getter_returns_correct_value(): void
    {
        $mandatorySegment = new EdgeSegment(
            true,
            new EdgeCapacity(Money::fromString('USD', '1.00', 2), Money::fromString('USD', '2.00', 2)),
            new EdgeCapacity(Money::fromString('EUR', '0.90', 2), Money::fromString('EUR', '1.80', 2)),
            new EdgeCapacity(Money::fromString('USD', '1.10', 2), Money::fromString('USD', '2.20', 2)),
        );

        $optionalSegment = new EdgeSegment(
            false,
            new EdgeCapacity(Money::fromString('USD', '1.00', 2), Money::fromString('USD', '2.00', 2)),
            new EdgeCapacity(Money::fromString('EUR', '0.90', 2), Money::fromString('EUR', '1.80', 2)),
            new EdgeCapacity(Money::fromString('USD', '1.10', 2), Money::fromString('USD', '2.20', 2)),
        );

        self::assertTrue($mandatorySegment->isMandatory());
        self::assertFalse($optionalSegment->isMandatory());
    }

    public function test_base_getter_returns_correct_capacity(): void
    {
        $baseCapacity = new EdgeCapacity(
            Money::fromString('USD', '1.00', 2),
            Money::fromString('USD', '2.00', 2),
        );

        $segment = new EdgeSegment(
            true,
            $baseCapacity,
            new EdgeCapacity(Money::fromString('EUR', '0.90', 2), Money::fromString('EUR', '1.80', 2)),
            new EdgeCapacity(Money::fromString('USD', '1.10', 2), Money::fromString('USD', '2.20', 2)),
        );

        self::assertSame($baseCapacity, $segment->base());
    }

    public function test_quote_getter_returns_correct_capacity(): void
    {
        $quoteCapacity = new EdgeCapacity(
            Money::fromString('EUR', '0.90', 2),
            Money::fromString('EUR', '1.80', 2),
        );

        $segment = new EdgeSegment(
            true,
            new EdgeCapacity(Money::fromString('USD', '1.00', 2), Money::fromString('USD', '2.00', 2)),
            $quoteCapacity,
            new EdgeCapacity(Money::fromString('USD', '1.10', 2), Money::fromString('USD', '2.20', 2)),
        );

        self::assertSame($quoteCapacity, $segment->quote());
    }

    public function test_gross_base_getter_returns_correct_capacity(): void
    {
        $grossBaseCapacity = new EdgeCapacity(
            Money::fromString('USD', '1.10', 2),
            Money::fromString('USD', '2.20', 2),
        );

        $segment = new EdgeSegment(
            true,
            new EdgeCapacity(Money::fromString('USD', '1.00', 2), Money::fromString('USD', '2.00', 2)),
            new EdgeCapacity(Money::fromString('EUR', '0.90', 2), Money::fromString('EUR', '1.80', 2)),
            $grossBaseCapacity,
        );

        self::assertSame($grossBaseCapacity, $segment->grossBase());
    }

    public function test_getters_return_same_instances(): void
    {
        $baseCapacity = new EdgeCapacity(
            Money::fromString('BTC', '0.00100000', 8),
            Money::fromString('BTC', '0.00500000', 8),
        );
        $quoteCapacity = new EdgeCapacity(
            Money::fromString('USDT', '100.000000', 6),
            Money::fromString('USDT', '500.000000', 6),
        );
        $grossBaseCapacity = new EdgeCapacity(
            Money::fromString('BTC', '0.00110000', 8),
            Money::fromString('BTC', '0.00550000', 8),
        );

        $segment = new EdgeSegment(
            false,
            $baseCapacity,
            $quoteCapacity,
            $grossBaseCapacity,
        );

        self::assertSame($baseCapacity, $segment->base());
        self::assertSame($quoteCapacity, $segment->quote());
        self::assertSame($grossBaseCapacity, $segment->grossBase());
    }

    public function test_accepts_zero_capacities(): void
    {
        $zeroBase = new EdgeCapacity(
            Money::zero('USD', 2),
            Money::zero('USD', 2),
        );
        $zeroQuote = new EdgeCapacity(
            Money::zero('EUR', 2),
            Money::zero('EUR', 2),
        );
        $zeroGrossBase = new EdgeCapacity(
            Money::zero('USD', 2),
            Money::zero('USD', 2),
        );

        $segment = new EdgeSegment(false, $zeroBase, $zeroQuote, $zeroGrossBase);

        self::assertFalse($segment->isMandatory());
        self::assertSame($zeroBase, $segment->base());
        self::assertSame($zeroQuote, $segment->quote());
        self::assertSame($zeroGrossBase, $segment->grossBase());
    }

    public function test_accepts_large_capacities(): void
    {
        $largeBase = new EdgeCapacity(
            Money::fromString('BTC', '1000.00000000', 8),
            Money::fromString('BTC', '5000.00000000', 8),
        );
        $largeQuote = new EdgeCapacity(
            Money::fromString('USDT', '1000000.000000', 6),
            Money::fromString('USDT', '5000000.000000', 6),
        );
        $largeGrossBase = new EdgeCapacity(
            Money::fromString('BTC', '1100.00000000', 8),
            Money::fromString('BTC', '5500.00000000', 8),
        );

        $segment = new EdgeSegment(true, $largeBase, $largeQuote, $largeGrossBase);

        self::assertTrue($segment->isMandatory());
        self::assertSame($largeBase, $segment->base());
        self::assertSame($largeQuote, $segment->quote());
        self::assertSame($largeGrossBase, $segment->grossBase());
    }

    public function test_accepts_different_scales_across_capacities(): void
    {
        $baseCapacity = new EdgeCapacity(
            Money::fromString('ETH', '1.000000000000000000', 18),
            Money::fromString('ETH', '5.000000000000000000', 18),
        );
        $quoteCapacity = new EdgeCapacity(
            Money::fromString('USDC', '1000.00', 2),
            Money::fromString('USDC', '5000.00', 2),
        );
        $grossBaseCapacity = new EdgeCapacity(
            Money::fromString('ETH', '1.100000000000000000', 18),
            Money::fromString('ETH', '5.500000000000000000', 18),
        );

        $segment = new EdgeSegment(true, $baseCapacity, $quoteCapacity, $grossBaseCapacity);

        self::assertSame($baseCapacity, $segment->base());
        self::assertSame($quoteCapacity, $segment->quote());
        self::assertSame($grossBaseCapacity, $segment->grossBase());
    }
}
