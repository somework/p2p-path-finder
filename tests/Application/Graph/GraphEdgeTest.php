<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Graph;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Graph\EdgeCapacity;
use SomeWork\P2PPathFinder\Application\Graph\EdgeSegment;
use SomeWork\P2PPathFinder\Application\Graph\GraphEdge;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

use function array_column;

final class GraphEdgeTest extends TestCase
{
    public function test_it_rejects_non_edge_segment(): void
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

        $edge = fn (array $segments): GraphEdge => new GraphEdge(
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
            segments: $segments,
        );

        $validSegment = new EdgeSegment(
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
        );

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Graph edge segments must be instances of EdgeSegment.');

        $edge([$validSegment, 'not-a-segment']);
    }

    public function test_it_serializes_optional_and_mandatory_segments(): void
    {
        $order = OrderFactory::buy(
            base: 'USD',
            quote: 'EUR',
            minAmount: '2.000',
            maxAmount: '6.000',
            rate: '0.950',
            amountScale: 3,
            rateScale: 3,
        );

        $optionalSegment = new EdgeSegment(
            false,
            new EdgeCapacity(
                Money::fromString('USD', '2.000', 3),
                Money::fromString('USD', '4.000', 3),
            ),
            new EdgeCapacity(
                Money::fromString('EUR', '1.900', 3),
                Money::fromString('EUR', '3.800', 3),
            ),
            new EdgeCapacity(
                Money::fromString('USD', '2.000', 3),
                Money::fromString('USD', '4.000', 3),
            ),
        );

        $mandatorySegment = new EdgeSegment(
            true,
            new EdgeCapacity(
                Money::fromString('USD', '4.000', 3),
                Money::fromString('USD', '6.000', 3),
            ),
            new EdgeCapacity(
                Money::fromString('EUR', '3.800', 3),
                Money::fromString('EUR', '5.700', 3),
            ),
            new EdgeCapacity(
                Money::fromString('USD', '4.000', 3),
                Money::fromString('USD', '6.000', 3),
            ),
        );

        $edge = new GraphEdge(
            from: 'EUR',
            to: 'USD',
            orderSide: OrderSide::BUY,
            order: $order,
            rate: $order->effectiveRate(),
            baseCapacity: new EdgeCapacity(
                Money::fromString('EUR', '1.900', 3),
                Money::fromString('EUR', '5.700', 3),
            ),
            quoteCapacity: new EdgeCapacity(
                Money::fromString('USD', '2.000', 3),
                Money::fromString('USD', '6.000', 3),
            ),
            grossBaseCapacity: new EdgeCapacity(
                Money::fromString('EUR', '1.900', 3),
                Money::fromString('EUR', '5.700', 3),
            ),
            segments: [$optionalSegment, $mandatorySegment],
        );

        $serialized = $edge->jsonSerialize();

        self::assertSame([false, true], array_column($serialized['segments'], 'isMandatory'));
        self::assertCount(2, $serialized['segments']);
    }
}
