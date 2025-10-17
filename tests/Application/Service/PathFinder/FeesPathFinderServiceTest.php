<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Service\PathFinder;

use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Application\Service\LegMaterializer;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

use function sprintf;

final class FeesPathFinderServiceTest extends PathFinderServiceTestCase
{
    /**
     * @testdox Materializes EUR→JPY bridge with quote fees on each hop and captures fee breakdown
     */
    public function test_it_materializes_leg_fees_and_breakdown(): void
    {
        $orderBook = $this->scenarioEurToJpyBridgeWithLegFees();

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.25')
            ->withHopLimits(1, 2)
            ->build();

        $results = $this->makeService()->findBestPaths($orderBook, $config, 'JPY');

        self::assertNotSame([], $results);
        $result = $results[0];

        $legs = $result->legs();
        self::assertCount(2, $legs);

        self::assertSame('112.233', $legs[0]->received()->amount());
        $firstLegFees = $legs[0]->fees();
        self::assertArrayHasKey('EUR', $firstLegFees);
        self::assertSame('1.010', $firstLegFees['EUR']->amount());

        $grossFirstLegSpend = $legs[0]->spent();
        $this->assertGrossWithinTolerance(
            $config->spendAmount(),
            $grossFirstLegSpend,
            $config->maximumTolerance(),
            'Gross spend mismatch of %s exceeds tolerance.',
        );

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

    /**
     * @testdox Applies base-denominated fee to reduce BTC received on a direct USD sell
     */
    public function test_it_reduces_sell_leg_receipts_by_base_fee(): void
    {
        $orderBook = $this->scenarioBtcSellWithBaseFee();

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '2.00', 2))
            ->withToleranceBounds('0.0', '0.0')
            ->withHopLimits(1, 1)
            ->build();

        $results = $this->makeService()->findBestPaths($orderBook, $config, 'BTC');

        self::assertNotSame([], $results);
        $result = $results[0];

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

    /**
     * @testdox Includes EUR base fee when determining gross spend for a direct buy leg
     */
    public function test_it_includes_base_fee_in_total_spent(): void
    {
        $orderBook = $this->scenarioEurBuyWithBaseFee();

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.05')
            ->withHopLimits(1, 1)
            ->build();

        $results = $this->makeService()->findBestPaths($orderBook, $config, 'USD');

        self::assertNotSame([], $results);
        $result = $results[0];

        $legs = $result->legs();
        self::assertCount(1, $legs);

        self::assertSame('EUR', $result->totalSpent()->currency());
        self::assertSame(
            $legs[0]->spent()->withScale($result->totalSpent()->scale())->amount(),
            $result->totalSpent()->amount(),
        );
        self::assertSame('0.020000000000000000', $result->residualTolerance()->ratio());

        self::assertSame('102.000', $legs[0]->spent()->amount());

        $fees = $legs[0]->fees();
        self::assertArrayHasKey('EUR', $fees);
        self::assertSame('2.000', $fees['EUR']->amount());

        $feeBreakdown = $result->feeBreakdown();
        self::assertArrayHasKey('EUR', $feeBreakdown);
        self::assertSame('2.000', $feeBreakdown['EUR']->amount());
    }

    /**
     * @testdox Handles combined base and quote fees on a single EUR→USD buy leg
     */
    public function test_it_materializes_buy_leg_with_combined_base_and_quote_fees(): void
    {
        $orderBook = $this->scenarioEurBuyWithMixedFees();

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.05')
            ->withHopLimits(1, 1)
            ->build();

        $results = $this->makeService()->findBestPaths($orderBook, $config, 'USD');

        self::assertNotSame([], $results);
        $result = $results[0];
        self::assertSame('EUR', $result->totalSpent()->currency());
        self::assertSame('102.000', $result->totalSpent()->amount());
        self::assertSame('USD', $result->totalReceived()->currency());
        self::assertSame('114.000', $result->totalReceived()->amount());
        self::assertSame('0.020000000000000000', $result->residualTolerance()->ratio());

        $legs = $result->legs();
        self::assertCount(1, $legs);

        $leg = $legs[0];
        self::assertSame('EUR', $leg->from());
        self::assertSame('USD', $leg->to());
        self::assertSame('102.000', $leg->spent()->amount());
        self::assertSame('114.000', $leg->received()->amount());

        $fees = $leg->fees();
        self::assertArrayHasKey('EUR', $fees);
        self::assertArrayHasKey('USD', $fees);
        self::assertSame('2.000', $fees['EUR']->amount());
        self::assertSame('6.000', $fees['USD']->amount());

        $feeBreakdown = $result->feeBreakdown();
        self::assertArrayHasKey('EUR', $feeBreakdown);
        self::assertArrayHasKey('USD', $feeBreakdown);
        self::assertSame('2.000', $feeBreakdown['EUR']->amount());
        self::assertSame('6.000', $feeBreakdown['USD']->amount());
    }

    /**
     * @testdox Chains EUR→USD→JPY buy legs while propagating quote-denominated fees between hops
     */
    public function test_it_materializes_chained_buy_legs_with_fees_using_net_quotes(): void
    {
        $orderBook = $this->scenarioChainedBuyLegsWithFees();

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 3)
            ->build();

        $results = $this->makeService()->findBestPaths($orderBook, $config, 'JPY');

        self::assertNotSame([], $results);
        $result = $results[0];
        self::assertSame('EUR', $result->totalSpent()->currency());
        self::assertSame('100.000', $result->totalSpent()->amount());
        self::assertSame('JPY', $result->totalReceived()->currency());
        self::assertSame('15361.500', $result->totalReceived()->amount());
        self::assertTrue($result->residualTolerance()->isZero());
        self::assertSame('0.000000000000000000', $result->residualTolerance()->ratio());

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

    /**
     * @testdox Caps gross spend when consecutive buy legs each take base-denominated fees
     */
    public function test_it_limits_gross_spend_for_buy_legs_with_base_fees(): void
    {
        $orderBook = $this->scenarioStackedBaseFeeBuys();

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.20')
            ->withHopLimits(1, 3)
            ->build();

        $results = $this->makeService()->findBestPaths($orderBook, $config, 'JPY');

        self::assertNotSame([], $results);
        $result = $results[0];

        $legs = $result->legs();
        self::assertCount(2, $legs);

        $firstLeg = $legs[0];
        $secondLeg = $legs[1];

        $maxSpend = $config->maximumSpendAmount()->withScale($firstLeg->spent()->scale());
        self::assertFalse($firstLeg->spent()->greaterThan($maxSpend));
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

    /**
     * @testdox Prefers lower fee EUR→USD direct route even when a rival advertises a better raw rate
     */
    public function test_it_prefers_fee_efficient_direct_route_over_higher_raw_rate(): void
    {
        $orderBook = $this->scenarioHighFeeDirectRoute();

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.0')
            ->withHopLimits(1, 1)
            ->build();

        $results = $this->makeService()->findBestPaths($orderBook, $config, 'USD');

        self::assertNotSame([], $results);
        $result = $results[0];
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

    /**
     * @testdox Chooses quote-efficient USD sell route when competing leg inflates spend via fees
     */
    public function test_it_prefers_sell_route_that_limits_gross_quote_spend(): void
    {
        $orderBook = $this->scenarioQuoteEfficientSellRoute();

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.0')
            ->withHopLimits(1, 1)
            ->build();

        $results = $this->makeService()->findBestPaths($orderBook, $config, 'USD');

        self::assertNotSame([], $results);
        $result = $results[0];

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

    /**
     * @testdox Resizes downstream sell leg when quote fee would otherwise overspend received USD
     */
    public function test_it_resizes_sell_leg_when_quote_fee_would_overdraw_available_budget(): void
    {
        $orderBook = $this->scenarioSellChainRequiringQuoteFeeResizing();

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.15')
            ->withHopLimits(1, 2)
            ->build();

        $results = $this->makeService()->findBestPaths($orderBook, $config, 'BTC');

        self::assertNotSame([], $results);
        $result = $results[0];

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

    /**
     * @testdox Rejects sell chain outright when quote fee minimum cannot be funded by upstream liquidity
     */
    public function test_it_rejects_sell_leg_when_quote_fee_budget_cannot_cover_minimum(): void
    {
        $orderBook = $this->scenarioSellChainBlockedByFeeMinimum();

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.15')
            ->withHopLimits(1, 2)
            ->build();

        self::assertSame([], $this->makeService()->findBestPaths($orderBook, $config, 'BTC'));
    }

    /**
     * @testdox Picks multi-hop EUR→USD→JPY route when direct high-fee offer erodes payout
     */
    public function test_it_prefers_fee_efficient_multi_hop_route_over_high_fee_alternative(): void
    {
        $orderBook = $this->scenarioMultiHopBeatsHighFeeDirect();

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 2)
            ->build();

        $results = $this->makeService()->findBestPaths($orderBook, $config, 'JPY');

        self::assertNotSame([], $results);
        $result = $results[0];
        self::assertSame('JPY', $result->totalReceived()->currency());
        self::assertSame('16632.000', $result->totalReceived()->amount());

        $legs = $result->legs();
        self::assertCount(2, $legs);
        self::assertSame('USD', $legs[0]->to());
        self::assertSame('118.800', $legs[0]->received()->amount());
        self::assertSame('JPY', $legs[1]->to());
        self::assertSame('16632.000', $legs[1]->received()->amount());
    }

    public function test_it_refines_sell_legs_until_effective_quote_matches(): void
    {
        $feePolicy = $this->tieredFeePolicy('310.000', '0.05', '0.35', '25.000');
        $order = $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '0.001', '1000.000', '0.400', 3, $feePolicy);

        $target = Money::fromString('EUR', '200.000', 3);
        $materializer = new LegMaterializer();
        $resolved = $materializer->resolveSellLegAmounts($order, $target);

        self::assertIsArray($resolved);

        [$grossSpent, $baseReceived, $fees] = $resolved;

        $this->assertSellLegRefinementMatches($order, $feePolicy, $target, $grossSpent, $baseReceived, $fees);
    }

    public function test_it_returns_null_when_sell_leg_cannot_meet_target_after_refinement(): void
    {
        $feePolicy = $this->tieredFeePolicy('310.000', '0.05', '0.35', '25.000');
        $order = $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '0.001', '1000.000', '0.400', 3, $feePolicy);

        $orderBook = $this->orderBook($order);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '450.000', 3))
            ->withToleranceBounds('0.0', '0.0')
            ->withHopLimits(1, 1)
            ->build();

        self::assertSame([], $this->makeService()->findBestPaths($orderBook, $config, 'USD'));
    }

    private function scenarioEurToJpyBridgeWithLegFees(): OrderBook
    {
        return $this->orderBook(
            $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '10.000', '200.000', '0.900', 3, $this->percentageFeePolicy('0.01')),
            $this->createOrder(OrderSide::BUY, 'USD', 'JPY', '50.000', '200.000', '150.000', 3, $this->percentageFeePolicy('0.02')),
        );
    }

    private function scenarioBtcSellWithBaseFee(): OrderBook
    {
        return $this->orderBook(
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
        );
    }

    private function scenarioEurBuyWithBaseFee(): OrderBook
    {
        return $this->orderBook(
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
        );
    }

    private function scenarioEurBuyWithMixedFees(): OrderBook
    {
        return $this->orderBook(
            $this->createOrder(
                OrderSide::BUY,
                'EUR',
                'USD',
                '10.000',
                '200.000',
                '1.200',
                3,
                $this->mixedPercentageFeePolicy('0.02', '0.05'),
            ),
        );
    }

    private function scenarioChainedBuyLegsWithFees(): OrderBook
    {
        return $this->orderBook(
            $this->createOrder(OrderSide::BUY, 'EUR', 'USD', '50.000', '200.000', '1.100', 3, $this->percentageFeePolicy('0.05')),
            $this->createOrder(OrderSide::BUY, 'USD', 'JPY', '50.000', '200.000', '150.000', 3, $this->percentageFeePolicy('0.02')),
        );
    }

    private function scenarioStackedBaseFeeBuys(): OrderBook
    {
        return $this->orderBook(
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
        );
    }

    private function scenarioHighFeeDirectRoute(): OrderBook
    {
        return $this->orderBook(
            $this->createOrder(OrderSide::BUY, 'EUR', 'USD', '50.000', '200.000', '1.250', 3, $this->percentageFeePolicy('0.10')),
            $this->createOrder(OrderSide::BUY, 'EUR', 'USD', '50.000', '200.000', '1.200', 3, $this->percentageFeePolicy('0.01')),
        );
    }

    private function scenarioQuoteEfficientSellRoute(): OrderBook
    {
        return $this->orderBook(
            $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '50.000', '200.000', '0.900', 3, $this->percentageFeePolicy('0.10')),
            $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '50.000', '200.000', '0.880', 3),
        );
    }

    private function scenarioSellChainRequiringQuoteFeeResizing(): OrderBook
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

        return $this->orderBook($firstLegOrder, $secondLegOrder);
    }

    private function scenarioSellChainBlockedByFeeMinimum(): OrderBook
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

        return $this->orderBook($firstLegOrder, $secondLegOrder);
    }

    private function scenarioMultiHopBeatsHighFeeDirect(): OrderBook
    {
        return $this->orderBook(
            $this->createOrder(OrderSide::BUY, 'EUR', 'USD', '50.000', '200.000', '1.250', 3, $this->percentageFeePolicy('0.10')),
            $this->createOrder(OrderSide::BUY, 'EUR', 'USD', '50.000', '200.000', '1.200', 3, $this->percentageFeePolicy('0.01')),
            $this->createOrder(OrderSide::BUY, 'USD', 'JPY', '50.000', '200.000', '140.000', 3),
        );
    }
}
