<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Graph;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Graph\SegmentCapacityTotals;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

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

    public function test_it_allows_negative_optional_headroom_when_mandatory_exceeds_maximum(): void
    {
        $mandatory = Money::fromString('USD', '50', 2);
        $maximum = Money::fromString('USD', '50', 2);

        $totals = new SegmentCapacityTotals($mandatory, $maximum);

        self::assertTrue($totals->optionalHeadroom()->equals(Money::fromString('USD', '0', 2)));
    }
}
