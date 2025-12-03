<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Result;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\Path;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathHop;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathHopCollection;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Money\MoneyMap;
use SomeWork\P2PPathFinder\Domain\Tolerance\DecimalTolerance;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

#[CoversClass(Path::class)]
final class PathTest extends TestCase
{
    public function test_it_derives_totals_and_fees_from_hops(): void
    {
        $first = new PathHop(
            'USD',
            'BTC',
            Money::fromString('USD', '100.00', 2),
            Money::fromString('BTC', '0.00200000', 8),
            OrderFactory::sell('USD', 'BTC'),
            MoneyMap::fromList([Money::fromString('USD', '1.00', 2)]),
        );

        $second = new PathHop(
            'BTC',
            'ETH',
            Money::fromString('BTC', '0.00200000', 8),
            Money::fromString('ETH', '0.0300', 4),
            OrderFactory::sell('BTC', 'ETH'),
            MoneyMap::fromList([Money::fromString('BTC', '0.0001', 4)]),
        );

        $collection = PathHopCollection::fromList([$second, $first]);
        $path = new Path($collection, DecimalTolerance::fromNumericString('0.05'));

        self::assertSame($first->spent(), $path->totalSpent());
        self::assertSame($second->received(), $path->totalReceived());
        self::assertSame($collection, $path->hops());
        self::assertSame([$first, $second], $path->hopsAsArray());

        $fees = $path->feeBreakdown();
        self::assertCount(2, $fees);
        self::assertSame('1.00', $fees->get('USD')?->amount());
        self::assertSame('0.0001', $fees->get('BTC')?->amount());

        $array = $path->toArray();
        self::assertSame($collection, $array['hops']);
        self::assertSame($path->residualTolerance(), $array['residualTolerance']);
    }

    public function test_it_rejects_empty_hop_collection(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path must contain at least one hop.');

        new Path(PathHopCollection::empty(), DecimalTolerance::zero());
    }

    public function test_residual_tolerance_percentage(): void
    {
        $hop = new PathHop(
            'USD',
            'EUR',
            Money::fromString('USD', '10', 2),
            Money::fromString('EUR', '9', 2),
            OrderFactory::sell('USD', 'EUR'),
        );

        $path = new Path(PathHopCollection::fromList([$hop]), DecimalTolerance::fromNumericString('0.123456789'));

        self::assertSame('12.35', $path->residualTolerancePercentage());
        self::assertSame('12.3457', $path->residualTolerancePercentage(4));
    }
}
