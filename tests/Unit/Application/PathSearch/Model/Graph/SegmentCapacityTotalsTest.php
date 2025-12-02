<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Model\Graph;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\SegmentCapacityTotals;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

#[CoversClass(SegmentCapacityTotals::class)]
final class SegmentCapacityTotalsTest extends TestCase
{
    public function test_it_requires_mandatory_and_maximum_values_to_share_currency(): void
    {
        $mandatory = Money::fromString('USD', '10', 0);
        $maximum = Money::fromString('EUR', '20', 0);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Segment capacity totals must share the same currency.');

        new SegmentCapacityTotals($mandatory, $maximum);
    }

    public function test_it_exposes_the_provided_totals_and_calculates_optional_headroom(): void
    {
        $mandatory = Money::fromString('USD', '10', 2);
        $maximum = Money::fromString('USD', '25', 2);

        $totals = new SegmentCapacityTotals($mandatory, $maximum);

        self::assertSame($mandatory, $totals->mandatory());
        self::assertSame($maximum, $totals->maximum());
        self::assertSame(2, $totals->scale());
        self::assertTrue($totals->optionalHeadroom()->equals(Money::fromString('USD', '15', 2)));
    }

    public function test_it_calculates_headroom_using_the_mandatory_scale(): void
    {
        $mandatory = Money::fromString('USD', '10.12', 2);
        $maximum = Money::fromString('USD', '12.3456', 4);

        $totals = new SegmentCapacityTotals($mandatory, $maximum);

        self::assertSame(2, $totals->scale());
        self::assertTrue($totals->optionalHeadroom()->equals(Money::fromString('USD', '2.23', 2)));
    }

    public function test_it_prevents_mandatory_exceeding_maximum(): void
    {
        $mandatory = Money::fromString('USD', '50', 2);
        $maximum = Money::fromString('USD', '25', 2);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage(
            'Segment capacity mandatory amount (USD 50.00) cannot exceed maximum (USD 25.00)'
        );

        new SegmentCapacityTotals($mandatory, $maximum);
    }

    public function test_it_allows_equal_mandatory_and_maximum_values(): void
    {
        $mandatory = Money::fromString('USD', '50.00', 2);
        $maximum = Money::fromString('USD', '50.00', 2);

        $totals = new SegmentCapacityTotals($mandatory, $maximum);

        self::assertTrue($totals->mandatory()->equals($totals->maximum()));
        self::assertTrue($totals->optionalHeadroom()->equals(Money::zero('USD', 2)));
    }

    public function test_getters_return_same_instances(): void
    {
        $mandatory = Money::fromString('BTC', '0.00100000', 8);
        $maximum = Money::fromString('BTC', '0.00500000', 8);

        $totals = new SegmentCapacityTotals($mandatory, $maximum);

        self::assertSame($mandatory, $totals->mandatory());
        self::assertSame($maximum, $totals->maximum());
    }

    public function test_it_handles_zero_values_correctly(): void
    {
        $mandatory = Money::zero('EUR', 2);
        $maximum = Money::fromString('EUR', '100.00', 2);

        $totals = new SegmentCapacityTotals($mandatory, $maximum);

        self::assertTrue($totals->mandatory()->isZero());
        self::assertTrue($totals->maximum()->equals($maximum));
        self::assertTrue($totals->optionalHeadroom()->equals($maximum));
    }

    public function test_it_handles_large_values(): void
    {
        $mandatory = Money::fromString('BTC', '1000.00000000', 8);
        $maximum = Money::fromString('BTC', '5000.00000000', 8);

        $totals = new SegmentCapacityTotals($mandatory, $maximum);

        self::assertTrue($totals->mandatory()->equals($mandatory));
        self::assertTrue($totals->maximum()->equals($maximum));
        self::assertTrue($totals->optionalHeadroom()->equals(Money::fromString('BTC', '4000.00000000', 8)));
    }

    public function test_scale_returns_mandatory_scale(): void
    {
        $mandatory = Money::fromString('ETH', '1.000000000000000000', 18);
        $maximum = Money::fromString('ETH', '5.000000000000000000', 4); // Different scale

        $totals = new SegmentCapacityTotals($mandatory, $maximum);

        // Scale should return mandatory's scale, not maximum's
        self::assertSame(18, $totals->scale());
    }

    public function test_optional_headroom_calculates_correctly_with_different_scales(): void
    {
        $mandatory = Money::fromString('USD', '10.12', 2);
        $maximum = Money::fromString('USD', '25.3456', 4);

        $totals = new SegmentCapacityTotals($mandatory, $maximum);

        // Headroom should be calculated at mandatory's scale (2)
        self::assertTrue($totals->optionalHeadroom()->equals(Money::fromString('USD', '15.23', 2)));
        self::assertSame(2, $totals->optionalHeadroom()->scale());
    }

    public function test_it_rejects_mismatched_currencies_in_constructor(): void
    {
        $mandatory = Money::fromString('USD', '10', 2);
        $maximum = Money::fromString('EUR', '20', 2);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Segment capacity totals must share the same currency.');

        new SegmentCapacityTotals($mandatory, $maximum);
    }

    public function test_it_handles_different_currencies_with_same_code(): void
    {
        // This should work - same currency code
        $mandatory = Money::fromString('USD', '10', 2);
        $maximum = Money::fromString('USD', '20', 2);

        $totals = new SegmentCapacityTotals($mandatory, $maximum);

        self::assertSame('USD', $totals->mandatory()->currency());
        self::assertSame('USD', $totals->maximum()->currency());
    }

    public function test_optional_headroom_with_precision_edge_cases(): void
    {
        // Test precision handling in subtraction
        $mandatory = Money::fromString('USD', '10.123456', 6);
        $maximum = Money::fromString('USD', '10.123457', 6);

        $totals = new SegmentCapacityTotals($mandatory, $maximum);

        self::assertTrue($totals->optionalHeadroom()->equals(Money::fromString('USD', '0.000001', 6)));
    }

    public function test_constructor_validates_with_various_scales(): void
    {
        // Different scales but same logical values should work
        $mandatory = Money::fromString('USD', '10.00', 2);
        $maximum = Money::fromString('USD', '20.0000', 4);

        $totals = new SegmentCapacityTotals($mandatory, $maximum);

        self::assertSame(2, $totals->scale()); // Uses mandatory's scale
        self::assertTrue($totals->optionalHeadroom()->equals(Money::fromString('USD', '10.00', 2)));
    }
}
