<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Graph;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Graph\EdgeCapacity;
use SomeWork\P2PPathFinder\Application\Graph\EdgeSegment;
use SomeWork\P2PPathFinder\Application\Graph\GraphEdge;
use SomeWork\P2PPathFinder\Application\Graph\GraphEdgeCollection;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

final class GraphEdgeCollectionTest extends TestCase
{
    public function test_from_array_accepts_empty_list(): void
    {
        $collection = GraphEdgeCollection::fromArray([]);

        self::assertTrue($collection->isEmpty());
        self::assertNull($collection->originCurrency());
    }

    public function test_offset_get_rejects_non_integer_offsets(): void
    {
        $edge = $this->createEdge();
        $collection = GraphEdgeCollection::fromArray([$edge]);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Graph edge index must reference an existing position.');

        $collection['invalid'];
    }

    public function test_offset_get_rejects_out_of_bounds_index(): void
    {
        $edge = $this->createEdge();
        $collection = GraphEdgeCollection::fromArray([$edge]);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Graph edge index must reference an existing position.');

        $collection[1];
    }

    private function createEdge(): GraphEdge
    {
        $order = OrderFactory::sell(
            base: 'USD',
            quote: 'EUR',
            minAmount: '1.000',
            maxAmount: '5.000',
            rate: '0.900',
            amountScale: 3,
            rateScale: 3,
        );

        return new GraphEdge(
            from: 'USD',
            to: 'EUR',
            orderSide: OrderSide::SELL,
            order: $order,
            rate: $order->effectiveRate(),
            baseCapacity: new EdgeCapacity(
                Money::fromString('USD', '1.000', 3),
                Money::fromString('USD', '5.000', 3),
            ),
            quoteCapacity: new EdgeCapacity(
                Money::fromString('EUR', '0.900', 3),
                Money::fromString('EUR', '4.500', 3),
            ),
            grossBaseCapacity: new EdgeCapacity(
                Money::fromString('USD', '1.000', 3),
                Money::fromString('USD', '5.000', 3),
            ),
            segments: [
                new EdgeSegment(
                    true,
                    new EdgeCapacity(
                        Money::fromString('USD', '1.000', 3),
                        Money::fromString('USD', '3.000', 3),
                    ),
                    new EdgeCapacity(
                        Money::fromString('EUR', '0.900', 3),
                        Money::fromString('EUR', '2.700', 3),
                    ),
                    new EdgeCapacity(
                        Money::fromString('USD', '1.000', 3),
                        Money::fromString('USD', '3.000', 3),
                    ),
                ),
            ],
        );
    }
}
