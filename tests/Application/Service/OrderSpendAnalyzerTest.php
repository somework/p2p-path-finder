<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Service;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Application\Service\OrderSpendAnalyzer;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Tests\Fixture\FeePolicyFactory;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

final class OrderSpendAnalyzerTest extends TestCase
{
    public function test_it_clamps_buy_seed_to_order_minimum_with_base_surcharge(): void
    {
        $order = OrderFactory::buy(
            base: 'EUR',
            quote: 'USD',
            minAmount: '120.000',
            maxAmount: '250.000',
            rate: '1.100',
            amountScale: 3,
            rateScale: 3,
            feePolicy: FeePolicyFactory::baseSurcharge('0.010'),
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $graph['EUR']['edges'][0];

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.000', 3))
            ->withToleranceBounds(0.0, 0.25)
            ->withHopLimits(1, 1)
            ->build();

        $analyzer = new OrderSpendAnalyzer();
        $seed = $analyzer->determineInitialSpendAmount($config, $edge);

        self::assertNotNull($seed);
        self::assertTrue($seed['net']->equals(Money::fromString('EUR', '120.000', 3)));
        self::assertTrue($seed['gross']->equals(Money::fromString('EUR', '121.200', 3)));
        self::assertTrue($seed['grossCeiling']->equals(Money::fromString('EUR', '125.000', 3)));
    }

    public function test_it_rejects_buy_seed_when_minimum_gross_exceeds_upper_tolerance(): void
    {
        $order = OrderFactory::buy(
            base: 'EUR',
            quote: 'USD',
            minAmount: '120.000',
            maxAmount: '250.000',
            rate: '1.100',
            amountScale: 3,
            rateScale: 3,
            feePolicy: FeePolicyFactory::baseSurcharge('0.010'),
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $graph['EUR']['edges'][0];

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.000', 3))
            ->withToleranceBounds(0.0, 0.05)
            ->withHopLimits(1, 1)
            ->build();

        $analyzer = new OrderSpendAnalyzer();
        $seed = $analyzer->determineInitialSpendAmount($config, $edge);

        self::assertNull($seed);
    }

    public function test_it_clamps_sell_seed_to_effective_bounds(): void
    {
        $order = OrderFactory::sell(
            base: 'BTC',
            quote: 'USD',
            minAmount: '0.500',
            maxAmount: '1.500',
            rate: '30000',
            amountScale: 3,
            rateScale: 8,
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $graph['USD']['edges'][0];

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '14000.00', 2))
            ->withToleranceBounds(0.1, 0.1)
            ->withHopLimits(1, 1)
            ->build();

        $analyzer = new OrderSpendAnalyzer();
        $seed = $analyzer->determineInitialSpendAmount($config, $edge);

        self::assertNotNull($seed);
        self::assertTrue($seed['net']->equals(Money::fromString('USD', '15000.00', 2)));
        self::assertTrue($seed['gross']->equals(Money::fromString('USD', '15000.00', 2)));
        self::assertTrue($seed['grossCeiling']->equals(Money::fromString('USD', '15000.00', 2)));
    }

    public function test_it_rejects_sell_seed_when_bounds_do_not_overlap(): void
    {
        $order = OrderFactory::sell(
            base: 'BTC',
            quote: 'USD',
            minAmount: '0.500',
            maxAmount: '1.500',
            rate: '30000',
            amountScale: 3,
            rateScale: 8,
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $graph['USD']['edges'][0];

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '14000.00', 2))
            ->withToleranceBounds(0.0, 0.05)
            ->withHopLimits(1, 1)
            ->build();

        $analyzer = new OrderSpendAnalyzer();
        $seed = $analyzer->determineInitialSpendAmount($config, $edge);

        self::assertNull($seed);
    }

    public function test_it_rejects_sell_seed_when_quote_fee_eliminates_effective_window(): void
    {
        $order = OrderFactory::sell(
            base: 'BTC',
            quote: 'USD',
            minAmount: '1.000',
            maxAmount: '1.000',
            rate: '100.00',
            amountScale: 3,
            rateScale: 2,
            feePolicy: FeePolicyFactory::baseAndQuoteSurcharge('0.000000', '0.60', 3),
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $graph['USD']['edges'][0];

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '150.00', 2))
            ->withToleranceBounds(0.1, 0.1)
            ->withHopLimits(1, 1)
            ->build();

        $analyzer = new OrderSpendAnalyzer();
        $seed = $analyzer->determineInitialSpendAmount($config, $edge);

        self::assertNull($seed);
    }

    public function test_it_excludes_orders_outside_spend_bounds(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds(0.1, 0.2)
            ->withHopLimits(1, 3)
            ->build();

        $tooLowBuy = OrderFactory::buy(
            base: 'USD',
            quote: 'EUR',
            minAmount: '10.00',
            maxAmount: '80.00',
            rate: '0.9000',
            amountScale: 2,
            rateScale: 4,
        );
        $tooHighBuy = OrderFactory::buy(
            base: 'USD',
            quote: 'EUR',
            minAmount: '130.00',
            maxAmount: '150.00',
            rate: '0.9000',
            amountScale: 2,
            rateScale: 4,
        );
        $tooLowSell = OrderFactory::sell(
            base: 'BTC',
            quote: 'USD',
            minAmount: '0.001',
            maxAmount: '0.002',
            rate: '30000',
            amountScale: 3,
            rateScale: 2,
        );
        $withinBoundsBuy = OrderFactory::buy(
            base: 'USD',
            quote: 'EUR',
            minAmount: '95.00',
            maxAmount: '115.00',
            rate: '0.9000',
            amountScale: 2,
            rateScale: 4,
        );

        $orderBook = new OrderBook([
            $tooLowBuy,
            $withinBoundsBuy,
            $tooHighBuy,
            $tooLowSell,
        ]);

        $analyzer = new OrderSpendAnalyzer();
        $filtered = $analyzer->filterOrders($orderBook, $config);

        self::assertSame([$withinBoundsBuy], $filtered);
    }

    public function test_it_preserves_mixed_orders_at_spend_boundaries(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds(0.1, 0.2)
            ->withHopLimits(1, 3)
            ->build();

        $buyAtBounds = OrderFactory::buy(
            base: 'USD',
            quote: 'EUR',
            minAmount: '90.00',
            maxAmount: '120.00',
            rate: '0.9000',
            amountScale: 2,
            rateScale: 4,
        );
        $sellAtBounds = OrderFactory::sell(
            base: 'BTC',
            quote: 'USD',
            minAmount: '0.003',
            maxAmount: '0.004',
            rate: '30000',
            amountScale: 3,
            rateScale: 2,
        );

        $orderBook = new OrderBook([
            $buyAtBounds,
            $sellAtBounds,
        ]);

        $analyzer = new OrderSpendAnalyzer();
        $filtered = $analyzer->filterOrders($orderBook, $config);

        self::assertSame([
            $buyAtBounds,
            $sellAtBounds,
        ], $filtered);
    }

    public function test_it_preserves_orders_with_foreign_spend_currency(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds(0.1, 0.2)
            ->withHopLimits(1, 3)
            ->build();

        $foreignSpendBuy = OrderFactory::buy(
            base: 'EUR',
            quote: 'GBP',
            minAmount: '500.00',
            maxAmount: '600.00',
            rate: '0.8500',
            amountScale: 2,
            rateScale: 4,
        );
        $withinBoundsBuy = OrderFactory::buy(
            base: 'USD',
            quote: 'EUR',
            minAmount: '95.00',
            maxAmount: '115.00',
            rate: '0.9000',
            amountScale: 2,
            rateScale: 4,
        );
        $tooHighBuy = OrderFactory::buy(
            base: 'USD',
            quote: 'EUR',
            minAmount: '130.00',
            maxAmount: '150.00',
            rate: '0.9000',
            amountScale: 2,
            rateScale: 4,
        );

        $orderBook = new OrderBook([
            $foreignSpendBuy,
            $withinBoundsBuy,
            $tooHighBuy,
        ]);

        $analyzer = new OrderSpendAnalyzer();
        $filtered = $analyzer->filterOrders($orderBook, $config);

        self::assertSame([
            $foreignSpendBuy,
            $withinBoundsBuy,
        ], $filtered);
    }
}
