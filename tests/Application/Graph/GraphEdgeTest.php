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

    public function test_array_access_exposes_edge_metadata(): void
    {
        $fixture = $this->createEdgeFixture();
        $edge = $fixture['edge'];

        self::assertSame('USD', $edge['from']);
        self::assertSame('EUR', $edge['to']);
        self::assertSame(OrderSide::SELL, $edge['orderSide']);
        self::assertSame($fixture['order'], $edge['order']);
        self::assertSame($fixture['rate'], $edge['rate']);

        $baseCapacity = $edge['baseCapacity'];
        self::assertTrue($baseCapacity['min']->equals($fixture['baseCapacity']->min()));
        self::assertTrue($baseCapacity['max']->equals($fixture['baseCapacity']->max()));

        $quoteCapacity = $edge['quoteCapacity'];
        self::assertTrue($quoteCapacity['min']->equals($fixture['quoteCapacity']->min()));
        self::assertTrue($quoteCapacity['max']->equals($fixture['quoteCapacity']->max()));

        $grossBaseCapacity = $edge['grossBaseCapacity'];
        self::assertTrue($grossBaseCapacity['min']->equals($fixture['grossBaseCapacity']->min()));
        self::assertTrue($grossBaseCapacity['max']->equals($fixture['grossBaseCapacity']->max()));

        self::assertSame([
            $fixture['segment']->jsonSerialize(),
        ], $edge['segments']);
    }

    public function test_json_serialization_includes_complete_payload(): void
    {
        $fixture = $this->createEdgeFixture();
        $edge = $fixture['edge'];
        $order = $fixture['order'];

        $serialized = $edge->jsonSerialize();

        self::assertSame('USD', $serialized['from']);
        self::assertSame('EUR', $serialized['to']);
        self::assertSame(OrderSide::SELL->value, $serialized['orderSide']);

        self::assertSame(
            [
                'side' => $order->side()->value,
                'assetPair' => [
                    'base' => $order->assetPair()->base(),
                    'quote' => $order->assetPair()->quote(),
                ],
                'bounds' => [
                    'min' => [
                        'currency' => $order->bounds()->min()->currency(),
                        'amount' => $order->bounds()->min()->amount(),
                        'scale' => $order->bounds()->min()->scale(),
                    ],
                    'max' => [
                        'currency' => $order->bounds()->max()->currency(),
                        'amount' => $order->bounds()->max()->amount(),
                        'scale' => $order->bounds()->max()->scale(),
                    ],
                ],
                'effectiveRate' => [
                    'baseCurrency' => $order->effectiveRate()->baseCurrency(),
                    'quoteCurrency' => $order->effectiveRate()->quoteCurrency(),
                    'value' => $order->effectiveRate()->rate(),
                    'scale' => $order->effectiveRate()->scale(),
                ],
            ],
            $serialized['order'],
        );

        self::assertSame(
            [
                'baseCurrency' => $fixture['rate']->baseCurrency(),
                'quoteCurrency' => $fixture['rate']->quoteCurrency(),
                'value' => $fixture['rate']->rate(),
                'scale' => $fixture['rate']->scale(),
            ],
            $serialized['rate'],
        );

        self::assertSame($fixture['baseCapacity']->jsonSerialize(), $serialized['baseCapacity']);
        self::assertSame($fixture['quoteCapacity']->jsonSerialize(), $serialized['quoteCapacity']);
        self::assertSame($fixture['grossBaseCapacity']->jsonSerialize(), $serialized['grossBaseCapacity']);
        self::assertSame([
            $fixture['segment']->jsonSerialize(),
        ], $serialized['segments']);
    }

    public function test_array_access_returns_null_for_unknown_key(): void
    {
        $edge = $this->createEdgeFixture()['edge'];

        self::assertNull($edge['nonexistent']);
    }

    /**
     * @return array{
     *     edge: GraphEdge,
     *     order: \SomeWork\P2PPathFinder\Domain\Order\Order,
     *     rate: \SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate,
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
