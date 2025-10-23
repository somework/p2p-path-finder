<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Result;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Result\PathLeg;
use SomeWork\P2PPathFinder\Application\Result\PathLegCollection;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

final class PathLegCollectionTest extends TestCase
{
    public function test_it_orders_legs_monotonically(): void
    {
        $first = new PathLeg(
            'usd',
            'btc',
            Money::fromString('USD', '100', 2),
            Money::fromString('BTC', '2', 2),
        );
        $second = new PathLeg(
            'btc',
            'eth',
            Money::fromString('BTC', '2', 2),
            Money::fromString('ETH', '30', 2),
        );

        $collection = PathLegCollection::fromList([$second, $first]);
        $legs = $collection->toArray();

        self::assertSame('USD', $legs[0]->from());
        self::assertSame('BTC', $legs[0]->to());
        self::assertSame('BTC', $legs[1]->from());
        self::assertSame('ETH', $legs[1]->to());
    }

    public function test_it_rejects_duplicate_sources(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path legs must be unique.');

        PathLegCollection::fromList([
            new PathLeg(
                'usd',
                'btc',
                Money::fromString('USD', '1', 2),
                Money::fromString('BTC', '2', 2),
            ),
            new PathLeg(
                'usd',
                'eth',
                Money::fromString('USD', '1', 2),
                Money::fromString('ETH', '3', 2),
            ),
        ]);
    }

    public function test_it_rejects_non_monotonic_sequences(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path legs must form a monotonic sequence.');

        PathLegCollection::fromList([
            new PathLeg(
                'usd',
                'btc',
                Money::fromString('USD', '1', 2),
                Money::fromString('BTC', '2', 2),
            ),
            new PathLeg(
                'eur',
                'gbp',
                Money::fromString('EUR', '3', 2),
                Money::fromString('GBP', '4', 2),
            ),
        ]);
    }

    public function test_it_rejects_non_integer_offsets(): void
    {
        $collection = PathLegCollection::fromList([
            new PathLeg(
                'usd',
                'btc',
                Money::fromString('USD', '1', 2),
                Money::fromString('BTC', '2', 2),
            ),
        ]);

        self::assertFalse(isset($collection['0']));

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path leg index must reference an existing position.');

        $collection['0'];
    }
}
