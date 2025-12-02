<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Model\Graph;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\EdgeCapacity;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\EdgeSegment;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\GraphEdge;
use SomeWork\P2PPathFinder\Domain\Money\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

#[CoversClass(GraphEdge::class)]
final class GraphEdgeTest extends TestCase
{
    public function test_it_rejects_non_edge_segment(): void
    {
        $edge = $this->createSellEdgeFactory();

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

    public function test_it_rejects_non_list_segment_payloads(): void
    {
        $edge = $this->createSellEdgeFactory();

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
        $this->expectExceptionMessage('Graph edge segments must be provided as a list.');

        $edge(['segment' => $validSegment]);
    }

    public function test_it_accepts_empty_segment_payloads(): void
    {
        $edge = $this->createSellEdgeFactory();

        $graphEdge = $edge([]);

        self::assertSame([], $graphEdge->segments());
    }

    public function test_accessors_expose_edge_metadata(): void
    {
        $fixture = $this->createEdgeFixture();
        $edge = $fixture['edge'];

        self::assertSame('USD', $edge->from());
        self::assertSame('EUR', $edge->to());
        self::assertSame(OrderSide::SELL, $edge->orderSide());
        self::assertSame($fixture['order'], $edge->order());
        self::assertSame($fixture['rate'], $edge->rate());

        $baseCapacity = $edge->baseCapacity();
        self::assertTrue($baseCapacity->min()->equals($fixture['baseCapacity']->min()));
        self::assertTrue($baseCapacity->max()->equals($fixture['baseCapacity']->max()));

        $quoteCapacity = $edge->quoteCapacity();
        self::assertTrue($quoteCapacity->min()->equals($fixture['quoteCapacity']->min()));
        self::assertTrue($quoteCapacity->max()->equals($fixture['quoteCapacity']->max()));

        $grossBaseCapacity = $edge->grossBaseCapacity();
        self::assertTrue($grossBaseCapacity->min()->equals($fixture['grossBaseCapacity']->min()));
        self::assertTrue($grossBaseCapacity->max()->equals($fixture['grossBaseCapacity']->max()));

        self::assertSame([
            $fixture['segment'],
        ], $edge->segmentCollection()->toArray());
    }

    public function test_segment_collection_exposes_edge_segments(): void
    {
        $fixture = $this->createEdgeFixture();
        $edge = $fixture['edge'];

        self::assertSame([$fixture['segment']], $edge->segmentCollection()->toArray());
    }

    public function test_segments_returns_list_of_edge_segments(): void
    {
        $fixture = $this->createEdgeFixture();
        $edge = $fixture['edge'];

        $segments = $edge->segments();
        self::assertIsArray($segments);
        self::assertCount(1, $segments);
        self::assertSame($fixture['segment'], $segments[0]);
    }

    public function test_get_iterator_implements_iterator_aggregate(): void
    {
        $fixture = $this->createEdgeFixture();
        $edge = $fixture['edge'];

        $iterator = $edge->getIterator();
        self::assertInstanceOf(\Traversable::class, $iterator);

        $segments = [];
        foreach ($iterator as $segment) {
            $segments[] = $segment;
        }

        self::assertSame([$fixture['segment']], $segments);
    }

    public function test_from_returns_origin_currency(): void
    {
        $fixture = $this->createEdgeFixture();
        $edge = $fixture['edge'];

        self::assertSame('USD', $edge->from());
    }

    public function test_to_returns_destination_currency(): void
    {
        $fixture = $this->createEdgeFixture();
        $edge = $fixture['edge'];

        self::assertSame('EUR', $edge->to());
    }

    public function test_order_side_returns_order_side(): void
    {
        $fixture = $this->createEdgeFixture();
        $edge = $fixture['edge'];

        self::assertSame(OrderSide::SELL, $edge->orderSide());
    }

    public function test_order_returns_order(): void
    {
        $fixture = $this->createEdgeFixture();
        $edge = $fixture['edge'];

        self::assertSame($fixture['order'], $edge->order());
    }

    public function test_rate_returns_exchange_rate(): void
    {
        $fixture = $this->createEdgeFixture();
        $edge = $fixture['edge'];

        self::assertSame($fixture['rate'], $edge->rate());
    }

    public function test_base_capacity_returns_base_capacity(): void
    {
        $fixture = $this->createEdgeFixture();
        $edge = $fixture['edge'];

        $baseCapacity = $edge->baseCapacity();
        self::assertTrue($baseCapacity->min()->equals($fixture['baseCapacity']->min()));
        self::assertTrue($baseCapacity->max()->equals($fixture['baseCapacity']->max()));
    }

    public function test_quote_capacity_returns_quote_capacity(): void
    {
        $fixture = $this->createEdgeFixture();
        $edge = $fixture['edge'];

        $quoteCapacity = $edge->quoteCapacity();
        self::assertTrue($quoteCapacity->min()->equals($fixture['quoteCapacity']->min()));
        self::assertTrue($quoteCapacity->max()->equals($fixture['quoteCapacity']->max()));
    }

    public function test_gross_base_capacity_returns_gross_base_capacity(): void
    {
        $fixture = $this->createEdgeFixture();
        $edge = $fixture['edge'];

        $grossBaseCapacity = $edge->grossBaseCapacity();
        self::assertTrue($grossBaseCapacity->min()->equals($fixture['grossBaseCapacity']->min()));
        self::assertTrue($grossBaseCapacity->max()->equals($fixture['grossBaseCapacity']->max()));
    }

    public function test_edge_with_multiple_segments(): void
    {
        $order = OrderFactory::buy(
            base: 'BTC',
            quote: 'USD',
            minAmount: '1.000',
            maxAmount: '10.000',
            rate: '50000.000',
            amountScale: 3,
            rateScale: 3,
        );

        $segment1 = new EdgeSegment(
            true,
            new EdgeCapacity(Money::fromString('BTC', '1.000', 3), Money::fromString('BTC', '3.000', 3)),
            new EdgeCapacity(Money::fromString('USD', '50000.000', 3), Money::fromString('USD', '150000.000', 3)),
            new EdgeCapacity(Money::fromString('BTC', '1.000', 3), Money::fromString('BTC', '3.000', 3)),
        );

        $segment2 = new EdgeSegment(
            false,
            new EdgeCapacity(Money::fromString('BTC', '3.000', 3), Money::fromString('BTC', '10.000', 3)),
            new EdgeCapacity(Money::fromString('USD', '150000.000', 3), Money::fromString('USD', '500000.000', 3)),
            new EdgeCapacity(Money::fromString('BTC', '3.000', 3), Money::fromString('BTC', '10.000', 3)),
        );

        $edge = new GraphEdge(
            from: 'BTC',
            to: 'USD',
            orderSide: OrderSide::BUY,
            order: $order,
            rate: $order->effectiveRate(),
            baseCapacity: new EdgeCapacity(Money::fromString('BTC', '1.000', 3), Money::fromString('BTC', '10.000', 3)),
            quoteCapacity: new EdgeCapacity(Money::fromString('USD', '50000.000', 3), Money::fromString('USD', '500000.000', 3)),
            grossBaseCapacity: new EdgeCapacity(Money::fromString('BTC', '1.000', 3), Money::fromString('BTC', '10.000', 3)),
            segments: [$segment1, $segment2],
        );

        self::assertSame('BTC', $edge->from());
        self::assertSame('USD', $edge->to());
        self::assertSame(OrderSide::BUY, $edge->orderSide());
        self::assertCount(2, $edge->segments());
        self::assertSame([$segment1, $segment2], $edge->segments());
    }

    public function test_edge_with_buy_order_side(): void
    {
        $order = OrderFactory::buy(
            base: 'EUR',
            quote: 'GBP',
            minAmount: '100.000',
            maxAmount: '500.000',
            rate: '0.850',
            amountScale: 3,
            rateScale: 3,
        );

        $edge = new GraphEdge(
            from: 'EUR',
            to: 'GBP',
            orderSide: OrderSide::BUY,
            order: $order,
            rate: $order->effectiveRate(),
            baseCapacity: new EdgeCapacity(Money::fromString('EUR', '100.000', 3), Money::fromString('EUR', '500.000', 3)),
            quoteCapacity: new EdgeCapacity(Money::fromString('GBP', '85.000', 3), Money::fromString('GBP', '425.000', 3)),
            grossBaseCapacity: new EdgeCapacity(Money::fromString('EUR', '100.000', 3), Money::fromString('EUR', '500.000', 3)),
        );

        self::assertSame(OrderSide::BUY, $edge->orderSide());
        self::assertCount(0, $edge->segments());
    }

    /**
     * @return callable(array<array-key, mixed>): GraphEdge
     */
    private function createSellEdgeFactory(): callable
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

        return static fn (array $segments): GraphEdge => new GraphEdge(
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
    }

    /**
     * @return array{
     *     edge: GraphEdge,
     *     order: Order,
     *     rate: ExchangeRate,
     *     baseCapacity: EdgeCapacity,
     *     quoteCapacity: EdgeCapacity,
     *     grossBaseCapacity: EdgeCapacity,
     *     segment: EdgeSegment,
     * }
     */
    private function createEdgeFixture(): array
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
        $rate = $order->effectiveRate();

        $baseCapacity = new EdgeCapacity(
            Money::fromString('USD', '1.000', 3),
            Money::fromString('USD', '5.000', 3),
        );
        $quoteCapacity = new EdgeCapacity(
            Money::fromString('EUR', '0.900', 3),
            Money::fromString('EUR', '4.500', 3),
        );
        $grossBaseCapacity = new EdgeCapacity(
            Money::fromString('USD', '1.000', 3),
            Money::fromString('USD', '5.000', 3),
        );

        $segment = new EdgeSegment(
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

        $edge = new GraphEdge(
            from: 'USD',
            to: 'EUR',
            orderSide: OrderSide::SELL,
            order: $order,
            rate: $rate,
            baseCapacity: $baseCapacity,
            quoteCapacity: $quoteCapacity,
            grossBaseCapacity: $grossBaseCapacity,
            segments: [$segment],
        );

        return [
            'edge' => $edge,
            'order' => $order,
            'rate' => $rate,
            'baseCapacity' => $baseCapacity,
            'quoteCapacity' => $quoteCapacity,
            'grossBaseCapacity' => $grossBaseCapacity,
            'segment' => $segment,
        ];
    }
}
