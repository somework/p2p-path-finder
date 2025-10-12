<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Service;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Application\Service\PathFinderService;
use SomeWork\P2PPathFinder\Domain\Order\FeePolicy;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\AssetPair;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Domain\ValueObject\OrderBounds;

final class PathFinderServiceTest extends TestCase
{
    public function test_it_builds_multi_hop_path_and_aggregates_amounts(): void
    {
        $orderBook = new OrderBook([
            $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '10.000', '200.000', '0.900', 3),
            $this->createOrder(OrderSide::BUY, 'USD', 'JPY', '50.000', '200.000', '150.000', 3),
            $this->createOrder(OrderSide::SELL, 'JPY', 'EUR', '10.000', '20000.000', '0.007500', 6),
        ]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds(0.0, 0.25)
            ->withHopLimits(1, 3)
            ->build();

        $service = new PathFinderService(new GraphBuilder());
        $result = $service->findBestPath($orderBook, $config, 'JPY');

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

    public function test_it_materializes_leg_fees_and_breakdown(): void
    {
        $orderBook = new OrderBook([
            $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '10.000', '200.000', '0.900', 3, $this->percentageFeePolicy('0.01')),
            $this->createOrder(OrderSide::BUY, 'USD', 'JPY', '50.000', '200.000', '150.000', 3, $this->percentageFeePolicy('0.02')),
        ]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds(0.0, 0.25)
            ->withHopLimits(1, 2)
            ->build();

        $service = new PathFinderService(new GraphBuilder());
        $result = $service->findBestPath($orderBook, $config, 'JPY');

        self::assertNotNull($result);

        $legs = $result->legs();
        self::assertCount(2, $legs);

        self::assertSame('100.000', $legs[0]->spent()->amount());
        self::assertSame('112.233', $legs[0]->received()->amount());
        self::assertSame('EUR', $legs[0]->fee()->currency());
        self::assertSame('1.010', $legs[0]->fee()->amount());

        self::assertSame('112.233', $legs[1]->spent()->amount());
        self::assertSame('17171.649', $legs[1]->received()->amount());
        self::assertSame('JPY', $legs[1]->fee()->currency());
        self::assertSame('336.699', $legs[1]->fee()->amount());

        $feeBreakdown = $result->feeBreakdown();
        self::assertCount(2, $feeBreakdown);
        self::assertArrayHasKey('EUR', $feeBreakdown);
        self::assertArrayHasKey('JPY', $feeBreakdown);
        self::assertSame('1.010', $feeBreakdown['EUR']->amount());
        self::assertSame('336.699', $feeBreakdown['JPY']->amount());
    }

    public function test_it_returns_null_when_tolerance_window_filters_out_orders(): void
    {
        $orderBook = new OrderBook([
            $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '10.000', '200.000', '0.900', 3),
            $this->createOrder(OrderSide::SELL, 'JPY', 'EUR', '10.000', '20000.000', '0.007500', 6),
        ]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '5.00', 2))
            ->withToleranceBounds(0.0, 0.40)
            ->withHopLimits(1, 2)
            ->build();

        $service = new PathFinderService(new GraphBuilder());
        $result = $service->findBestPath($orderBook, $config, 'USD');

        self::assertNull($result);
    }

    public function test_it_enforces_minimum_hop_requirement(): void
    {
        $orderBook = new OrderBook([
            $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '10.000', '200.000', '0.900', 3),
            $this->createOrder(OrderSide::BUY, 'USD', 'JPY', '50.000', '200.000', '150.000', 3),
            $this->createOrder(OrderSide::SELL, 'JPY', 'EUR', '10.000', '20000.000', '0.007500', 6),
        ]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds(0.0, 0.25)
            ->withHopLimits(3, 3)
            ->build();

        $service = new PathFinderService(new GraphBuilder());
        $result = $service->findBestPath($orderBook, $config, 'JPY');

        self::assertNull($result);
    }

    public function test_it_handles_under_spend_within_tolerance_bounds(): void
    {
        $orderBook = new OrderBook([
            $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '5.000', '8.000', '0.900', 3),
        ]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '8.00', 2))
            ->withToleranceBounds(0.25, 0.05)
            ->withHopLimits(1, 1)
            ->build();

        $service = new PathFinderService(new GraphBuilder());
        $result = $service->findBestPath($orderBook, $config, 'USD');

        self::assertNotNull($result);
        self::assertSame('EUR', $result->totalSpent()->currency());
        self::assertSame('7.200', $result->totalSpent()->amount());
        self::assertSame('USD', $result->totalReceived()->currency());
        self::assertSame('7.999', $result->totalReceived()->amount());
        self::assertEqualsWithDelta(0.1, $result->residualTolerance(), 1e-9);
    }

    private function createOrder(OrderSide $side, string $base, string $quote, string $min, string $max, string $rate, int $rateScale, ?FeePolicy $feePolicy = null): Order
    {
        $assetPair = AssetPair::fromString($base, $quote);
        $bounds = OrderBounds::from(
            Money::fromString($base, $min, 3),
            Money::fromString($base, $max, 3),
        );
        $exchangeRate = ExchangeRate::fromString($base, $quote, $rate, $rateScale);

        return new Order($side, $assetPair, $bounds, $exchangeRate, $feePolicy);
    }

    private function percentageFeePolicy(string $percentage): FeePolicy
    {
        return new class($percentage) implements FeePolicy {
            public function __construct(private readonly string $percentage)
            {
            }

            public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): Money
            {
                return $quoteAmount->multiply($this->percentage, $quoteAmount->scale());
            }
        };
    }
}
