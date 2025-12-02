<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Integration\Application\PathSearch\Service;

use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\PathSearchService;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\OrderBook;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

final class PathSearchServiceRejectionTest extends PathSearchServiceTestCase
{
    public function test_it_returns_empty_result_when_filtered_orders_do_not_overlap_spend_window(): void
    {
        $orderBook = $this->orderBook(
            $this->createOrder(OrderSide::BUY, 'EUR', 'USD', '200.000', '300.000', '1.100', 3),
        );

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '50.00', 2))
            ->withToleranceBounds('0.0', '0.0')
            ->withHopLimits(1, 1)
            ->build();

        $service = new PathSearchService(new GraphBuilder());

        $result = $service->findBestPaths($this->makeRequest($orderBook, $config, 'USD'));

        self::assertSame([], $result->paths()->toArray());
        self::assertFalse($result->guardLimits()->expansionsReached());
        self::assertFalse($result->guardLimits()->visitedStatesReached());
    }

    public function test_it_returns_empty_result_when_graph_lacks_source_node(): void
    {
        $orders = [
            OrderFactory::buy(
                base: 'USD',
                quote: 'JPY',
                minAmount: '10.00',
                maxAmount: '20.00',
                rate: '150.000',
                amountScale: 2,
                rateScale: 3
            ),
        ];

        $orderBook = new OrderBook($orders);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '10.00', 2))
            ->withToleranceBounds('0.0', '0.05')
            ->withHopLimits(1, 2)
            ->build();

        $service = new PathSearchService(new GraphBuilder());

        $result = $service->findBestPaths($this->makeRequest($orderBook, $config, 'USD'));

        self::assertSame([], $result->paths()->toArray());
        self::assertFalse($result->guardLimits()->expansionsReached());
        self::assertFalse($result->guardLimits()->visitedStatesReached());
    }

    public function test_it_rejects_candidates_when_initial_seed_cannot_be_resolved(): void
    {
        $orderBook = $this->orderBook(
            OrderFactory::sell(
                base: 'BTC',
                quote: 'USD',
                minAmount: '0.500',
                maxAmount: '0.750',
                rate: '100.00',
                amountScale: 3,
                rateScale: 2,
            ),
        );

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.0')
            ->withHopLimits(1, 2)
            ->build();

        $service = new PathSearchService(new GraphBuilder());

        $result = $service->findBestPaths($this->makeRequest($orderBook, $config, 'BTC'));

        self::assertSame([], $result->paths()->toArray());
        self::assertFalse($result->guardLimits()->expansionsReached());
        self::assertFalse($result->guardLimits()->visitedStatesReached());
    }

    public function test_it_filters_candidates_exceeding_tolerance_after_materialization(): void
    {
        $orderBook = $this->orderBook(
            $this->createOrder(
                OrderSide::SELL,
                'USD',
                'EUR',
                '100.000',
                '200.000',
                '1.000',
                3,
                $this->percentageFeePolicy('0.10')
            ),
        );

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.05')
            ->withHopLimits(1, 1)
            ->build();

        $service = new PathSearchService(new GraphBuilder());

        $result = $service->findBestPaths($this->makeRequest($orderBook, $config, 'USD'));

        self::assertSame([], $result->paths()->toArray());
        self::assertFalse($result->guardLimits()->expansionsReached());
        self::assertFalse($result->guardLimits()->visitedStatesReached());
    }
}
