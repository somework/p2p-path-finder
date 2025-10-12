<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\PathFinder\PathFinder;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\AssetPair;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Domain\ValueObject\OrderBounds;

final class PathFinderTest extends TestCase
{
    /**
     * @dataProvider provideRubToIdrConstraintScenarios
     *
     * @param list<array{from: string, to: string}> $expectedRoute
     */
    public function test_it_finds_best_rub_to_idr_path_under_various_filters(
        int $maxHops,
        float $tolerance,
        int $expectedHopCount,
        array $expectedRoute,
        float $expectedProduct
    ): void {
        $orders = $this->buildComprehensiveOrderBook();
        $graph = (new GraphBuilder())->build($orders);

        $finder = new PathFinder($maxHops, $tolerance);
        $result = $finder->findBestPath($graph, 'RUB', 'IDR');

        self::assertNotNull($result);
        self::assertSame($expectedHopCount, $result['hops']);
        self::assertCount($expectedHopCount, $result['edges']);

        foreach ($expectedRoute as $index => $edge) {
            self::assertSame($edge['from'], $result['edges'][$index]['from']);
            self::assertSame($edge['to'], $result['edges'][$index]['to']);
        }

        self::assertEqualsWithDelta($expectedProduct, $result['product'], 1e-6);
        self::assertEqualsWithDelta(-log($expectedProduct), $result['cost'], 1e-6);
    }

    /**
     * @return iterable<string, array{int, float, int, list<array{from: string, to: string}>, float}>
     */
    public function provideRubToIdrConstraintScenarios(): iterable
    {
        yield 'direct_route_only' => [
            1,
            0.0,
            1,
            [
                ['from' => 'RUB', 'to' => 'IDR'],
            ],
            165.0,
        ];

        $twoHopProduct = 15400.0 / 90.5;
        yield 'two_hop_best_path_with_strict_tolerance' => [
            2,
            0.0,
            2,
            [
                ['from' => 'RUB', 'to' => 'USD'],
                ['from' => 'USD', 'to' => 'IDR'],
            ],
            $twoHopProduct,
        ];

        yield 'two_hop_best_path_with_relaxed_tolerance' => [
            2,
            0.12,
            2,
            [
                ['from' => 'RUB', 'to' => 'USD'],
                ['from' => 'USD', 'to' => 'IDR'],
            ],
            $twoHopProduct,
        ];

        $threeHopProduct = (1 / 90.5) * 149.5 * 112.75;
        yield 'three_hop_path_outperforms_direct_conversion' => [
            3,
            0.995,
            3,
            [
                ['from' => 'RUB', 'to' => 'USD'],
                ['from' => 'USD', 'to' => 'JPY'],
                ['from' => 'JPY', 'to' => 'IDR'],
            ],
            $threeHopProduct,
        ];
    }

    /**
     * @dataProvider provideImpossibleScenarios
     */
    public function test_it_returns_null_when_no_viable_path(
        array $orders,
        int $maxHops,
        float $tolerance,
        string $source,
        string $target
    ): void {
        $graph = (new GraphBuilder())->build($orders);

        $finder = new PathFinder($maxHops, $tolerance);
        $result = $finder->findBestPath($graph, $source, $target);

        self::assertNull($result);
    }

    /**
     * @return iterable<string, array{list<Order>, int, float, string, string}>
     */
    public function provideImpossibleScenarios(): iterable
    {
        yield 'missing_second_leg' => [
            $this->createRubToUsdSellOrders(),
            3,
            0.05,
            'RUB',
            'IDR',
        ];

        $withoutDirectEdge = array_merge(
            $this->createRubToUsdSellOrders(),
            $this->createUsdToIdrBuyOrders(),
            $this->createMultiHopSupplement(),
        );
        yield 'hop_budget_too_strict' => [
            $withoutDirectEdge,
            1,
            0.0,
            'RUB',
            'IDR',
        ];

        yield 'missing_source_currency' => [
            $this->buildComprehensiveOrderBook(),
            3,
            0.0,
            'GBP',
            'IDR',
        ];

        yield 'missing_target_currency' => [
            $this->buildComprehensiveOrderBook(),
            3,
            0.0,
            'RUB',
            'CHF',
        ];
    }

    /**
     * @dataProvider provideSingleLegMarkets
     */
    public function test_it_handles_single_leg_markets(
        array $orders,
        string $source,
        string $target,
        float $expectedProduct
    ): void {
        $graph = (new GraphBuilder())->build($orders);

        $finder = new PathFinder(maxHops: 2, tolerance: 0.05);
        $result = $finder->findBestPath($graph, $source, $target);

        self::assertNotNull($result);
        self::assertSame(1, $result['hops']);
        self::assertCount(1, $result['edges']);
        self::assertSame($source, $result['edges'][0]['from']);
        self::assertSame($target, $result['edges'][0]['to']);
        self::assertEqualsWithDelta($expectedProduct, $result['product'], 1e-6);
    }

    /**
     * @return iterable<string, array{list<Order>, string, string, float}>
     */
    public function provideSingleLegMarkets(): iterable
    {
        yield 'rub_to_usd_order_book' => [
            $this->createRubToUsdSellOrders(),
            'RUB',
            'USD',
            1 / 90.5,
        ];

        yield 'usd_to_idr_order_book' => [
            $this->createUsdToIdrBuyOrders(),
            'USD',
            'IDR',
            15400.0,
        ];
    }

    /**
     * @return list<Order>
     */
    private function buildComprehensiveOrderBook(): array
    {
        return array_merge(
            $this->createRubToUsdSellOrders(),
            $this->createUsdToIdrBuyOrders(),
            $this->createDirectRubToIdrOrders(),
            $this->createMultiHopSupplement(),
        );
    }

    /**
     * @return list<Order>
     */
    private function createRubToUsdSellOrders(): array
    {
        $rates = [
            '96.500', '97.250', '94.400', '95.100', '98.600',
            '93.300', '92.750', '94.900', '96.000', '95.500',
            '97.800', '94.050', '92.350', '93.750', '96.800',
            '91.900', '90.500', '94.200', '95.900', '93.100',
        ];

        $orders = [];
        foreach ($rates as $index => $rate) {
            $maxBase = 100 + ($index * 5);
            $minBase = 0 === $index % 2 ? $maxBase : $maxBase / 2;

            $orders[] = $this->createOrder(
                OrderSide::SELL,
                'USD',
                'RUB',
                $this->formatAmount($minBase),
                $this->formatAmount($maxBase),
                $rate
            );
        }

        return $orders;
    }

    /**
     * @return list<Order>
     */
    private function createUsdToIdrBuyOrders(): array
    {
        $rates = [
            '15050.000', '15120.000', '14980.000', '15240.000', '15090.000',
            '15310.000', '15020.000', '15170.000', '15360.000', '15280.000',
            '15110.000', '15060.000', '15210.000', '15030.000', '15320.000',
            '15190.000', '15400.000', '15010.000', '15260.000', '15140.000',
        ];

        $orders = [];
        foreach ($rates as $index => $rate) {
            $maxBase = 50 + ($index * 3);
            $minBase = 0 === $index % 2 ? $maxBase : $maxBase / 2;

            $orders[] = $this->createOrder(
                OrderSide::BUY,
                'USD',
                'IDR',
                $this->formatAmount($minBase),
                $this->formatAmount($maxBase),
                $rate
            );
        }

        return $orders;
    }

    /**
     * @return list<Order>
     */
    private function createDirectRubToIdrOrders(): array
    {
        return [
            $this->createOrder(
                OrderSide::BUY,
                'RUB',
                'IDR',
                '200.000',
                '200.000',
                '165.000'
            ),
        ];
    }

    /**
     * @return list<Order>
     */
    private function createMultiHopSupplement(): array
    {
        return [
            $this->createOrder(
                OrderSide::BUY,
                'USD',
                'JPY',
                '25.000',
                '50.000',
                '149.500'
            ),
            $this->createOrder(
                OrderSide::BUY,
                'JPY',
                'IDR',
                '2500.000',
                '5000.000',
                '112.750'
            ),
            $this->createOrder(
                OrderSide::BUY,
                'USD',
                'SGD',
                '15.000',
                '30.000',
                '1.350'
            ),
            $this->createOrder(
                OrderSide::BUY,
                'SGD',
                'IDR',
                '20.000',
                '40.000',
                '11250.000'
            ),
        ];
    }

    private function formatAmount(float $amount): string
    {
        return number_format($amount, 3, '.', '');
    }

    private function createOrder(
        OrderSide $side,
        string $base,
        string $quote,
        string $min,
        string $max,
        string $rate
    ): Order {
        $assetPair = AssetPair::fromString($base, $quote);
        $bounds = OrderBounds::from(
            Money::fromString($base, $min, 3),
            Money::fromString($base, $max, 3),
        );
        $exchangeRate = ExchangeRate::fromString($base, $quote, $rate, 3);

        return new Order($side, $assetPair, $bounds, $exchangeRate);
    }
}
