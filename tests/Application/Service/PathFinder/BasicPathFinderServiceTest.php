<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Service\PathFinder;

use InvalidArgumentException;
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

final class BasicPathFinderServiceTest extends PathFinderServiceTestCase
{
    public function test_it_builds_multi_hop_path_and_aggregates_amounts(): void
    {
        $orderBook = $this->orderBook(
            $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '10.000', '200.000', '0.900', 3),
            $this->createOrder(OrderSide::BUY, 'USD', 'JPY', '50.000', '200.000', '150.000', 3),
            $this->createOrder(OrderSide::SELL, 'JPY', 'EUR', '10.000', '20000.000', '0.007500', 6),
        );

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds(0.0, 0.25)
            ->withHopLimits(1, 3)
            ->build();

        $result = $this->makeService()->findBestPath($orderBook, $config, 'JPY');

        self::assertNotNull($result);
        self::assertSame('EUR', $result->totalSpent()->currency());
        self::assertSame('100.000', $result->totalSpent()->amount());
        self::assertSame('JPY', $result->totalReceived()->currency());
        self::assertSame('16665.000', $result->totalReceived()->amount());
        self::assertSame(0.0, $result->residualTolerance());

        $legs = $result->legs();
        self::assertCount(2, $legs);

        self::assertSame('EUR', $legs[0]->from());
        self::assertSame('USD', $legs[0]->to());
        self::assertSame('100.000', $legs[0]->spent()->amount());
        self::assertSame('111.100', $legs[0]->received()->amount());

        self::assertSame('USD', $legs[1]->from());
        self::assertSame('JPY', $legs[1]->to());
        self::assertSame('111.100', $legs[1]->spent()->amount());
        self::assertSame('16665.000', $legs[1]->received()->amount());
    }

    public function test_it_requires_non_empty_target_asset(): void
    {
        $orderBook = $this->orderBook();

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '1.00', 2))
            ->withToleranceBounds(0.0, 0.0)
            ->withHopLimits(1, 1)
            ->build();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Target asset cannot be empty.');

        $this->makeService()->findBestPath($orderBook, $config, '');
    }

    public function test_it_returns_null_when_target_node_is_missing(): void
    {
        $orderBook = $this->orderBook(
            $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '10.000', '200.000', '0.900', 3),
        );

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '50.00', 2))
            ->withToleranceBounds(0.0, 0.0)
            ->withHopLimits(1, 1)
            ->build();

        self::assertNull($this->makeService()->findBestPath($orderBook, $config, 'JPY'));
    }

    public function test_it_skips_highest_scoring_path_when_complex_book_lacks_capacity(): void
    {
        $orderBook = $this->orderBook(
            $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '10.000', '80.000', '0.600', 3),
            $this->createOrder(OrderSide::SELL, 'GBP', 'EUR', '5.000', '500.000', '0.800', 3),
            $this->createOrder(OrderSide::SELL, 'CHF', 'EUR', '5.000', '400.000', '0.920', 3),
            $this->createOrder(OrderSide::SELL, 'AUD', 'EUR', '5.000', '400.000', '0.700', 3),
            $this->createOrder(OrderSide::SELL, 'CAD', 'EUR', '5.000', '400.000', '0.750', 3),
            $this->createOrder(OrderSide::BUY, 'GBP', 'USD', '5.000', '500.000', '1.200', 3),
            $this->createOrder(OrderSide::BUY, 'CHF', 'USD', '5.000', '500.000', '1.050', 3),
            $this->createOrder(OrderSide::BUY, 'AUD', 'USD', '5.000', '500.000', '0.650', 3),
            $this->createOrder(OrderSide::BUY, 'CAD', 'USD', '5.000', '500.000', '0.730', 3),
            $this->createOrder(OrderSide::BUY, 'EUR', 'CHF', '5.000', '500.000', '1.100', 3),
        );

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds(0.0, 0.20)
            ->withHopLimits(1, 3)
            ->build();

        $result = $this->makeService()->findBestPath($orderBook, $config, 'USD');

        self::assertNotNull($result);
        self::assertSame('EUR', $result->totalSpent()->currency());
        self::assertSame('100.000', $result->totalSpent()->amount());
        self::assertSame('USD', $result->totalReceived()->currency());
        self::assertSame('150.000', $result->totalReceived()->amount());

        $legs = $result->legs();
        self::assertCount(2, $legs);
        self::assertSame('EUR', $legs[0]->from());
        self::assertSame('GBP', $legs[0]->to());
        self::assertSame('100.000', $legs[0]->spent()->amount());
        self::assertSame('125.000', $legs[0]->received()->amount());

        self::assertSame('GBP', $legs[1]->from());
        self::assertSame('USD', $legs[1]->to());
        self::assertSame('125.000', $legs[1]->spent()->amount());
        self::assertSame('150.000', $legs[1]->received()->amount());
    }

    public function test_it_prefers_best_rates_when_multiple_identical_pairs_exist(): void
    {
        $orderBook = $this->orderBook(
            $this->createOrder(OrderSide::SELL, 'GBP', 'EUR', '5.000', '80.000', '0.680', 3),
            $this->createOrder(OrderSide::SELL, 'GBP', 'EUR', '5.000', '500.000', '0.760', 3),
            $this->createOrder(OrderSide::SELL, 'GBP', 'EUR', '5.000', '500.000', '0.780', 3),
            $this->createOrder(OrderSide::SELL, 'GBP', 'EUR', '5.000', '500.000', '0.710', 3),
            $this->createOrder(OrderSide::SELL, 'GBP', 'EUR', '5.000', '500.000', '0.700', 3),
            $this->createOrder(OrderSide::BUY, 'GBP', 'USD', '5.000', '80.000', '1.350', 3),
            $this->createOrder(OrderSide::BUY, 'GBP', 'USD', '5.000', '500.000', '1.220', 3),
            $this->createOrder(OrderSide::BUY, 'GBP', 'USD', '5.000', '500.000', '1.200', 3),
            $this->createOrder(OrderSide::BUY, 'GBP', 'USD', '5.000', '500.000', '1.180', 3),
            $this->createOrder(OrderSide::BUY, 'GBP', 'USD', '5.000', '500.000', '1.250', 3),
        );

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds(0.0, 0.15)
            ->withHopLimits(1, 3)
            ->build();

        $result = $this->makeService()->findBestPath($orderBook, $config, 'USD');

        self::assertNotNull($result);
        self::assertSame('EUR', $result->totalSpent()->currency());
        self::assertSame('100.000', $result->totalSpent()->amount());
        self::assertSame('USD', $result->totalReceived()->currency());
        self::assertSame('178.625', $result->totalReceived()->amount());

        $legs = $result->legs();
        self::assertCount(2, $legs);

        self::assertSame('EUR', $legs[0]->from());
        self::assertSame('GBP', $legs[0]->to());
        self::assertSame('100.000', $legs[0]->spent()->amount());
        self::assertSame('142.900', $legs[0]->received()->amount());

        self::assertSame('GBP', $legs[1]->from());
        self::assertSame('USD', $legs[1]->to());
        self::assertSame('142.900', $legs[1]->spent()->amount());
        self::assertSame('178.625', $legs[1]->received()->amount());
    }
}
