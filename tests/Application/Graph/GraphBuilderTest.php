<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Graph;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Domain\Order\FeeBreakdown;
use SomeWork\P2PPathFinder\Domain\Order\FeePolicy;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\AssetPair;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Domain\ValueObject\OrderBounds;

final class GraphBuilderTest extends TestCase
{
    public function test_build_creates_currency_nodes_and_directional_edges_for_multiple_orders(): void
    {
        $primaryBuyOrder = $this->createOrder(OrderSide::BUY, 'BTC', 'USD', '0.100', '1.000', '30000');
        $secondaryBuyOrder = $this->createOrder(OrderSide::BUY, 'BTC', 'EUR', '0.200', '0.800', '28000');
        $primarySellOrder = $this->createOrder(OrderSide::SELL, 'ETH', 'USD', '0.500', '2.000', '1500');
        $secondarySellOrder = $this->createOrder(OrderSide::SELL, 'LTC', 'USD', '1.000', '4.000', '90');

        $graph = (new GraphBuilder())->build([
            $primaryBuyOrder,
            $secondaryBuyOrder,
            $primarySellOrder,
            $secondarySellOrder,
        ]);

        self::assertCount(5, $graph);
        self::assertEqualsCanonicalizing([
            'BTC',
            'USD',
            'EUR',
            'ETH',
            'LTC',
        ], array_keys($graph));

        $btcEdges = $graph['BTC']['edges'];
        self::assertCount(2, $btcEdges);

        $primaryBtcEdge = $btcEdges[0];
        self::assertSame('BTC', $primaryBtcEdge['from']);
        self::assertSame('USD', $primaryBtcEdge['to']);
        self::assertSame(OrderSide::BUY, $primaryBtcEdge['orderSide']);
        self::assertSame($primaryBuyOrder, $primaryBtcEdge['order']);
        self::assertTrue($primaryBtcEdge['baseCapacity']['min']->equals(Money::fromString('BTC', '0.100', 3)));
        self::assertTrue($primaryBtcEdge['baseCapacity']['max']->equals(Money::fromString('BTC', '1.000', 3)));
        self::assertTrue($primaryBtcEdge['quoteCapacity']['min']->equals(Money::fromString('USD', '3000.000', 3)));
        self::assertTrue($primaryBtcEdge['quoteCapacity']['max']->equals(Money::fromString('USD', '30000.000', 3)));

        self::assertCount(2, $primaryBtcEdge['segments']);
        self::assertTrue($primaryBtcEdge['segments'][0]['isMandatory']);
        self::assertTrue($primaryBtcEdge['segments'][0]['base']['max']->equals(Money::fromString('BTC', '0.100', 3)));
        self::assertTrue($primaryBtcEdge['segments'][0]['quote']['max']->equals(Money::fromString('USD', '3000.000', 3)));
        self::assertFalse($primaryBtcEdge['segments'][1]['isMandatory']);
        self::assertTrue($primaryBtcEdge['segments'][1]['base']['max']->equals(Money::fromString('BTC', '0.900', 3)));
        self::assertTrue($primaryBtcEdge['segments'][1]['quote']['max']->equals(Money::fromString('USD', '27000.000', 3)));

        $secondaryBtcEdge = $btcEdges[1];
        self::assertSame('BTC', $secondaryBtcEdge['from']);
        self::assertSame('EUR', $secondaryBtcEdge['to']);
        self::assertSame(OrderSide::BUY, $secondaryBtcEdge['orderSide']);
        self::assertSame($secondaryBuyOrder, $secondaryBtcEdge['order']);
        self::assertTrue($secondaryBtcEdge['baseCapacity']['min']->equals(Money::fromString('BTC', '0.200', 3)));
        self::assertTrue($secondaryBtcEdge['baseCapacity']['max']->equals(Money::fromString('BTC', '0.800', 3)));
        self::assertTrue($secondaryBtcEdge['quoteCapacity']['min']->equals(Money::fromString('EUR', '5600.000', 3)));
        self::assertTrue($secondaryBtcEdge['quoteCapacity']['max']->equals(Money::fromString('EUR', '22400.000', 3)));

        self::assertCount(2, $secondaryBtcEdge['segments']);
        self::assertTrue($secondaryBtcEdge['segments'][0]['isMandatory']);
        self::assertTrue($secondaryBtcEdge['segments'][0]['base']['max']->equals(Money::fromString('BTC', '0.200', 3)));
        self::assertTrue($secondaryBtcEdge['segments'][0]['quote']['max']->equals(Money::fromString('EUR', '5600.000', 3)));
        self::assertFalse($secondaryBtcEdge['segments'][1]['isMandatory']);
        self::assertTrue($secondaryBtcEdge['segments'][1]['base']['max']->equals(Money::fromString('BTC', '0.600', 3)));
        self::assertTrue($secondaryBtcEdge['segments'][1]['quote']['max']->equals(Money::fromString('EUR', '16800.000', 3)));

        $usdEdges = $graph['USD']['edges'];
        self::assertCount(2, $usdEdges);

        $primaryUsdEdge = $usdEdges[0];
        self::assertSame('USD', $primaryUsdEdge['from']);
        self::assertSame('ETH', $primaryUsdEdge['to']);
        self::assertSame(OrderSide::SELL, $primaryUsdEdge['orderSide']);
        self::assertSame($primarySellOrder, $primaryUsdEdge['order']);
        self::assertTrue($primaryUsdEdge['baseCapacity']['min']->equals(Money::fromString('ETH', '0.500', 3)));
        self::assertTrue($primaryUsdEdge['baseCapacity']['max']->equals(Money::fromString('ETH', '2.000', 3)));
        self::assertTrue($primaryUsdEdge['quoteCapacity']['min']->equals(Money::fromString('USD', '750.000', 3)));
        self::assertTrue($primaryUsdEdge['quoteCapacity']['max']->equals(Money::fromString('USD', '3000.000', 3)));

        self::assertCount(2, $primaryUsdEdge['segments']);
        self::assertTrue($primaryUsdEdge['segments'][0]['isMandatory']);
        self::assertTrue($primaryUsdEdge['segments'][0]['base']['max']->equals(Money::fromString('ETH', '0.500', 3)));
        self::assertTrue($primaryUsdEdge['segments'][0]['quote']['max']->equals(Money::fromString('USD', '750.000', 3)));
        self::assertFalse($primaryUsdEdge['segments'][1]['isMandatory']);
        self::assertTrue($primaryUsdEdge['segments'][1]['base']['max']->equals(Money::fromString('ETH', '1.500', 3)));
        self::assertTrue($primaryUsdEdge['segments'][1]['quote']['max']->equals(Money::fromString('USD', '2250.000', 3)));

        $secondaryUsdEdge = $usdEdges[1];
        self::assertSame('USD', $secondaryUsdEdge['from']);
        self::assertSame('LTC', $secondaryUsdEdge['to']);
        self::assertSame(OrderSide::SELL, $secondaryUsdEdge['orderSide']);
        self::assertSame($secondarySellOrder, $secondaryUsdEdge['order']);
        self::assertTrue($secondaryUsdEdge['baseCapacity']['min']->equals(Money::fromString('LTC', '1.000', 3)));
        self::assertTrue($secondaryUsdEdge['baseCapacity']['max']->equals(Money::fromString('LTC', '4.000', 3)));
        self::assertTrue($secondaryUsdEdge['quoteCapacity']['min']->equals(Money::fromString('USD', '90.000', 3)));
        self::assertTrue($secondaryUsdEdge['quoteCapacity']['max']->equals(Money::fromString('USD', '360.000', 3)));

        self::assertCount(2, $secondaryUsdEdge['segments']);
        self::assertTrue($secondaryUsdEdge['segments'][0]['isMandatory']);
        self::assertTrue($secondaryUsdEdge['segments'][0]['base']['max']->equals(Money::fromString('LTC', '1.000', 3)));
        self::assertTrue($secondaryUsdEdge['segments'][0]['quote']['max']->equals(Money::fromString('USD', '90.000', 3)));
        self::assertFalse($secondaryUsdEdge['segments'][1]['isMandatory']);
        self::assertTrue($secondaryUsdEdge['segments'][1]['base']['max']->equals(Money::fromString('LTC', '3.000', 3)));
        self::assertTrue($secondaryUsdEdge['segments'][1]['quote']['max']->equals(Money::fromString('USD', '270.000', 3)));

        self::assertCount(0, $graph['EUR']['edges']);
        self::assertCount(0, $graph['ETH']['edges']);
        self::assertCount(0, $graph['LTC']['edges']);
    }

    public function test_build_uses_net_quote_capacity_for_buy_orders_with_fee(): void
    {
        $order = $this->createOrder(
            OrderSide::BUY,
            'BTC',
            'USD',
            '1.000',
            '3.000',
            '100.000',
            $this->percentageFeePolicy('0.10'),
        );

        $graph = (new GraphBuilder())->build([$order]);

        $edges = $graph['BTC']['edges'];
        self::assertCount(1, $edges);

        $edge = $edges[0];

        self::assertTrue($edge['quoteCapacity']['min']->equals(Money::fromString('USD', '90.000', 3)));
        self::assertTrue($edge['quoteCapacity']['max']->equals(Money::fromString('USD', '270.000', 3)));

        $rawMin = Money::fromString('USD', '100.000', 3);
        $rawMax = Money::fromString('USD', '300.000', 3);
        self::assertTrue($edge['quoteCapacity']['min']->lessThan($rawMin));
        self::assertTrue($edge['quoteCapacity']['max']->lessThan($rawMax));

        self::assertCount(2, $edge['segments']);

        $mandatory = $edge['segments'][0];
        self::assertTrue($mandatory['isMandatory']);
        self::assertTrue($mandatory['quote']['max']->equals(Money::fromString('USD', '90.000', 3)));

        $optional = $edge['segments'][1];
        self::assertFalse($optional['isMandatory']);
        self::assertTrue($optional['quote']['max']->equals(Money::fromString('USD', '180.000', 3)));
    }

    public function test_build_encodes_fully_flexible_orders_as_single_segment(): void
    {
        $order = $this->createOrder(OrderSide::BUY, 'ETH', 'USN', '0.000', '5.000', '2000');

        $graph = (new GraphBuilder())->build([$order]);

        $edges = $graph['ETH']['edges'];
        self::assertCount(1, $edges);

        $edge = $edges[0];
        self::assertCount(1, $edge['segments']);
        self::assertFalse($edge['segments'][0]['isMandatory']);
        self::assertTrue($edge['segments'][0]['base']['min']->equals(Money::fromString('ETH', '0.000', 3)));
        self::assertTrue($edge['segments'][0]['base']['max']->equals(Money::fromString('ETH', '5.000', 3)));
        self::assertTrue($edge['segments'][0]['quote']['min']->equals(Money::fromString('USN', '0.000', 3)));
        self::assertTrue($edge['segments'][0]['quote']['max']->equals(Money::fromString('USN', '10000.000', 3)));
    }

    public function test_build_splits_bounds_into_mandatory_and_optional_segments(): void
    {
        $order = $this->createOrder(OrderSide::BUY, 'BTC', 'USD', '1.250', '3.750', '2500.000');

        $graph = (new GraphBuilder())->build([$order]);

        $edges = $graph['BTC']['edges'];
        self::assertCount(1, $edges);

        $edge = $edges[0];
        self::assertTrue($edge['baseCapacity']['min']->equals(Money::fromString('BTC', '1.250', 3)));
        self::assertTrue($edge['baseCapacity']['max']->equals(Money::fromString('BTC', '3.750', 3)));
        self::assertTrue($edge['quoteCapacity']['min']->equals(Money::fromString('USD', '3125.000', 3)));
        self::assertTrue($edge['quoteCapacity']['max']->equals(Money::fromString('USD', '9375.000', 3)));

        self::assertCount(2, $edge['segments']);

        $mandatory = $edge['segments'][0];
        self::assertTrue($mandatory['isMandatory']);
        self::assertTrue($mandatory['base']['min']->equals(Money::fromString('BTC', '1.250', 3)));
        self::assertTrue($mandatory['base']['max']->equals(Money::fromString('BTC', '1.250', 3)));
        self::assertTrue($mandatory['quote']['min']->equals(Money::fromString('USD', '3125.000', 3)));
        self::assertTrue($mandatory['quote']['max']->equals(Money::fromString('USD', '3125.000', 3)));

        $optional = $edge['segments'][1];
        self::assertFalse($optional['isMandatory']);
        self::assertTrue($optional['base']['min']->equals(Money::fromString('BTC', '0.000', 3)));
        self::assertTrue($optional['base']['max']->equals(Money::fromString('BTC', '2.500', 3)));
        self::assertTrue($optional['quote']['min']->equals(Money::fromString('USD', '0.000', 3)));
        self::assertTrue($optional['quote']['max']->equals(Money::fromString('USD', '6250.000', 3)));
    }

    public function test_build_creates_zero_capacity_segment_for_point_orders(): void
    {
        $order = $this->createOrder(OrderSide::BUY, 'ETH', 'USD', '0.000', '0.000', '1800.000');

        $graph = (new GraphBuilder())->build([$order]);

        $edges = $graph['ETH']['edges'];
        self::assertCount(1, $edges);

        $edge = $edges[0];
        self::assertCount(1, $edge['segments']);

        $segment = $edge['segments'][0];
        self::assertFalse($segment['isMandatory']);
        self::assertTrue($segment['base']['min']->equals(Money::fromString('ETH', '0.000', 3)));
        self::assertTrue($segment['base']['max']->equals(Money::fromString('ETH', '0.000', 3)));
        self::assertTrue($segment['quote']['min']->equals(Money::fromString('USD', '0.000', 3)));
        self::assertTrue($segment['quote']['max']->equals(Money::fromString('USD', '0.000', 3)));
    }

    /**
     * @param non-empty-string $base
     * @param non-empty-string $quote
     */
    private function createOrder(
        OrderSide $side,
        string $base,
        string $quote,
        string $min,
        string $max,
        string $rate,
        ?FeePolicy $feePolicy = null,
    ): Order {
        $assetPair = AssetPair::fromString($base, $quote);
        $bounds = OrderBounds::from(
            Money::fromString($base, $min, 3),
            Money::fromString($base, $max, 3),
        );
        $exchangeRate = ExchangeRate::fromString($base, $quote, $rate, 3);

        return new Order($side, $assetPair, $bounds, $exchangeRate, $feePolicy);
    }

    private function percentageFeePolicy(string $percentage): FeePolicy
    {
        return new class($percentage) implements FeePolicy {
            public function __construct(private readonly string $percentage)
            {
            }

            public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
            {
                $fee = $quoteAmount->multiply($this->percentage, $quoteAmount->scale());

                return FeeBreakdown::forQuote($fee);
            }
        };
    }
}
