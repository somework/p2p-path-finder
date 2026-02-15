<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Model;

use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\EdgeCapacity;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\GraphEdge;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\PathEdge;
use SomeWork\P2PPathFinder\Domain\Money\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;
use SomeWork\P2PPathFinder\Tests\Helpers\DecimalMath;

#[CoversClass(PathEdge::class)]
final class PathEdgeTest extends TestCase
{
    private const SCALE = 18;

    #[TestDox('create() builds a PathEdge with all properties accessible')]
    public function test_create_builds_path_edge_with_all_properties(): void
    {
        $order = OrderFactory::buy('BTC', 'USD', '0.100', '1.000', '30000.00', 3, 2);
        $rate = $order->effectiveRate();
        $conversionRate = DecimalMath::decimal('30000.000000000000000000', self::SCALE);

        $edge = PathEdge::create('BTC', 'USD', $order, $rate, OrderSide::BUY, $conversionRate);

        self::assertSame('BTC', $edge->from());
        self::assertSame('USD', $edge->to());
        self::assertSame(OrderSide::BUY, $edge->orderSide());
        self::assertSame('30000.000000000000000000', $edge->conversionRate());
        self::assertTrue($edge->conversionRateDecimal()->isEqualTo($conversionRate));
    }

    #[TestDox('create() preserves order and rate references')]
    public function test_create_preserves_order_and_rate_references(): void
    {
        $order = OrderFactory::sell('ETH', 'BTC', '1.000', '10.000', '0.05000000', 3, 8);
        $rate = $order->effectiveRate();
        $conversionRate = DecimalMath::decimal('0.050000000000000000', self::SCALE);

        $edge = PathEdge::create('BTC', 'ETH', $order, $rate, OrderSide::SELL, $conversionRate);

        self::assertSame($order->bounds()->min()->amount(), $edge->order()->bounds()->min()->amount());
        self::assertSame($rate->rate(), $edge->rate()->rate());
    }

    #[TestDox('fromGraphEdge() creates PathEdge from GraphEdge')]
    public function test_from_graph_edge_creates_path_edge(): void
    {
        $order = OrderFactory::buy('BTC', 'USD', '0.100', '1.000', '30000.00', 3, 2);
        $rate = $order->effectiveRate();

        $baseCapacity = new EdgeCapacity(
            Money::fromString('BTC', '0.100', 3),
            Money::fromString('BTC', '1.000', 3),
        );
        $quoteCapacity = new EdgeCapacity(
            Money::fromString('USD', '3000.00', 2),
            Money::fromString('USD', '30000.00', 2),
        );

        $graphEdge = new GraphEdge(
            'BTC',
            'USD',
            OrderSide::BUY,
            $order,
            $rate,
            $baseCapacity,
            $quoteCapacity,
            $baseCapacity,
        );

        $conversionRate = DecimalMath::decimal('30000.000000000000000000', self::SCALE);
        $pathEdge = PathEdge::fromGraphEdge($graphEdge, $conversionRate);

        self::assertSame('BTC', $pathEdge->from());
        self::assertSame('USD', $pathEdge->to());
        self::assertSame(OrderSide::BUY, $pathEdge->orderSide());
        self::assertSame('30000.000000000000000000', $pathEdge->conversionRate());
    }

    #[TestDox('toArray() returns all fields in expected format')]
    public function test_to_array_returns_all_fields(): void
    {
        $order = OrderFactory::buy('AAA', 'BBB', '1.000', '5.000', '2.500', 3, 3);
        $rate = $order->effectiveRate();
        $conversionRate = DecimalMath::decimal('2.500000000000000000', self::SCALE);

        $edge = PathEdge::create('AAA', 'BBB', $order, $rate, OrderSide::BUY, $conversionRate);
        $array = $edge->toArray();

        self::assertSame('AAA', $array['from']);
        self::assertSame('BBB', $array['to']);
        self::assertSame(OrderSide::BUY, $array['orderSide']);
        self::assertSame('2.500000000000000000', $array['conversionRate']);
        self::assertInstanceOf(ExchangeRate::class, $array['rate']);
    }

    #[TestDox('conversionRate() preserves precision at scale 18')]
    public function test_conversion_rate_preserves_precision(): void
    {
        $order = OrderFactory::buy('AAA', 'BBB', '1.000', '1.000', '1.000', 3, 3);
        $conversionRate = DecimalMath::decimal('1.123456789012345678', self::SCALE);

        $edge = PathEdge::create(
            'AAA',
            'BBB',
            $order,
            $order->effectiveRate(),
            OrderSide::BUY,
            $conversionRate,
        );

        self::assertSame('1.123456789012345678', $edge->conversionRate());
    }

    #[TestDox('conversionRateDecimal() returns BigDecimal with original precision')]
    public function test_conversion_rate_decimal_returns_big_decimal(): void
    {
        $order = OrderFactory::buy('AAA', 'BBB', '1.000', '1.000', '1.000', 3, 3);
        $conversionRate = BigDecimal::of('0.999999999999999999');

        $edge = PathEdge::create(
            'AAA',
            'BBB',
            $order,
            $order->effectiveRate(),
            OrderSide::BUY,
            $conversionRate,
        );

        self::assertInstanceOf(BigDecimal::class, $edge->conversionRateDecimal());
        self::assertTrue($edge->conversionRateDecimal()->isEqualTo($conversionRate));
    }

    #[TestDox('create() works with SELL side orders')]
    public function test_create_works_with_sell_side(): void
    {
        $order = OrderFactory::sell('ETH', 'USD', '0.500', '5.000', '2000.00', 3, 2);
        $conversionRate = DecimalMath::decimal('2000.000000000000000000', self::SCALE);

        $edge = PathEdge::create(
            'USD',
            'ETH',
            $order,
            $order->effectiveRate(),
            OrderSide::SELL,
            $conversionRate,
        );

        self::assertSame('USD', $edge->from());
        self::assertSame('ETH', $edge->to());
        self::assertSame(OrderSide::SELL, $edge->orderSide());
    }
}
