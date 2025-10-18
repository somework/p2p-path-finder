<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Service\PathFinder;

use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Application\Service\PathFinderService;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Tests\Fixture\FeePolicyFactory;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

/**
 * @covers \SomeWork\P2PPathFinder\Application\Service\PathFinderService
 *
 * @group acceptance
 */
final class PathFinderServiceGuardsTest extends PathFinderServiceTestCase
{
    public function test_it_rejects_candidates_that_do_not_meet_minimum_hops(): void
    {
        $orderBook = $this->simpleEuroToUsdOrderBook();

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(2, 3)
            ->build();

        $result = $this->makeService()->findBestPaths($orderBook, $config, 'USD');

        self::assertSame([], $result->paths());
        self::assertFalse($result->guardLimits()->expansionsReached());
        self::assertFalse($result->guardLimits()->visitedStatesReached());
    }

    public function test_it_ignores_candidates_without_initial_seed_resolution(): void
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
                feePolicy: FeePolicyFactory::baseAndQuoteSurcharge('0.000000', '0.50', 3),
            ),
        );

        $service = new PathFinderService(new GraphBuilder());

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.0')
            ->withHopLimits(1, 3)
            ->build();

        $result = $service->findBestPaths($orderBook, $config, 'BTC');

        self::assertSame([], $result->paths());
        self::assertFalse($result->guardLimits()->expansionsReached());
        self::assertFalse($result->guardLimits()->visitedStatesReached());
    }

    public function test_it_filters_candidates_that_exceed_tolerance_after_materialization(): void
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
                $this->percentageFeePolicy('0.10'),
            ),
        );

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.05')
            ->withHopLimits(1, 1)
            ->build();

        $result = $this->makeService()->findBestPaths($orderBook, $config, 'USD');

        self::assertSame([], $result->paths());
        self::assertFalse($result->guardLimits()->expansionsReached());
        self::assertFalse($result->guardLimits()->visitedStatesReached());
    }

    private function simpleEuroToUsdOrderBook(): OrderBook
    {
        return $this->orderBook(
            OrderFactory::sell('USD', 'EUR', '10.000', '200.000', '0.900', 3),
        );
    }
}
