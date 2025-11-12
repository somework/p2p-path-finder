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
}
