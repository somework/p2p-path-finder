<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Service;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Application\Service\PathFinderService;
use SomeWork\P2PPathFinder\Domain\Order\FeeBreakdown;
use SomeWork\P2PPathFinder\Domain\Order\FeePolicy;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\AssetPair;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Domain\ValueObject\OrderBounds;

use function sprintf;
use function substr;

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
        $firstLegFees = $legs[0]->fees();
        self::assertArrayHasKey('EUR', $firstLegFees);
        self::assertSame('1.010', $firstLegFees['EUR']->amount());

        self::assertSame('112.233', $legs[1]->spent()->amount());
        self::assertSame('16498.251', $legs[1]->received()->amount());
        $secondLegFees = $legs[1]->fees();
        self::assertArrayHasKey('JPY', $secondLegFees);
        self::assertSame('336.699', $secondLegFees['JPY']->amount());

        $rawWithoutFee = Money::fromString('JPY', '16834.950', 3);
        self::assertTrue($legs[1]->received()->lessThan($rawWithoutFee));

        $feeBreakdown = $result->feeBreakdown();
        self::assertCount(2, $feeBreakdown);
        self::assertArrayHasKey('EUR', $feeBreakdown);
        self::assertArrayHasKey('JPY', $feeBreakdown);
        self::assertSame('1.010', $feeBreakdown['EUR']->amount());
        self::assertSame('336.699', $feeBreakdown['JPY']->amount());

        self::assertTrue($result->totalReceived()->lessThan($rawWithoutFee));
    }

    public function test_it_includes_base_fee_in_total_spent(): void
    {
        $orderBook = new OrderBook([
            $this->createOrder(
                OrderSide::BUY,
                'EUR',
                'USD',
                '10.000',
                '200.000',
                '1.200',
                3,
                $this->basePercentageFeePolicy('0.02'),
            ),
        ]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds(0.0, 0.05)
            ->withHopLimits(1, 1)
            ->build();

        $service = new PathFinderService(new GraphBuilder());
        $result = $service->findBestPath($orderBook, $config, 'USD');

        self::assertNotNull($result);

        self::assertSame('EUR', $result->totalSpent()->currency());
        self::assertSame('100.000', $result->totalSpent()->amount());
        self::assertSame(0.0, $result->residualTolerance());

        $legs = $result->legs();
        self::assertCount(1, $legs);
        self::assertSame('100.000', $legs[0]->spent()->amount());

        $fees = $legs[0]->fees();
        self::assertArrayHasKey('EUR', $fees);
        self::assertSame('1.961', $fees['EUR']->amount());

        $feeBreakdown = $result->feeBreakdown();
        self::assertArrayHasKey('EUR', $feeBreakdown);
        self::assertSame('1.961', $feeBreakdown['EUR']->amount());
    }

    public function test_it_materializes_chained_buy_legs_with_fees_using_net_quotes(): void
    {
        $orderBook = new OrderBook([
            $this->createOrder(OrderSide::BUY, 'EUR', 'USD', '50.000', '200.000', '1.100', 3, $this->percentageFeePolicy('0.05')),
            $this->createOrder(OrderSide::BUY, 'USD', 'JPY', '50.000', '200.000', '150.000', 3, $this->percentageFeePolicy('0.02')),
        ]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds(0.0, 0.10)
            ->withHopLimits(1, 3)
            ->build();

        $service = new PathFinderService(new GraphBuilder());
        $result = $service->findBestPath($orderBook, $config, 'JPY');

        self::assertNotNull($result);
        self::assertSame('EUR', $result->totalSpent()->currency());
        self::assertSame('100.000', $result->totalSpent()->amount());
        self::assertSame('JPY', $result->totalReceived()->currency());
        self::assertSame('15361.500', $result->totalReceived()->amount());
        self::assertSame(0.0, $result->residualTolerance());

        $legs = $result->legs();
        self::assertCount(2, $legs);

        self::assertSame('EUR', $legs[0]->from());
        self::assertSame('USD', $legs[0]->to());
        self::assertSame('100.000', $legs[0]->spent()->amount());
        self::assertSame('104.500', $legs[0]->received()->amount());
        $firstLegFees = $legs[0]->fees();
        self::assertArrayHasKey('USD', $firstLegFees);
        self::assertSame('5.500', $firstLegFees['USD']->amount());

        self::assertSame('USD', $legs[1]->from());
        self::assertSame('JPY', $legs[1]->to());
        self::assertSame('104.500', $legs[1]->spent()->amount());
        self::assertSame('15361.500', $legs[1]->received()->amount());
        $secondLegFees = $legs[1]->fees();
        self::assertArrayHasKey('JPY', $secondLegFees);
        self::assertSame('313.500', $secondLegFees['JPY']->amount());

        $rawUsdWithoutFee = Money::fromString('USD', '110.000', 3);
        $rawJpyWithoutFee = Money::fromString('JPY', '15675.000', 3);

        self::assertTrue($legs[0]->received()->lessThan($rawUsdWithoutFee));
        self::assertTrue($legs[1]->received()->lessThan($rawJpyWithoutFee));
        self::assertTrue($result->totalReceived()->lessThan($rawJpyWithoutFee));

        $feeBreakdown = $result->feeBreakdown();
        self::assertArrayHasKey('USD', $feeBreakdown);
        self::assertArrayHasKey('JPY', $feeBreakdown);
        self::assertSame('5.500', $feeBreakdown['USD']->amount());
        self::assertSame('313.500', $feeBreakdown['JPY']->amount());
    }

    public function test_it_limits_gross_spend_for_buy_legs_with_base_fees(): void
    {
        $orderBook = new OrderBook([
            $this->createOrder(
                OrderSide::BUY,
                'EUR',
                'USD',
                '20.000',
                '300.000',
                '1.250',
                3,
                $this->basePercentageFeePolicy('0.10'),
            ),
            $this->createOrder(
                OrderSide::BUY,
                'USD',
                'JPY',
                '20.000',
                '300.000',
                '140.000',
                3,
                $this->basePercentageFeePolicy('0.05'),
            ),
        ]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds(0.0, 0.20)
            ->withHopLimits(1, 3)
            ->build();

        $service = new PathFinderService(new GraphBuilder());
        $result = $service->findBestPath($orderBook, $config, 'JPY');

        self::assertNotNull($result);

        $legs = $result->legs();
        self::assertCount(2, $legs);

        $firstLeg = $legs[0];
        $secondLeg = $legs[1];

        self::assertFalse($firstLeg->spent()->greaterThan($config->spendAmount()));
        self::assertFalse($secondLeg->spent()->greaterThan($firstLeg->received()));

        self::assertArrayHasKey('EUR', $firstLeg->fees());
        self::assertArrayHasKey('USD', $secondLeg->fees());

        $totalSpent = $result->totalSpent();
        self::assertSame($firstLeg->spent()->currency(), $totalSpent->currency());

        $comparisonScale = max($firstLeg->spent()->scale(), $totalSpent->scale());
        self::assertSame(
            $firstLeg->spent()->withScale($comparisonScale)->amount(),
            $totalSpent->withScale($comparisonScale)->amount(),
        );
    }

    public function test_it_prefers_fee_efficient_direct_route_over_higher_raw_rate(): void
    {
        $orderBook = new OrderBook([
            $this->createOrder(OrderSide::BUY, 'EUR', 'USD', '50.000', '200.000', '1.250', 3, $this->percentageFeePolicy('0.10')),
            $this->createOrder(OrderSide::BUY, 'EUR', 'USD', '50.000', '200.000', '1.200', 3, $this->percentageFeePolicy('0.01')),
        ]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds(0.0, 0.0)
            ->withHopLimits(1, 1)
            ->build();

        $service = new PathFinderService(new GraphBuilder());
        $result = $service->findBestPath($orderBook, $config, 'USD');

        self::assertNotNull($result);
        self::assertSame('USD', $result->totalReceived()->currency());
        self::assertSame('118.800', $result->totalReceived()->amount());

        $legs = $result->legs();
        self::assertCount(1, $legs);
        self::assertSame('USD', $legs[0]->to());
        self::assertSame('118.800', $legs[0]->received()->amount());
        $fees = $legs[0]->fees();
        self::assertArrayHasKey('USD', $fees);
        self::assertSame('1.200', $fees['USD']->amount());
    }

    public function test_it_prefers_fee_efficient_multi_hop_route_over_high_fee_alternative(): void
    {
        $orderBook = new OrderBook([
            $this->createOrder(OrderSide::BUY, 'EUR', 'USD', '50.000', '200.000', '1.250', 3, $this->percentageFeePolicy('0.10')),
            $this->createOrder(OrderSide::BUY, 'EUR', 'USD', '50.000', '200.000', '1.200', 3, $this->percentageFeePolicy('0.01')),
            $this->createOrder(OrderSide::BUY, 'USD', 'JPY', '50.000', '200.000', '140.000', 3),
        ]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds(0.0, 0.10)
            ->withHopLimits(1, 2)
            ->build();

        $service = new PathFinderService(new GraphBuilder());
        $result = $service->findBestPath($orderBook, $config, 'JPY');

        self::assertNotNull($result);
        self::assertSame('JPY', $result->totalReceived()->currency());
        self::assertSame('16632.000', $result->totalReceived()->amount());

        $legs = $result->legs();
        self::assertCount(2, $legs);
        self::assertSame('USD', $legs[0]->to());
        self::assertSame('118.800', $legs[0]->received()->amount());
        self::assertSame('JPY', $legs[1]->to());
        self::assertSame('16632.000', $legs[1]->received()->amount());
    }

    public function test_it_skips_highest_scoring_path_when_complex_book_lacks_capacity(): void
    {
        $orderBook = new OrderBook([
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
        ]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds(0.0, 0.20)
            ->withHopLimits(1, 3)
            ->build();

        $service = new PathFinderService(new GraphBuilder());
        $result = $service->findBestPath($orderBook, $config, 'USD');

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
        $orderBook = new OrderBook([
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
        ]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds(0.0, 0.15)
            ->withHopLimits(1, 3)
            ->build();

        $service = new PathFinderService(new GraphBuilder());
        $result = $service->findBestPath($orderBook, $config, 'USD');

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

    public function test_it_discovers_buy_path_when_order_minimum_exceeds_configured_minimum(): void
    {
        $orderBook = new OrderBook([
            $this->createOrder(OrderSide::BUY, 'EUR', 'USD', '50.000', '120.000', '1.200', 3),
        ]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '40.00', 2))
            ->withToleranceBounds(0.5, 0.5)
            ->withHopLimits(1, 1)
            ->build();

        $service = new PathFinderService(new GraphBuilder());
        $result = $service->findBestPath($orderBook, $config, 'USD');

        self::assertNotNull($result);
        self::assertSame('EUR', $result->totalSpent()->currency());
        self::assertSame('50.000', $result->totalSpent()->amount());
        self::assertSame('USD', $result->totalReceived()->currency());
        self::assertSame('60.000', $result->totalReceived()->amount());
        self::assertEqualsWithDelta(0.25, $result->residualTolerance(), 1e-9);
    }

    public function test_it_discovers_sell_path_when_order_minimum_exceeds_configured_minimum(): void
    {
        $orderBook = new OrderBook([
            $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '30.000', '120.000', '0.800', 3),
        ]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '22.00', 2))
            ->withToleranceBounds(0.1, 0.5)
            ->withHopLimits(1, 1)
            ->build();

        $service = new PathFinderService(new GraphBuilder());
        $result = $service->findBestPath($orderBook, $config, 'USD');

        self::assertNotNull($result);
        self::assertSame('EUR', $result->totalSpent()->currency());
        self::assertSame('24.000', $result->totalSpent()->amount());
        self::assertSame('USD', $result->totalReceived()->currency());
        self::assertSame('30.000', $result->totalReceived()->amount());
        self::assertEqualsWithDelta(2 / 22, $result->residualTolerance(), 1e-9);
    }

    public function test_it_refines_sell_legs_until_effective_quote_matches(): void
    {
        $feePolicy = $this->tieredFeePolicy('310.000', '0.05', '0.35', '25.000');
        $order = $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '0.001', '1000.000', '0.400', 3, $feePolicy);

        $orderBook = new OrderBook([$order]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '220.000', 3))
            ->withToleranceBounds(0.0, 0.0)
            ->withHopLimits(1, 1)
            ->build();

        $service = new PathFinderService(new GraphBuilder());
        $result = $service->findBestPath($orderBook, $config, 'USD');

        self::assertNotNull($result);
        $legs = $result->legs();
        self::assertCount(1, $legs);

        $leg = $legs[0];

        $rawQuote = $order->calculateQuoteAmount($leg->received());
        $expectedBreakdown = $feePolicy->calculate(OrderSide::SELL, $leg->received(), $rawQuote);
        $expectedFee = $expectedBreakdown->quoteFee();
        self::assertNotNull($expectedFee);
        $effectiveQuote = $order->calculateEffectiveQuoteAmount($leg->received());

        $comparisonScale = max($effectiveQuote->scale(), $leg->spent()->scale(), 6);
        $actualAmount = $effectiveQuote->withScale($comparisonScale)->amount();
        $reportedAmount = $leg->spent()->withScale($comparisonScale)->amount();
        $difference = BcMath::sub($actualAmount, $reportedAmount, $comparisonScale + 6);
        if ('-' === $difference[0]) {
            $difference = substr($difference, 1);
        }

        if ('' === $difference) {
            $difference = '0';
        }

        $difference = BcMath::normalize($difference, $comparisonScale + 6);
        $relativeDifference = BcMath::div($difference, $actualAmount, $comparisonScale + 6);

        self::assertTrue(
            BcMath::comp($relativeDifference, '0.000001', $comparisonScale + 6) <= 0,
            sprintf('Effective quote mismatch of %s exceeds tolerance.', $difference),
        );

        $legFees = $leg->fees();
        self::assertArrayHasKey($expectedFee->currency(), $legFees);
        $actualFee = $legFees[$expectedFee->currency()];

        $feeScale = max($expectedFee->scale(), $actualFee->scale(), 6);
        self::assertSame(
            $expectedFee->withScale($feeScale)->amount(),
            $actualFee->withScale($feeScale)->amount(),
        );
    }

    public function test_it_returns_null_when_sell_leg_cannot_meet_target_after_refinement(): void
    {
        $feePolicy = $this->tieredFeePolicy('310.000', '0.05', '0.35', '25.000');
        $order = $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '0.001', '1000.000', '0.400', 3, $feePolicy);

        $orderBook = new OrderBook([$order]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '450.000', 3))
            ->withToleranceBounds(0.0, 0.0)
            ->withHopLimits(1, 1)
            ->build();

        $service = new PathFinderService(new GraphBuilder());

        self::assertNull($service->findBestPath($orderBook, $config, 'USD'));
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

    private function basePercentageFeePolicy(string $percentage): FeePolicy
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

    private function tieredFeePolicy(string $threshold, string $lowPercentage, string $highPercentage, string $fixed): FeePolicy
    {
        return new class($threshold, $lowPercentage, $highPercentage, $fixed) implements FeePolicy {
            public function __construct(
                private readonly string $threshold,
                private readonly string $lowPercentage,
                private readonly string $highPercentage,
                private readonly string $fixed,
            ) {
            }

            public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
            {
                $scale = max($quoteAmount->scale(), 6);
                $threshold = Money::fromString($quoteAmount->currency(), $this->threshold, $scale);

                if ($quoteAmount->greaterThan($threshold)) {
                    $percentageComponent = $quoteAmount->multiply($this->highPercentage, $scale);
                    $fixedComponent = Money::fromString($quoteAmount->currency(), $this->fixed, $scale);

                    $fee = $percentageComponent->add($fixedComponent, $scale);

                    return FeeBreakdown::forQuote($fee);
                }

                $fee = $quoteAmount->multiply($this->lowPercentage, $scale);

                return FeeBreakdown::forQuote($fee);
            }
        };
    }
}
