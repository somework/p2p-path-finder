<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\PathFinder\PathFinder;
use SomeWork\P2PPathFinder\Domain\Order\FeeBreakdown;
use SomeWork\P2PPathFinder\Domain\Order\FeePolicy;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Tests\Fixture\CurrencyScenarioFactory;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

final class PathFinderTest extends TestCase
{
    private const SCALE = 18;

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
        string $expectedProduct
    ): void {
        $orders = self::buildComprehensiveOrderBook();
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

        self::assertSame($expectedProduct, $result['product']);
        $expectedCost = BcMath::div('1', $expectedProduct, self::SCALE);
        self::assertSame($expectedCost, $result['cost']);
    }

    /**
     * @return iterable<string, array{int, float, int, list<array{from: string, to: string}>, string}>
     */
    public static function provideRubToIdrConstraintScenarios(): iterable
    {
        yield 'direct_route_only' => [
            1,
            0.0,
            1,
            [
                ['from' => 'RUB', 'to' => 'IDR'],
            ],
            BcMath::normalize('165.000', self::SCALE),
        ];

        $rubToUsd = BcMath::div('1', '90.500', self::SCALE);
        $twoHopProduct = BcMath::mul(
            $rubToUsd,
            BcMath::normalize('15400.000', self::SCALE),
            self::SCALE,
        );
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

        $threeHopProduct = BcMath::mul(
            BcMath::mul(
                $rubToUsd,
                BcMath::normalize('149.500', self::SCALE),
                self::SCALE,
            ),
            BcMath::normalize('112.750', self::SCALE),
            self::SCALE,
        );
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
    public static function provideImpossibleScenarios(): iterable
    {
        yield 'missing_second_leg' => [
            self::createRubToUsdSellOrders(),
            3,
            0.05,
            'RUB',
            'IDR',
        ];

        $withoutDirectEdge = array_merge(
            self::createRubToUsdSellOrders(),
            self::createUsdToIdrBuyOrders(),
            self::createMultiHopSupplement(),
        );
        yield 'hop_budget_too_strict' => [
            $withoutDirectEdge,
            1,
            0.0,
            'RUB',
            'IDR',
        ];

        yield 'missing_source_currency' => [
            self::buildComprehensiveOrderBook(),
            3,
            0.0,
            'GBP',
            'IDR',
        ];

        yield 'missing_target_currency' => [
            self::buildComprehensiveOrderBook(),
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
        string $expectedProduct
    ): void {
        $graph = (new GraphBuilder())->build($orders);

        $finder = new PathFinder(maxHops: 2, tolerance: 0.05);
        $result = $finder->findBestPath($graph, $source, $target);

        self::assertNotNull($result);
        self::assertSame(1, $result['hops']);
        self::assertCount(1, $result['edges']);
        self::assertSame($source, $result['edges'][0]['from']);
        self::assertSame($target, $result['edges'][0]['to']);
        self::assertSame($expectedProduct, $result['product']);
    }

    /**
     * @return iterable<string, array{list<Order>, string, string, string}>
     */
    public static function provideSingleLegMarkets(): iterable
    {
        yield 'rub_to_usd_order_book' => [
            self::createRubToUsdSellOrders(),
            'RUB',
            'USD',
            BcMath::div('1', '90.500', self::SCALE),
        ];

        yield 'usd_to_idr_order_book' => [
            self::createUsdToIdrBuyOrders(),
            'USD',
            'IDR',
            BcMath::normalize('15400.000', self::SCALE),
        ];
    }

    /**
     * @dataProvider provideSpendBelowMandatoryMinimum
     */
    public function test_it_prunes_paths_below_mandatory_minimum(
        Order $order,
        string $source,
        string $target,
        Money $desiredSpend
    ): void {
        $graph = (new GraphBuilder())->build([$order]);

        $finder = new PathFinder(maxHops: 1, tolerance: 0.0);
        $accepted = false;

        $result = $finder->findBestPath(
            $graph,
            $source,
            $target,
            [
                'min' => $desiredSpend,
                'max' => $desiredSpend,
                'desired' => $desiredSpend,
            ],
            static function () use (&$accepted): bool {
                $accepted = true;

                return true;
            },
        );

        self::assertNull($result);
        self::assertFalse($accepted);
    }

    /**
     * @return iterable<string, array{Order, string, string, Money}>
     */
    public static function provideSpendBelowMandatoryMinimum(): iterable
    {
        yield 'buy_edge_below_base_minimum' => [
            OrderFactory::buy('EUR', 'USD', '10.000', '100.000', '1.050', 3, 3),
            'EUR',
            'USD',
            CurrencyScenarioFactory::money('EUR', '9.999', 3),
        ];

        yield 'buy_edge_below_gross_base_requirement' => [
            OrderFactory::buy(
                'EUR',
                'USD',
                '10.000',
                '100.000',
                '1.050',
                3,
                3,
                self::basePercentageFeePolicy('0.02'),
            ),
            'EUR',
            'USD',
            CurrencyScenarioFactory::money('EUR', '10.150', 3),
        ];

        yield 'sell_edge_below_quote_minimum' => [
            OrderFactory::sell('EUR', 'USD', '10.000', '100.000', '1.050', 3, 3),
            'USD',
            'EUR',
            CurrencyScenarioFactory::money('USD', '10.499', 3),
        ];
    }

    public function test_it_prefers_profitable_multi_leg_route_with_mixed_order_sides(): void
    {
        $orders = array_merge(
            self::createUsdToEurDirectOrders(),
            self::createUsdToEthSellOrders(),
            self::createEthToEurBuyOrders(),
        );

        $graph = (new GraphBuilder())->build($orders);

        $finder = new PathFinder(maxHops: 3, tolerance: 0.0);
        $result = $finder->findBestPath($graph, 'USD', 'EUR');

        self::assertNotNull($result);
        self::assertSame(2, $result['hops']);
        self::assertCount(2, $result['edges']);

        $expectedProduct = BcMath::mul(
            BcMath::div('1', '1800.00', self::SCALE),
            BcMath::normalize('1700.00', self::SCALE),
            self::SCALE,
        );
        self::assertSame($expectedProduct, $result['product']);

        self::assertSame('USD', $result['edges'][0]['from']);
        self::assertSame('ETH', $result['edges'][0]['to']);
        self::assertSame(OrderSide::SELL, $result['edges'][0]['orderSide']);

        self::assertSame('ETH', $result['edges'][1]['from']);
        self::assertSame('EUR', $result['edges'][1]['to']);
        self::assertSame(OrderSide::BUY, $result['edges'][1]['orderSide']);

        self::assertSame(1, BcMath::comp($result['product'], BcMath::normalize('0.92', self::SCALE), self::SCALE));
    }

    public function test_it_accounts_for_gross_base_when_scoring_buy_edges(): void
    {
        $order = OrderFactory::buy(
            'EUR',
            'USD',
            '1.000',
            '1.000',
            '1.100',
            3,
            3,
            self::basePercentageFeePolicy('0.05'),
        );

        $graph = (new GraphBuilder())->build([$order]);

        $finder = new PathFinder(maxHops: 1, tolerance: 0.0);
        $grossSpend = CurrencyScenarioFactory::money('EUR', '1.050', 3);

        $result = $finder->findBestPath(
            $graph,
            'EUR',
            'USD',
            [
                'min' => $grossSpend,
                'max' => $grossSpend,
                'desired' => $grossSpend,
            ],
        );

        self::assertNotNull($result);
        self::assertSame(1, $result['hops']);
        self::assertCount(1, $result['edges']);

        $expectedProduct = BcMath::div('1.100', '1.050', self::SCALE);
        self::assertSame($expectedProduct, $result['product']);
        self::assertSame(BcMath::div('1', $expectedProduct, self::SCALE), $result['cost']);
        self::assertSame($expectedProduct, $result['edges'][0]['conversionRate']);
    }

    /**
     * Demonstrates how a single-leg buy path with simultaneous base surcharges and quote deductions alters the conversion rate used for tolerance math.
     */
    public function test_it_accounts_for_combined_base_and_quote_fees_when_scoring_buy_edges(): void
    {
        $order = OrderFactory::createOrder(
            OrderSide::BUY,
            'EUR',
            'USD',
            '10.000',
            '10.000',
            '1.200',
            amountScale: 3,
            rateScale: 3,
            feePolicy: self::mixedPercentageFeePolicy('0.02', '0.05'),
        );

        $graph = (new GraphBuilder())->build([$order]);

        $finder = new PathFinder(maxHops: 1, tolerance: 0.10);
        $result = $finder->findBestPath($graph, 'EUR', 'USD');

        self::assertNotNull($result);
        self::assertSame(1, $result['hops']);
        self::assertCount(1, $result['edges']);
        self::assertSame('EUR', $result['edges'][0]['from']);
        self::assertSame('USD', $result['edges'][0]['to']);

        $expectedProduct = BcMath::div('11.400', '10.200', self::SCALE);
        $expectedCost = BcMath::div('1', $expectedProduct, self::SCALE);

        self::assertSame($expectedProduct, $result['product']);
        self::assertSame($expectedCost, $result['cost']);
        self::assertSame($expectedProduct, $result['edges'][0]['conversionRate']);
    }

    public function test_it_returns_zero_hop_path_when_source_equals_target(): void
    {
        $orders = self::buildComprehensiveOrderBook();
        $graph = (new GraphBuilder())->build($orders);

        $finder = new PathFinder(maxHops: 3, tolerance: 0.15);
        $result = $finder->findBestPath($graph, 'USD', 'USD');

        self::assertNotNull($result);
        self::assertSame(0, $result['hops']);
        self::assertSame([], $result['edges']);
        self::assertSame(BcMath::normalize('1', self::SCALE), $result['product']);
        self::assertSame(BcMath::normalize('1', self::SCALE), $result['cost']);
    }

    /**
     * @param list<Order> $orders
     *
     * @dataProvider provideExtremeRateScenarios
     */
    public function test_it_remains_deterministic_with_extreme_rate_scales(
        array $orders,
        string $source,
        string $target,
        string $expectedProduct,
        int $expectedHops
    ): void {
        $graph = (new GraphBuilder())->build($orders);

        $finder = new PathFinder(maxHops: 3, tolerance: 0.0);
        $first = $finder->findBestPath($graph, $source, $target);
        $second = $finder->findBestPath($graph, $source, $target);

        self::assertNotNull($first);
        self::assertNotNull($second);

        self::assertSame($first, $second, 'Extreme rate scenarios should produce deterministic outcomes.');

        self::assertSame($expectedHops, $first['hops']);
        self::assertSame($expectedProduct, $first['product']);
        $expectedCost = BcMath::div('1', $expectedProduct, self::SCALE);
        self::assertSame($expectedCost, $first['cost']);
    }

    /**
     * @return iterable<string, array{list<Order>, string, string, string, int}>
     */
    public static function provideExtremeRateScenarios(): iterable
    {
        $highGrowthFirstLeg = BcMath::div('1', '0.000000000123456789', self::SCALE);
        $astronomicalProduct = BcMath::mul(
            $highGrowthFirstLeg,
            BcMath::normalize('987654321.987654321', self::SCALE),
            self::SCALE,
        );

        yield 'astronomical_precision_path' => [
            [
                OrderFactory::createOrder(
                    OrderSide::SELL,
                    'MIC',
                    'SRC',
                    '1.000000000000000000',
                    '1.000000000000000000',
                    '0.000000000123456789',
                    amountScale: 18,
                    rateScale: 18,
                ),
                OrderFactory::createOrder(
                    OrderSide::BUY,
                    'MIC',
                    'MEG',
                    '1.000000000000000000',
                    '1.000000000000000000',
                    '987654321.987654321',
                    amountScale: 18,
                    rateScale: 9,
                ),
                OrderFactory::createOrder(
                    OrderSide::BUY,
                    'SRC',
                    'MEG',
                    '1.000000000000000000',
                    '1.000000000000000000',
                    '100.000000000000000000',
                    amountScale: 18,
                    rateScale: 18,
                ),
            ],
            'SRC',
            'MEG',
            $astronomicalProduct,
            2,
        ];

        yield 'microscopic_precision_path' => [
            [
                OrderFactory::createOrder(
                    OrderSide::SELL,
                    'MIC',
                    'SRC',
                    '1.000000000000000000',
                    '1.000000000000000000',
                    '987654321.987654321',
                    amountScale: 18,
                    rateScale: 9,
                ),
                OrderFactory::createOrder(
                    OrderSide::BUY,
                    'MIC',
                    'MEG',
                    '1.000000000000000000',
                    '1.000000000000000000',
                    '0.000000000123456789',
                    amountScale: 18,
                    rateScale: 18,
                ),
                OrderFactory::createOrder(
                    OrderSide::BUY,
                    'SRC',
                    'MEG',
                    '1.000000000000000000',
                    '1.000000000000000000',
                    '0.000000000200000000',
                    amountScale: 18,
                    rateScale: 18,
                ),
            ],
            'SRC',
            'MEG',
            BcMath::normalize('0.000000000200000000', self::SCALE),
            1,
        ];
    }

    /**
     * @return list<Order>
     */
    private static function buildComprehensiveOrderBook(): array
    {
        return array_merge(
            self::createRubToUsdSellOrders(),
            self::createUsdToIdrBuyOrders(),
            self::createDirectRubToIdrOrders(),
            self::createMultiHopSupplement(),
        );
    }

    /**
     * @return list<Order>
     */
    private static function createRubToUsdSellOrders(): array
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

            $orders[] = OrderFactory::createOrder(
                OrderSide::SELL,
                'USD',
                'RUB',
                self::formatAmount($minBase),
                self::formatAmount($maxBase),
                $rate,
                rateScale: 3,
            );
        }

        return $orders;
    }

    /**
     * @return list<Order>
     */
    private static function createUsdToIdrBuyOrders(): array
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

            $orders[] = OrderFactory::createOrder(
                OrderSide::BUY,
                'USD',
                'IDR',
                self::formatAmount($minBase),
                self::formatAmount($maxBase),
                $rate,
                rateScale: 3,
            );
        }

        return $orders;
    }

    /**
     * @return list<Order>
     */
    private static function createDirectRubToIdrOrders(): array
    {
        return [
            OrderFactory::createOrder(
                OrderSide::BUY,
                'RUB',
                'IDR',
                '200.000',
                '200.000',
                '165.000',
                rateScale: 3,
            ),
        ];
    }

    /**
     * @return list<Order>
     */
    private static function createMultiHopSupplement(): array
    {
        return [
            OrderFactory::createOrder(
                OrderSide::BUY,
                'USD',
                'JPY',
                '25.000',
                '50.000',
                '149.500',
                rateScale: 3,
            ),
            OrderFactory::createOrder(
                OrderSide::BUY,
                'JPY',
                'IDR',
                '2500.000',
                '5000.000',
                '112.750',
                rateScale: 3,
            ),
            OrderFactory::createOrder(
                OrderSide::BUY,
                'USD',
                'SGD',
                '15.000',
                '30.000',
                '1.350',
                rateScale: 3,
            ),
            OrderFactory::createOrder(
                OrderSide::BUY,
                'SGD',
                'IDR',
                '20.000',
                '40.000',
                '11250.000',
                rateScale: 3,
            ),
        ];
    }

    /**
     * @return list<Order>
     */
    private static function createUsdToEurDirectOrders(): array
    {
        return [
            OrderFactory::createOrder(
                OrderSide::BUY,
                'USD',
                'EUR',
                '10.000',
                '10.000',
                '0.9200',
                amountScale: 3,
                rateScale: 4,
            ),
        ];
    }

    /**
     * @return list<Order>
     */
    private static function createUsdToEthSellOrders(): array
    {
        return [
            OrderFactory::createOrder(
                OrderSide::SELL,
                'ETH',
                'USD',
                '5.000',
                '5.000',
                '1800.00',
                amountScale: 3,
                rateScale: 2,
            ),
        ];
    }

    /**
     * @return list<Order>
     */
    private static function createEthToEurBuyOrders(): array
    {
        return [
            OrderFactory::createOrder(
                OrderSide::BUY,
                'ETH',
                'EUR',
                '5.000',
                '5.000',
                '1700.00',
                amountScale: 3,
                rateScale: 2,
            ),
        ];
    }

    private static function basePercentageFeePolicy(string $percentage): FeePolicy
    {
        return new class($percentage) implements FeePolicy {
            public function __construct(private readonly string $percentage)
            {
            }

            public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
            {
                $fee = $baseAmount->multiply($this->percentage, $baseAmount->scale());

                return FeeBreakdown::forBase($fee);
            }
        };
    }

    private static function mixedPercentageFeePolicy(string $basePercentage, string $quotePercentage): FeePolicy
    {
        return new class($basePercentage, $quotePercentage) implements FeePolicy {
            public function __construct(
                private readonly string $basePercentage,
                private readonly string $quotePercentage,
            ) {
            }

            public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
            {
                $baseFee = $baseAmount->multiply($this->basePercentage, $baseAmount->scale());
                $quoteFee = $quoteAmount->multiply($this->quotePercentage, $quoteAmount->scale());

                return FeeBreakdown::of($baseFee, $quoteFee);
            }
        };
    }

    private static function formatAmount(float $amount): string
    {
        return number_format($amount, 3, '.', '');
    }
}
