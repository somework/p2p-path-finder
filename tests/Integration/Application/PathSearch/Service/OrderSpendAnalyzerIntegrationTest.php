<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Integration\Application\PathSearch\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\OrderSpendAnalyzer;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\OrderBook;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

#[CoversClass(OrderSpendAnalyzer::class)]
final class OrderSpendAnalyzerIntegrationTest extends TestCase
{
    public function test_it_excludes_orders_outside_spend_bounds(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.1', '0.2')
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
            ->withToleranceBounds('0.1', '0.2')
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
            ->withToleranceBounds('0.1', '0.2')
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

    public function test_filter_orders_handles_empty_order_book(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.1', '0.2')
            ->withHopLimits(1, 3)
            ->build();

        $orderBook = new OrderBook([]);

        $analyzer = new OrderSpendAnalyzer();
        $filtered = $analyzer->filterOrders($orderBook, $config);

        self::assertSame([], $filtered);
    }

    public function test_filter_orders_handles_orders_with_exact_bound_matches(): void
    {
        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.1', '0.2')
            ->withHopLimits(1, 3)
            ->build();

        $exactMatchBuy = OrderFactory::buy(
            base: 'USD',
            quote: 'EUR',
            minAmount: '80.00', // 100 * 0.8 = 80 (lower bound)
            maxAmount: '120.00', // 100 * 1.2 = 120 (upper bound)
            rate: '0.9000',
            amountScale: 2,
            rateScale: 4,
        );

        $orderBook = new OrderBook([$exactMatchBuy]);

        $analyzer = new OrderSpendAnalyzer();
        $filtered = $analyzer->filterOrders($orderBook, $config);

        self::assertSame([$exactMatchBuy], $filtered);
    }
}
