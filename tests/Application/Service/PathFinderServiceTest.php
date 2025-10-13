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

        self::assertSame('110.011', $legs[0]->received()->amount());
        $firstLegFees = $legs[0]->fees();
        self::assertArrayHasKey('EUR', $firstLegFees);
        self::assertSame('0.990', $firstLegFees['EUR']->amount());

        $grossFirstLegSpend = $legs[0]->spent();
        $firstLegFee = $firstLegFees['EUR'];

        $grossScale = max(
            $grossFirstLegSpend->scale(),
            $config->spendAmount()->scale(),
        );

        $requestedGross = $config->spendAmount()->withScale($grossScale)->amount();
        $actualGross = $grossFirstLegSpend->withScale($grossScale)->amount();
        $difference = BcMath::sub($actualGross, $requestedGross, $grossScale + 6);
        if ('-' === $difference[0]) {
            $difference = substr($difference, 1);
        }

        if ('' === $difference) {
            $difference = '0';
        }

        $difference = BcMath::normalize($difference, $grossScale + 6);
        $relativeDifference = BcMath::div($difference, $requestedGross, $grossScale + 6);

        self::assertTrue(
            BcMath::comp(
                $relativeDifference,
                BcMath::normalize(sprintf('%.'.($grossScale + 6).'F', $config->maximumTolerance()), $grossScale + 6),
                $grossScale + 6,
            ) <= 0,
            sprintf('Gross spend mismatch of %s exceeds tolerance.', $difference),
        );

        self::assertSame('110.011', $legs[1]->spent()->amount());
        self::assertSame('16171.617', $legs[1]->received()->amount());
        $secondLegFees = $legs[1]->fees();
        self::assertArrayHasKey('JPY', $secondLegFees);
        self::assertSame('330.033', $secondLegFees['JPY']->amount());

        $rawWithoutFee = Money::fromString('JPY', '16501.650', 3);
        self::assertTrue($legs[1]->received()->lessThan($rawWithoutFee));

        $feeBreakdown = $result->feeBreakdown();
        self::assertCount(2, $feeBreakdown);
        self::assertArrayHasKey('EUR', $feeBreakdown);
        self::assertArrayHasKey('JPY', $feeBreakdown);
        self::assertSame('0.990', $feeBreakdown['EUR']->amount());
        self::assertSame('330.033', $feeBreakdown['JPY']->amount());

        self::assertTrue($result->totalReceived()->lessThan($rawWithoutFee));
    }

    public function test_it_reduces_sell_leg_receipts_by_base_fee(): void
    {
        $orderBook = new OrderBook([
            $this->createOrder(
                OrderSide::SELL,
                'BTC',
                'USD',
                '1.000',
                '3.000',
                '2.000',
                3,
                $this->basePercentageFeePolicy('0.10'),
            ),
        ]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '2.00', 2))
            ->withToleranceBounds(0.0, 0.0)
            ->withHopLimits(1, 1)
            ->build();

        $service = new PathFinderService(new GraphBuilder());
        $result = $service->findBestPath($orderBook, $config, 'BTC');

        self::assertNotNull($result);
        self::assertSame('BTC', $result->totalReceived()->currency());
        self::assertSame('0.900', $result->totalReceived()->amount());

        $legs = $result->legs();
        self::assertCount(1, $legs);

        $leg = $legs[0];
        self::assertSame('USD', $leg->from());
        self::assertSame('BTC', $leg->to());
        self::assertSame('2.000', $leg->spent()->amount());
        self::assertSame('0.900', $leg->received()->amount());

        $fees = $leg->fees();
        self::assertArrayHasKey('BTC', $fees);
        self::assertSame('0.100', $fees['BTC']->amount());
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

        $legs = $result->legs();
        self::assertCount(1, $legs);

        self::assertSame('EUR', $result->totalSpent()->currency());
        self::assertSame(
            $legs[0]->spent()->withScale($result->totalSpent()->scale())->amount(),
            $result->totalSpent()->amount(),
        );
        self::assertSame(0.0, $result->residualTolerance());

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

    public function test_it_prefers_sell_route_that_limits_gross_quote_spend(): void
    {
        $orderBook = new OrderBook([
            $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '50.000', '200.000', '0.900', 3, $this->percentageFeePolicy('0.10')),
            $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '50.000', '200.000', '0.880', 3),
        ]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds(0.0, 0.0)
            ->withHopLimits(1, 1)
            ->build();

        $service = new PathFinderService(new GraphBuilder());
        $result = $service->findBestPath($orderBook, $config, 'USD');

        self::assertNotNull($result);

        $legs = $result->legs();
        self::assertCount(1, $legs);

        $leg = $legs[0];
        self::assertSame('EUR', $leg->from());
        self::assertSame('USD', $leg->to());
        self::assertCount(0, $leg->fees());

        self::assertSame('113.600', $leg->received()->withScale(3)->amount());

        $grossSpend = $leg->spent();
        $grossScale = max($grossSpend->scale(), $config->spendAmount()->scale());

        self::assertSame(
            $config->spendAmount()->withScale($grossScale)->amount(),
            $grossSpend->withScale($grossScale)->amount(),
        );

        self::assertSame('113.600', $result->totalReceived()->withScale(3)->amount());

        $highFeeGross = $config
            ->spendAmount()
            ->withScale($grossScale)
            ->divide('0.90', $grossScale)
            ->multiply('1.10', $grossScale);

        self::assertTrue(
            $result->totalSpent()->withScale($grossScale)->lessThan($highFeeGross->withScale($grossScale)),
        );
    }

    public function test_it_resizes_sell_leg_when_quote_fee_would_overdraw_available_budget(): void
    {
        $firstLegOrder = $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '0.000', '200.000', '1.000', 3);
        $secondLegOrder = $this->createOrder(
            OrderSide::SELL,
            'BTC',
            'USD',
            '0.000',
            '5.000',
            '100.000',
            3,
            $this->percentageFeePolicy('0.10'),
        );

        $orderBook = new OrderBook([$firstLegOrder, $secondLegOrder]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds(0.0, 0.15)
            ->withHopLimits(1, 2)
            ->build();

        $service = new PathFinderService(new GraphBuilder());
        $result = $service->findBestPath($orderBook, $config, 'BTC');

        self::assertNotNull($result);

        $legs = $result->legs();
        self::assertCount(2, $legs);

        [$firstLeg, $secondLeg] = $legs;

        $comparisonScale = max($firstLeg->received()->scale(), $secondLeg->spent()->scale());
        $firstLegReceived = $firstLeg->received()->withScale($comparisonScale);
        $secondLegSpent = $secondLeg->spent()->withScale($comparisonScale);

        self::assertFalse($secondLeg->spent()->greaterThan($firstLeg->received()));

        $difference = BcMath::sub($firstLegReceived->amount(), $secondLegSpent->amount(), $comparisonScale + 6);
        if ('-' === $difference[0]) {
            $difference = substr($difference, 1);
        }
        if ('' === $difference) {
            $difference = '0';
        }
        $difference = BcMath::normalize($difference, $comparisonScale + 6);

        self::assertTrue(
            BcMath::comp($difference, BcMath::normalize('0.02', $comparisonScale + 6), $comparisonScale + 6) <= 0,
            sprintf('Gross quote spend exceeded available budget by %s.', $difference),
        );

        $fees = $secondLeg->fees();
        self::assertArrayHasKey('USD', $fees);

        $quoteFee = $fees['USD'];
        self::assertTrue($quoteFee->greaterThan(Money::zero('USD', $quoteFee->scale())));

        $rawQuote = $secondLeg->spent()->subtract($quoteFee, max($secondLeg->spent()->scale(), $quoteFee->scale()));
        self::assertTrue($rawQuote->lessThan($secondLeg->spent()));

        $resultSpent = $result->totalSpent()->withScale($config->spendAmount()->scale());
        self::assertSame($config->spendAmount()->withScale($resultSpent->scale())->amount(), $resultSpent->amount());
    }

    public function test_it_rejects_sell_leg_when_quote_fee_budget_cannot_cover_minimum(): void
    {
        $firstLegOrder = $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '0.000', '200.000', '1.000', 3);
        $secondLegOrder = $this->createOrder(
            OrderSide::SELL,
            'BTC',
            'USD',
            '1.000',
            '5.000',
            '100.000',
            3,
            $this->percentageFeePolicy('0.10'),
        );

        $orderBook = new OrderBook([$firstLegOrder, $secondLegOrder]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds(0.0, 0.15)
            ->withHopLimits(1, 2)
            ->build();

        $service = new PathFinderService(new GraphBuilder());

        self::assertNull($service->findBestPath($orderBook, $config, 'BTC'));
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

    public function test_it_filters_sell_orders_exceeding_gross_tolerance(): void
    {
        $orderBook = new OrderBook([
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
        ]);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds(0.0, 0.05)
            ->withHopLimits(1, 1)
            ->build();

        $service = new PathFinderService(new GraphBuilder());

        self::assertNull($service->findBestPath($orderBook, $config, 'USD'));
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

        $service = new PathFinderService(new GraphBuilder());
        $reflection = new \ReflectionClass(PathFinderService::class);
        $method = $reflection->getMethod('resolveSellLegAmounts');
        $method->setAccessible(true);

        $target = Money::fromString('EUR', '200.000', 3);
        $resolved = $method->invoke($service, $order, $target);

        self::assertIsArray($resolved);

        [$grossSpent, $baseReceived, $fees] = $resolved;

        $baseFill = $baseReceived;
        $baseFee = $fees->baseFee();
        if (null !== $baseFee && !$baseFee->isZero()) {
            $baseFill = $baseReceived->add($baseFee);
        }

        $rawQuote = $order->calculateQuoteAmount($baseFill);
        $expectedBreakdown = $feePolicy->calculate(OrderSide::SELL, $baseFill, $rawQuote);
        $expectedFee = $expectedBreakdown->quoteFee();
        self::assertNotNull($expectedFee);
        $effectiveQuote = $order->calculateEffectiveQuoteAmount($baseFill);

        $comparisonScale = max($effectiveQuote->scale(), $target->scale(), 6);
        $actualAmount = $effectiveQuote->withScale($comparisonScale)->amount();
        $targetAmount = $target->withScale($comparisonScale)->amount();
        $difference = BcMath::sub($actualAmount, $targetAmount, $comparisonScale + 6);
        if ('-' === $difference[0]) {
            $difference = substr($difference, 1);
        }

        if ('' === $difference) {
            $difference = '0';
        }

        $difference = BcMath::normalize($difference, $comparisonScale + 6);
        $difference = BcMath::normalize($difference, $comparisonScale + 6);
        $relativeDifference = BcMath::div($difference, $targetAmount, $comparisonScale + 6);

        self::assertTrue(
            BcMath::comp($relativeDifference, '0.00001', $comparisonScale + 6) <= 0,
            sprintf('Effective quote mismatch of %s exceeds tolerance.', $difference),
        );

        $grossComparisonScale = max($rawQuote->scale(), $expectedFee->scale(), $grossSpent->scale(), 6);
        $expectedGross = $rawQuote->add($expectedFee, $grossComparisonScale);

        self::assertSame(
            $expectedGross->withScale($grossComparisonScale)->amount(),
            $grossSpent->withScale($grossComparisonScale)->amount(),
        );

        $actualFee = $fees->quoteFee();
        self::assertNotNull($actualFee);

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
