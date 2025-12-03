<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Result;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathHop;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathHopCollection;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

#[CoversClass(PathHopCollection::class)]
final class PathHopCollectionTest extends TestCase
{
    public function test_it_requires_list_of_hops(): void
    {
        $hop = new PathHop(
            'usd',
            'btc',
            Money::fromString('USD', '10', 2),
            Money::fromString('BTC', '0.001', 6),
            OrderFactory::sell('USD', 'BTC'),
        );

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path hops must be provided as a list.');

        PathHopCollection::fromList(['first' => $hop]);
    }

    public function test_it_requires_path_hop_instances(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Every path hop must be an instance of PathHop.');

        PathHopCollection::fromList([
            new PathHop(
                'usd',
                'btc',
                Money::fromString('USD', '10', 2),
                Money::fromString('BTC', '0.001', 6),
                OrderFactory::sell('USD', 'BTC'),
            ),
            'invalid-hop',
        ]);
    }

    public function test_it_enforces_contiguous_routing(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path hops must form a contiguous route.');

        PathHopCollection::fromList([
            new PathHop(
                'usd',
                'btc',
                Money::fromString('USD', '10', 2),
                Money::fromString('BTC', '0.001', 6),
                OrderFactory::sell('USD', 'BTC'),
            ),
            new PathHop(
                'eth',
                'usdt',
                Money::fromString('ETH', '1', 0),
                Money::fromString('USDT', '2000', 0),
                OrderFactory::sell('ETH', 'USDT'),
            ),
        ]);
    }

    public function test_it_orders_hops_by_route(): void
    {
        $first = new PathHop(
            'usd',
            'btc',
            Money::fromString('USD', '10', 2),
            Money::fromString('BTC', '0.001', 6),
            OrderFactory::sell('USD', 'BTC'),
        );

        $second = new PathHop(
            'btc',
            'eth',
            Money::fromString('BTC', '0.001', 6),
            Money::fromString('ETH', '0.02', 4),
            OrderFactory::sell('BTC', 'ETH'),
        );

        $collection = PathHopCollection::fromList([$second, $first]);

        self::assertSame([$first, $second], $collection->all());
        self::assertSame($first, $collection->first());
        self::assertSame($second, $collection->last());
    }

    public function test_it_requires_unique_origins(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Path hops must be unique.');

        PathHopCollection::fromList([
            new PathHop(
                'usd',
                'btc',
                Money::fromString('USD', '10', 2),
                Money::fromString('BTC', '0.001', 6),
                OrderFactory::sell('USD', 'BTC'),
            ),
            new PathHop(
                'usd',
                'eth',
                Money::fromString('USD', '5', 2),
                Money::fromString('ETH', '10', 2),
                OrderFactory::sell('USD', 'ETH'),
            ),
        ]);
    }
}
