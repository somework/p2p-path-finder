<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Service;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\Graph\GraphEdge;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\PathEdge;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\PathEdgeSequence;
use SomeWork\P2PPathFinder\Application\Service\LegMaterializer;
use SomeWork\P2PPathFinder\Application\Service\OrderSpendAnalyzer;
use SomeWork\P2PPathFinder\Domain\Order\FeeBreakdown;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\AssetPair;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Domain\ValueObject\OrderBounds;
use SomeWork\P2PPathFinder\Tests\Fixture\FeePolicyFactory;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

final class LegMaterializerTest extends TestCase
{
    public function test_it_materializes_multi_leg_path(): void
    {
        $orders = [
            $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '10.000', '200.000', '0.900', 3),
            $this->createOrder(OrderSide::BUY, 'USD', 'JPY', '50.000', '200.000', '150.000', 3),
        ];

        $graph = (new GraphBuilder())->build($orders);
        $edges = [
            $graph['EUR']['edges'][0],
            $graph['USD']['edges'][0],
        ];

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.25')
            ->withHopLimits(1, 3)
            ->build();

        $materializer = new LegMaterializer();
        $analyzer = new OrderSpendAnalyzer(null, $materializer);

        $initialSeed = $analyzer->determineInitialSpendAmount($config, $edges[0]);
        self::assertNotNull($initialSeed);

        $materialized = $materializer->materialize($this->pathEdges($edges), $config->spendAmount(), $initialSeed, 'JPY');
        self::assertNotNull($materialized);

        self::assertSame('EUR', $materialized['totalSpent']->currency());
        self::assertSame('100.000', $materialized['totalSpent']->amount());
        self::assertSame('JPY', $materialized['totalReceived']->currency());
        self::assertSame('16665.000', $materialized['totalReceived']->amount());
        self::assertCount(2, $materialized['legs']);
        self::assertSame('100.000', $materialized['toleranceSpent']->amount());
    }

    public function test_it_rejects_sell_leg_exceeding_budget(): void
    {
        $order = $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '10.000', '200.000', '0.900', 3);
        $materializer = new LegMaterializer();

        $target = Money::fromString('EUR', '100.000', 3);
        $insufficientBudget = Money::fromString('EUR', '50.000', 3);
        self::assertNull($materializer->resolveSellLegAmounts($order, $target, $insufficientBudget));

        $sufficientBudget = Money::fromString('EUR', '100.000', 3);
        $resolved = $materializer->resolveSellLegAmounts($order, $target, $sufficientBudget);
        self::assertNotNull($resolved);

        [$spent, $received] = $resolved;
        self::assertSame('EUR', $spent->currency());
        self::assertSame('USD', $received->currency());
    }

    public function test_it_materializes_legs_with_fees_and_partial_tolerance_consumption(): void
    {
        $orders = [
            OrderFactory::sell(
                'AAA',
                'USD',
                '10.000',
                '500.000',
                '1.000',
                3,
                3,
                FeePolicyFactory::baseAndQuoteSurcharge('0.050', '0.020', 6),
            ),
            OrderFactory::buy(
                'AAA',
                'EUR',
                '5.000',
                '500.000',
                '2.000',
                3,
                3,
                FeePolicyFactory::baseAndQuoteSurcharge('0.030', '0.015', 6),
            ),
        ];

        $graph = (new GraphBuilder())->build($orders);
        $edges = [
            $graph['USD']['edges'][0],
            $graph['AAA']['edges'][0],
        ];

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.000', 3))
            ->withToleranceBounds('0.0', '0.15')
            ->withHopLimits(1, 3)
            ->build();

        $materializer = new LegMaterializer();
        $analyzer = new OrderSpendAnalyzer(null, $materializer);

        $initialSeed = $analyzer->determineInitialSpendAmount($config, $edges[0]);
        self::assertNotNull($initialSeed);

        $materialized = $materializer->materialize($this->pathEdges($edges), $config->spendAmount(), $initialSeed, 'EUR');
        self::assertNotNull($materialized);

        self::assertSame('USD', $materialized['totalSpent']->currency());
        self::assertSame('104.082', $materialized['totalSpent']->amount());
        self::assertSame('EUR', $materialized['totalReceived']->currency());
        self::assertSame('185.409', $materialized['totalReceived']->amount());
        self::assertSame('USD', $materialized['toleranceSpent']->currency());
        self::assertSame('104.082', $materialized['toleranceSpent']->amount());
        self::assertTrue($config->maximumSpendAmount()->greaterThan($materialized['totalSpent']));

        $feeBreakdown = $materialized['feeBreakdown'];
        $feeBreakdownMap = $feeBreakdown->toArray();
        self::assertArrayHasKey('AAA', $feeBreakdownMap);
        self::assertArrayHasKey('USD', $feeBreakdownMap);
        self::assertArrayHasKey('EUR', $feeBreakdownMap);
        self::assertSame('7.925', $feeBreakdownMap['AAA']->amount());
        self::assertSame('2.041', $feeBreakdownMap['USD']->amount());
        self::assertSame('2.823', $feeBreakdownMap['EUR']->amount());

        $legs = $materialized['legs'];
        self::assertCount(2, $legs);

        $firstLeg = $legs[0];
        self::assertSame('USD', $firstLeg->from());
        self::assertSame('AAA', $firstLeg->to());
        self::assertSame('104.082', $firstLeg->spent()->amount());
        self::assertSame('USD', $firstLeg->spent()->currency());
        self::assertSame('96.939', $firstLeg->received()->amount());
        self::assertSame('AAA', $firstLeg->received()->currency());
        $firstFees = $firstLeg->fees();
        self::assertCount(2, $firstFees);
        self::assertSame('5.102', $firstFees['AAA']->amount());
        self::assertSame('2.041', $firstFees['USD']->amount());

        $secondLeg = $legs[1];
        self::assertSame('AAA', $secondLeg->from());
        self::assertSame('EUR', $secondLeg->to());
        self::assertSame('96.939', $secondLeg->spent()->amount());
        self::assertSame('AAA', $secondLeg->spent()->currency());
        self::assertSame('185.409', $secondLeg->received()->amount());
        self::assertSame('EUR', $secondLeg->received()->currency());
        $secondFees = $secondLeg->fees();
        self::assertCount(2, $secondFees);
        self::assertSame('2.823', $secondFees['AAA']->amount());
        self::assertSame('2.823', $secondFees['EUR']->amount());
    }

    public function test_it_rejects_non_contiguous_edge_sequences(): void
    {
        $orders = [
            $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '10.000', '200.000', '0.900', 3),
            $this->createOrder(OrderSide::BUY, 'USD', 'JPY', '50.000', '200.000', '150.000', 3),
        ];

        $graph = (new GraphBuilder())->build($orders);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.25')
            ->withHopLimits(1, 3)
            ->build();

        $materializer = new LegMaterializer();
        $analyzer = new OrderSpendAnalyzer(null, $materializer);

        $initialSeed = $analyzer->determineInitialSpendAmount($config, $graph['EUR']['edges'][0]);
        self::assertNotNull($initialSeed);

        $misorderedEdges = [
            $graph['USD']['edges'][0],
            $graph['EUR']['edges'][0],
        ];

        self::assertNull(
            $materializer->materialize($this->pathEdges($misorderedEdges), $config->spendAmount(), $initialSeed, 'JPY')
        );
    }

    public function test_materialize_consumes_tolerance_budget_on_initial_buy_leg(): void
    {
        $order = OrderFactory::buy(
            base: 'USD',
            quote: 'EUR',
            minAmount: '10.000',
            maxAmount: '200.000',
            rate: '0.900',
            amountScale: 3,
            rateScale: 3,
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $graph['USD']['edges'][0];

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '40.000', 3))
            ->withToleranceBounds('0.0', '0.20')
            ->withHopLimits(1, 1)
            ->build();

        $materializer = new LegMaterializer();
        $analyzer = new OrderSpendAnalyzer(null, $materializer);

        $initialSeed = $analyzer->determineInitialSpendAmount($config, $edge);
        self::assertNotNull($initialSeed);

        $materialized = $materializer->materialize($this->pathEdges([$edge]), $config->spendAmount(), $initialSeed, 'EUR');
        self::assertNotNull($materialized);

        self::assertSame('USD', $materialized['totalSpent']->currency());
        self::assertSame('USD', $materialized['toleranceSpent']->currency());
        self::assertSame($materialized['totalSpent']->amount(), $materialized['toleranceSpent']->amount());
        self::assertNotSame('0.000', $materialized['totalSpent']->amount());
    }

    public function test_it_rejects_when_final_currency_does_not_match_target(): void
    {
        $orders = [
            $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '10.000', '200.000', '0.900', 3),
            $this->createOrder(OrderSide::BUY, 'USD', 'JPY', '50.000', '200.000', '150.000', 3),
        ];

        $graph = (new GraphBuilder())->build($orders);
        $edges = [
            $graph['EUR']['edges'][0],
            $graph['USD']['edges'][0],
        ];

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.25')
            ->withHopLimits(1, 3)
            ->build();

        $materializer = new LegMaterializer();
        $analyzer = new OrderSpendAnalyzer(null, $materializer);

        $initialSeed = $analyzer->determineInitialSpendAmount($config, $edges[0]);
        self::assertNotNull($initialSeed);

        self::assertNull(
            $materializer->materialize($this->pathEdges($edges), $config->spendAmount(), $initialSeed, 'USD')
        );
    }

    public function test_resolve_buy_fill_rejects_when_minimum_spend_exceeds_ceiling(): void
    {
        $order = OrderFactory::buy('AAA', 'USD', '5.000', '10.000', '2.000', 3, 3);
        $materializer = new LegMaterializer();

        $netSeed = Money::fromString('AAA', '5.000', 3);
        $grossSeed = Money::fromString('AAA', '5.000', 3);
        $insufficientCeiling = Money::fromString('AAA', '4.999', 3);

        self::assertNull(
            $materializer->resolveBuyFill($order, $netSeed, $grossSeed, $insufficientCeiling)
        );
    }

    public function test_resolve_buy_fill_rejects_when_budget_ratio_collapses(): void
    {
        $order = OrderFactory::buy('AAA', 'USD', '0.000', '10.000', '2.000', 3, 3);
        $materializer = new LegMaterializer();

        $netSeed = Money::fromString('AAA', '5.000', 3);
        $grossSeed = Money::fromString('AAA', '5.000', 3);
        $zeroCeiling = Money::fromString('AAA', '0.000', 3);

        self::assertNull(
            $materializer->resolveBuyFill($order, $netSeed, $grossSeed, $zeroCeiling),
            'Expected the adjustment ratio to collapse to zero, causing a null result.'
        );
    }

    public function test_calculate_sell_adjustment_ratio_returns_null_when_actual_zero(): void
    {
        $materializer = new LegMaterializer();
        $method = new ReflectionMethod(LegMaterializer::class, 'calculateSellAdjustmentRatio');
        $method->setAccessible(true);

        $target = Money::fromString('USD', '100.000', 3);
        $actual = Money::fromString('USD', '0.000', 3);

        self::assertNull($method->invoke($materializer, $target, $actual, 3));
    }

    public function test_calculate_sell_adjustment_ratio_rejects_sign_mismatch(): void
    {
        $materializer = new LegMaterializer();
        $method = new ReflectionMethod(LegMaterializer::class, 'calculateSellAdjustmentRatio');
        $method->setAccessible(true);

        $target = Money::fromString('USD', '100.000', 3);
        $actual = Money::fromString('USD', '-50.000', 3);

        self::assertNull($method->invoke($materializer, $target, $actual, 3));
    }

    public function test_calculate_sell_adjustment_ratio_returns_precise_ratio(): void
    {
        $materializer = new LegMaterializer();
        $method = new ReflectionMethod(LegMaterializer::class, 'calculateSellAdjustmentRatio');
        $method->setAccessible(true);

        $target = Money::fromString('USD', '95.000', 3);
        $actual = Money::fromString('USD', '100.000', 3);

        $ratio = $method->invoke($materializer, $target, $actual, 3);

        self::assertSame(BcMath::div('95.000', '100.000', 9), $ratio);
    }

    public function test_is_within_sell_resolution_tolerance_requires_matching_zeroes(): void
    {
        $materializer = new LegMaterializer();
        $method = new ReflectionMethod(LegMaterializer::class, 'isWithinSellResolutionTolerance');
        $method->setAccessible(true);

        $targetZero = Money::fromString('USD', '0.000', 3);
        $actualZero = Money::fromString('USD', '0.000', 3);
        $actualPositive = Money::fromString('USD', '0.001', 3);

        self::assertTrue($method->invoke($materializer, $targetZero, $actualZero));
        self::assertFalse($method->invoke($materializer, $targetZero, $actualPositive));
    }

    public function test_is_within_sell_resolution_tolerance_obeys_relative_threshold(): void
    {
        $materializer = new LegMaterializer();
        $method = new ReflectionMethod(LegMaterializer::class, 'isWithinSellResolutionTolerance');
        $method->setAccessible(true);

        $target = Money::fromString('USD', '100.000000', 6);
        $withinTolerance = Money::fromString('USD', '100.000050', 6);
        $outsideTolerance = Money::fromString('USD', '100.100000', 6);

        self::assertTrue($method->invoke($materializer, $target, $withinTolerance));
        self::assertFalse($method->invoke($materializer, $target, $outsideTolerance));
    }

    public function test_convert_fees_to_map_filters_zero_and_sorts(): void
    {
        $materializer = new LegMaterializer();
        $method = new ReflectionMethod(LegMaterializer::class, 'convertFeesToMap');
        $method->setAccessible(true);

        $fees = FeeBreakdown::of(
            Money::fromString('ZZZ', '1.250', 3),
            Money::fromString('AAA', '0.750', 3),
        );

        $map = $method->invoke($materializer, $fees);
        $mapArray = $map->toArray();

        self::assertSame(['AAA', 'ZZZ'], array_keys($mapArray));
        self::assertSame('0.750', $map['AAA']->amount());
        self::assertSame('1.250', $map['ZZZ']->amount());

        $zeroFees = FeeBreakdown::of(Money::zero('AAA', 3), Money::zero('BBB', 3));
        $zeroMap = $method->invoke($materializer, $zeroFees);
        self::assertTrue($zeroMap->isEmpty());
    }

    public function test_reduce_budget_clamps_to_zero_when_spend_exceeds_budget(): void
    {
        $materializer = new LegMaterializer();
        $method = new ReflectionMethod(LegMaterializer::class, 'reduceBudget');
        $method->setAccessible(true);

        $budget = Money::fromString('USD', '100.00', 2);
        $spent = Money::fromString('USD', '250.00', 2);

        $remaining = $method->invoke($materializer, $budget, $spent);

        self::assertSame('0.00', $remaining->amount());
    }

    public function test_reduce_budget_ignores_spend_in_different_currency(): void
    {
        $materializer = new LegMaterializer();
        $method = new ReflectionMethod(LegMaterializer::class, 'reduceBudget');
        $method->setAccessible(true);

        $budget = Money::fromString('USD', '100.00', 2);
        $spent = Money::fromString('EUR', '50.00', 2);

        $remaining = $method->invoke($materializer, $budget, $spent);

        self::assertSame($budget->amount(), $remaining->amount());
        self::assertSame($budget->currency(), $remaining->currency());
    }

    public function test_resolve_sell_leg_amounts_respects_available_quote_budget_with_fees(): void
    {
        $order = OrderFactory::sell(
            'AAA',
            'USD',
            '10.000',
            '500.000',
            '1.000',
            3,
            3,
            FeePolicyFactory::baseAndQuoteSurcharge('0.050', '0.020', 6),
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $graph['USD']['edges'][0];

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.000', 3))
            ->withToleranceBounds('0.0', '0.15')
            ->withHopLimits(1, 3)
            ->build();

        $materializer = new LegMaterializer();
        $analyzer = new OrderSpendAnalyzer(null, $materializer);
        $seed = $analyzer->determineInitialSpendAmount($config, $edge);

        self::assertNotNull($seed);

        $resolved = $materializer->resolveSellLegAmounts($order, $seed['net'], $seed['gross']);

        self::assertNotNull($resolved);
        [$grossSpent, $baseReceived, $fees] = $resolved;

        self::assertSame($seed['gross']->amount(), $grossSpent->withScale($seed['gross']->scale())->amount());
        self::assertSame('AAA', $baseReceived->currency());

        $quoteFee = $fees->quoteFee();
        self::assertNotNull($quoteFee);
        self::assertTrue($quoteFee->greaterThan(Money::zero('USD', $quoteFee->scale())));

        $tightBudget = $seed['gross']->subtract(Money::fromString('USD', '0.001', 3));
        $adjusted = $materializer->resolveSellLegAmounts($order, $seed['net'], $tightBudget);
        self::assertNotNull($adjusted);
        self::assertFalse($adjusted[0]->greaterThan($tightBudget));

        $overlyTightBudget = Money::fromString('USD', '50.000', 3);
        $clamped = $materializer->resolveSellLegAmounts($order, $seed['net'], $overlyTightBudget);
        self::assertNotNull($clamped);
        self::assertFalse($clamped[0]->greaterThan($overlyTightBudget));
        self::assertTrue($clamped[1]->lessThan($baseReceived));
    }

    public function test_materialize_returns_null_when_terminal_currency_does_not_match_target(): void
    {
        $orders = [
            OrderFactory::buy('EUR', 'USD', '10.000', '200.000', '1.100', 3, 3),
            OrderFactory::buy('USD', 'GBP', '10.000', '200.000', '0.800', 3, 3),
        ];

        $graph = (new GraphBuilder())->build($orders);
        $edges = [
            $graph['EUR']['edges'][0],
            $graph['USD']['edges'][0],
        ];

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '50.00', 2))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 2)
            ->build();

        $materializer = new LegMaterializer();
        $analyzer = new OrderSpendAnalyzer(null, $materializer);
        $initialSeed = $analyzer->determineInitialSpendAmount($config, $edges[0]);

        self::assertNotNull($initialSeed);

        self::assertNull(
            $materializer->materialize($this->pathEdges($edges), $config->spendAmount(), $initialSeed, 'JPY'),
        );
    }

    public function test_materialize_returns_null_when_buy_leg_cannot_fit_budget(): void
    {
        $order = OrderFactory::buy(
            base: 'USD',
            quote: 'EUR',
            minAmount: '50.000',
            maxAmount: '150.000',
            rate: '0.900',
            amountScale: 3,
            rateScale: 3,
            feePolicy: FeePolicyFactory::baseAndQuoteSurcharge('0.020', '0.015', 4),
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $graph['USD']['edges'][0];

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '80.000', 3))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 1)
            ->build();

        $materializer = new LegMaterializer();
        $analyzer = new OrderSpendAnalyzer(null, $materializer);

        $initialSeed = $analyzer->determineInitialSpendAmount($config, $edge);
        self::assertNotNull($initialSeed);

        $insufficientSeed = $initialSeed;
        $insufficientSeed['grossCeiling'] = Money::fromString(
            $initialSeed['grossCeiling']->currency(),
            '0.000',
            $initialSeed['grossCeiling']->scale(),
        );

        self::assertNull(
            $materializer->materialize($this->pathEdges([$edge]), $config->spendAmount(), $insufficientSeed, 'EUR')
        );
    }

    public function test_resolve_sell_leg_amounts_rejects_when_effective_quote_outside_bounds(): void
    {
        $order = OrderFactory::sell(
            base: 'AAA',
            quote: 'USD',
            minAmount: '5.000',
            maxAmount: '10.000',
            rate: '1.250',
            amountScale: 3,
            rateScale: 3,
        );

        $materializer = new LegMaterializer();
        $target = Money::fromString('USD', '20.001', 3);

        self::assertNull($materializer->resolveSellLegAmounts($order, $target));
    }

    public function test_resolve_buy_fill_adjusts_candidate_to_budget_ceiling(): void
    {
        $order = OrderFactory::buy(
            base: 'USD',
            quote: 'BTC',
            minAmount: '100.000',
            maxAmount: '600.000',
            rate: '0.010000',
            amountScale: 3,
            rateScale: 6,
            feePolicy: FeePolicyFactory::baseSurcharge('0.150'),
        );

        $materializer = new LegMaterializer();

        $netSeed = Money::fromString('USD', '500.000', 3);
        $grossSeed = Money::fromString('USD', '500.000', 3);
        $grossCeiling = Money::fromString('USD', '520.000', 3);

        $resolved = $materializer->resolveBuyFill($order, $netSeed, $grossSeed, $grossCeiling);

        self::assertNotNull($resolved);
        self::assertFalse($resolved['gross']->greaterThan($grossCeiling));
        self::assertTrue($resolved['gross']->greaterThan($resolved['net']));
        self::assertSame('BTC', $resolved['quote']->currency());
    }

    public function test_materialize_consumes_tolerance_budget_before_switching_currencies(): void
    {
        $orders = [
            OrderFactory::buy(
                'USD',
                'EUR',
                '50.000',
                '120.000',
                '0.9000',
                3,
                4,
                FeePolicyFactory::quotePercentageWithFixed('0.010', '2.00', 4),
            ),
            OrderFactory::buy(
                'EUR',
                'JPY',
                '30.000',
                '150.000',
                '150.000',
                3,
                3,
                FeePolicyFactory::baseAndQuoteSurcharge('0.020', '0.015', 5),
            ),
        ];

        $graph = (new GraphBuilder())->build($orders);
        $edges = [
            $graph['USD']['edges'][0],
            $graph['EUR']['edges'][0],
        ];

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.000', 3))
            ->withToleranceBounds('0.0', '0.25')
            ->withHopLimits(1, 3)
            ->build();

        $materializer = new LegMaterializer();
        $analyzer = new OrderSpendAnalyzer(null, $materializer);
        $initialSeed = $analyzer->determineInitialSpendAmount($config, $edges[0]);

        self::assertNotNull($initialSeed);

        $materialized = $materializer->materialize($this->pathEdges($edges), $config->spendAmount(), $initialSeed, 'JPY');

        self::assertNotNull($materialized);
        self::assertSame('USD', $materialized['totalSpent']->currency());
        self::assertSame($materialized['totalSpent']->amount(), $materialized['toleranceSpent']->amount());
        self::assertCount(2, $materialized['legs']);
        self::assertSame('USD', $materialized['legs'][0]->spent()->currency());
        self::assertSame('EUR', $materialized['legs'][0]->received()->currency());
        self::assertSame('EUR', $materialized['legs'][1]->spent()->currency());
        self::assertSame('JPY', $materialized['legs'][1]->received()->currency());
    }

    public function test_reduce_budget_handles_currency_mismatch_and_zero_clamp(): void
    {
        $materializer = new LegMaterializer();
        $method = new ReflectionMethod(LegMaterializer::class, 'reduceBudget');
        $method->setAccessible(true);

        $budget = Money::fromString('USD', '100.000', 3);

        $foreignSpend = Money::fromString('EUR', '25.000', 3);
        /** @var Money $unchanged */
        $unchanged = $method->invoke($materializer, $budget, $foreignSpend);
        self::assertSame('USD', $unchanged->currency());
        self::assertSame('100.000', $unchanged->amount());

        $partialSpend = Money::fromString('USD', '40.000', 3);
        /** @var Money $reduced */
        $reduced = $method->invoke($materializer, $budget, $partialSpend);
        self::assertSame('USD', $reduced->currency());
        self::assertSame('60.000', $reduced->amount());

        $overspend = Money::fromString('USD', '250.000', 3);
        /** @var Money $depleted */
        $depleted = $method->invoke($materializer, $budget, $overspend);
        self::assertSame('USD', $depleted->currency());
        self::assertSame('0.000', $depleted->amount());
    }

    public function test_resolve_buy_leg_amounts_returns_materialized_components(): void
    {
        $order = OrderFactory::buy(
            base: 'USD',
            quote: 'EUR',
            minAmount: '95.000',
            maxAmount: '150.000',
            rate: '0.900',
            amountScale: 3,
            rateScale: 3,
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $graph['USD']['edges'][0];

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '120.000', 3))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 1)
            ->build();

        $materializer = new LegMaterializer();
        $analyzer = new OrderSpendAnalyzer(null, $materializer);

        $seed = $analyzer->determineInitialSpendAmount($config, $edge);
        self::assertNotNull($seed);

        $method = new ReflectionMethod(LegMaterializer::class, 'resolveBuyLegAmounts');
        $method->setAccessible(true);

        /** @var array{0: Money, 1: Money, 2: FeeBreakdown}|null $resolved */
        $resolved = $method->invoke(
            $materializer,
            $order,
            $seed['net'],
            $seed['gross'],
            $seed['grossCeiling'],
        );

        self::assertNotNull($resolved);

        [$grossBase, $quoteAmount, $fees] = $resolved;
        self::assertSame($seed['gross']->currency(), $grossBase->currency());
        self::assertTrue($grossBase->greaterThan(Money::zero($grossBase->currency(), $grossBase->scale())));
        self::assertSame($order->assetPair()->quote(), $quoteAmount->currency());
        self::assertInstanceOf(FeeBreakdown::class, $fees);
    }

    public function test_calculate_sell_adjustment_ratio_handles_edge_cases(): void
    {
        $materializer = new LegMaterializer();
        $method = new ReflectionMethod(LegMaterializer::class, 'calculateSellAdjustmentRatio');
        $method->setAccessible(true);

        $target = Money::fromString('USD', '100.000', 3);
        $actual = Money::fromString('USD', '50.000', 3);
        /** @var string|null $ratio */
        $ratio = $method->invoke($materializer, $target, $actual, 3);
        self::assertNotNull($ratio);
        self::assertSame('2.000000000', $ratio);

        $zeroActual = Money::fromString('USD', '0.000', 3);
        self::assertNull($method->invoke($materializer, $target, $zeroActual, 3));

        $oppositeSign = Money::fromString('USD', '-25.000', 3);
        self::assertNull($method->invoke($materializer, $target, $oppositeSign, 3));
    }

    public function test_align_base_scale_respects_bounds_precision(): void
    {
        $materializer = new LegMaterializer();
        $method = new ReflectionMethod(LegMaterializer::class, 'alignBaseScale');
        $method->setAccessible(true);

        $baseAmount = Money::fromString('USD', '1.2', 1);
        /** @var Money $aligned */
        $aligned = $method->invoke($materializer, 4, 5, $baseAmount);

        self::assertSame(5, $aligned->scale());
        self::assertSame('1.20000', $aligned->amount());
        self::assertSame('USD', $aligned->currency());
    }

    /**
     * @param list<GraphEdge> $edges
     */
    private function pathEdges(array $edges): PathEdgeSequence
    {
        if ([] === $edges) {
            return PathEdgeSequence::empty();
        }

        return PathEdgeSequence::fromList(array_map(
            static fn (GraphEdge $edge): PathEdge => PathEdge::create(
                $edge->from(),
                $edge->to(),
                $edge->order(),
                $edge->rate(),
                $edge->orderSide(),
                BcMath::normalize('1.000000000000000000', 18),
            ),
            $edges,
        ));
    }

    private function createOrder(OrderSide $side, string $base, string $quote, string $min, string $max, string $rate, int $rateScale): Order
    {
        $assetPair = AssetPair::fromString($base, $quote);
        $bounds = OrderBounds::from(
            Money::fromString($base, $min, 3),
            Money::fromString($base, $max, 3),
        );
        $exchangeRate = ExchangeRate::fromString($base, $quote, $rate, $rateScale);

        return new Order($side, $assetPair, $bounds, $exchangeRate, null);
    }
}
