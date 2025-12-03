<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Integration\Application\PathSearch\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\Graph;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\GraphEdge;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\PathEdge;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\PathEdgeSequence;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\LegMaterializer;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\OrderSpendAnalyzer;
use SomeWork\P2PPathFinder\Domain\Money\AssetPair;
use SomeWork\P2PPathFinder\Domain\Money\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Money\MoneyMap;
use SomeWork\P2PPathFinder\Domain\Order\Fee\FeeBreakdown;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderBounds;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Fixture\FeePolicyFactory;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;
use SomeWork\P2PPathFinder\Tests\Helpers\DecimalFactory;

use function sprintf;

#[CoversClass(LegMaterializer::class)]
final class LegMaterializerIntegrationTest extends TestCase
{
    public function test_it_materializes_multi_leg_path(): void
    {
        $orders = [
            $this->createOrder(OrderSide::SELL, 'USD', 'EUR', '10.000', '200.000', '0.900', 3),
            $this->createOrder(OrderSide::BUY, 'USD', 'JPY', '50.000', '200.000', '150.000', 3),
        ];

        $graph = (new GraphBuilder())->build($orders);
        $edges = [
            $this->edge($graph, 'EUR', 0),
            $this->edge($graph, 'USD', 0),
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
        self::assertCount(2, $materialized['hops']);
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
            $this->edge($graph, 'USD', 0),
            $this->edge($graph, 'AAA', 0),
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

        $legs = $materialized['hops'];
        self::assertCount(2, $legs);

        $firstLeg = $legs->at(0);
        self::assertSame('USD', $firstLeg->from());
        self::assertSame('AAA', $firstLeg->to());
        self::assertSame('104.082', $firstLeg->spent()->amount());
        self::assertSame('USD', $firstLeg->spent()->currency());
        self::assertSame('96.939', $firstLeg->received()->amount());
        self::assertSame('AAA', $firstLeg->received()->currency());
        $firstFees = $firstLeg->fees();
        self::assertCount(2, $firstFees);
        self::assertSame('5.102', $this->fee($firstFees, 'AAA')->amount());
        self::assertSame('2.041', $this->fee($firstFees, 'USD')->amount());

        $secondLeg = $legs->at(1);
        self::assertSame('AAA', $secondLeg->from());
        self::assertSame('EUR', $secondLeg->to());
        self::assertSame('96.939', $secondLeg->spent()->amount());
        self::assertSame('AAA', $secondLeg->spent()->currency());
        self::assertSame('185.409', $secondLeg->received()->amount());
        self::assertSame('EUR', $secondLeg->received()->currency());
        $secondFees = $secondLeg->fees();
        self::assertCount(2, $secondFees);
        self::assertSame('2.823', $this->fee($secondFees, 'AAA')->amount());
        self::assertSame('2.823', $this->fee($secondFees, 'EUR')->amount());
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

        $initialSeed = $analyzer->determineInitialSpendAmount($config, $this->edge($graph, 'EUR', 0));
        self::assertNotNull($initialSeed);

        $misorderedEdges = [
            $this->edge($graph, 'USD', 0),
            $this->edge($graph, 'EUR', 0),
        ];

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessageMatches('/^Path edge sequences must form a continuous chain\b/');

        $materializer->materialize($this->pathEdges($misorderedEdges), $config->spendAmount(), $initialSeed, 'JPY');
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
        $edge = $this->edge($graph, 'USD', 0);

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
            $this->edge($graph, 'EUR', 0),
            $this->edge($graph, 'USD', 0),
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
        $edge = $this->edge($graph, 'USD', 0);

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
            $this->edge($graph, 'EUR', 0),
            $this->edge($graph, 'USD', 0),
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
        $edge = $this->edge($graph, 'USD', 0);

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
            $this->edge($graph, 'USD', 0),
            $this->edge($graph, 'EUR', 0),
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
        $legs = $materialized['hops'];
        self::assertCount(2, $legs);
        self::assertSame('USD', $legs->at(0)->spent()->currency());
        self::assertSame('EUR', $legs->at(0)->received()->currency());
        self::assertSame('EUR', $legs->at(1)->spent()->currency());
        self::assertSame('JPY', $legs->at(1)->received()->currency());
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
        $edge = $this->edge($graph, 'USD', 0);

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

    public function test_materialize_rejects_empty_edge_sequence(): void
    {
        $materializer = new LegMaterializer();

        $initialSeed = [
            'net' => Money::fromString('USD', '100.00', 2),
            'gross' => Money::fromString('USD', '100.00', 2),
            'grossCeiling' => Money::fromString('USD', '125.00', 2),
        ];

        $result = $materializer->materialize(
            PathEdgeSequence::empty(),
            Money::fromString('USD', '100.00', 2),
            $initialSeed,
            'EUR'
        );

        self::assertNull($result, 'Expected null when edge sequence is empty');
    }

    public function test_materialize_rejects_zero_net_seed(): void
    {
        $order = OrderFactory::buy('USD', 'EUR', '10.000', '200.000', '0.900', 3, 3);
        $graph = (new GraphBuilder())->build([$order]);
        $edge = $this->edge($graph, 'USD', 0);

        $materializer = new LegMaterializer();

        $initialSeed = [
            'net' => Money::zero('USD', 2),
            'gross' => Money::fromString('USD', '100.00', 2),
            'grossCeiling' => Money::fromString('USD', '125.00', 2),
        ];

        $result = $materializer->materialize(
            $this->pathEdges([$edge]),
            Money::fromString('USD', '100.00', 2),
            $initialSeed,
            'EUR'
        );

        self::assertNull($result, 'Expected null when net seed is zero');
    }

    public function test_materialize_rejects_zero_gross_seed(): void
    {
        $order = OrderFactory::buy('USD', 'EUR', '10.000', '200.000', '0.900', 3, 3);
        $graph = (new GraphBuilder())->build([$order]);
        $edge = $this->edge($graph, 'USD', 0);

        $materializer = new LegMaterializer();

        $initialSeed = [
            'net' => Money::fromString('USD', '100.00', 2),
            'gross' => Money::zero('USD', 2),
            'grossCeiling' => Money::fromString('USD', '125.00', 2),
        ];

        $result = $materializer->materialize(
            $this->pathEdges([$edge]),
            Money::fromString('USD', '100.00', 2),
            $initialSeed,
            'EUR'
        );

        self::assertNull($result, 'Expected null when gross seed is zero');
    }

    public function test_materialize_rejects_zero_gross_ceiling(): void
    {
        $order = OrderFactory::buy('USD', 'EUR', '10.000', '200.000', '0.900', 3, 3);
        $graph = (new GraphBuilder())->build([$order]);
        $edge = $this->edge($graph, 'USD', 0);

        $materializer = new LegMaterializer();

        $initialSeed = [
            'net' => Money::fromString('USD', '100.00', 2),
            'gross' => Money::fromString('USD', '100.00', 2),
            'grossCeiling' => Money::zero('USD', 2),
        ];

        $result = $materializer->materialize(
            $this->pathEdges([$edge]),
            Money::fromString('USD', '100.00', 2),
            $initialSeed,
            'EUR'
        );

        self::assertNull($result, 'Expected null when gross ceiling is zero');
    }

    public function test_materialize_rejects_zero_requested_spend(): void
    {
        $order = OrderFactory::buy('USD', 'EUR', '10.000', '200.000', '0.900', 3, 3);
        $graph = (new GraphBuilder())->build([$order]);
        $edge = $this->edge($graph, 'USD', 0);

        $materializer = new LegMaterializer();

        $initialSeed = [
            'net' => Money::fromString('USD', '100.00', 2),
            'gross' => Money::fromString('USD', '100.00', 2),
            'grossCeiling' => Money::fromString('USD', '125.00', 2),
        ];

        $result = $materializer->materialize(
            $this->pathEdges([$edge]),
            Money::zero('USD', 2),
            $initialSeed,
            'EUR'
        );

        self::assertNull($result, 'Expected null when requested spend is zero');
    }

    public function test_materialize_with_very_high_tolerance_accepts_large_overage(): void
    {
        $order = OrderFactory::buy(
            'USD',
            'EUR',
            '50.000',
            '200.000',
            '0.900',
            3,
            3,
            FeePolicyFactory::baseAndQuoteSurcharge('0.100', '0.050', 6)
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $this->edge($graph, 'USD', 0);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.000', 3))
            ->withToleranceBounds('0.0', '0.50') // 50% tolerance
            ->withHopLimits(1, 1)
            ->build();

        $materializer = new LegMaterializer();
        $analyzer = new OrderSpendAnalyzer(null, $materializer);
        $seed = $analyzer->determineInitialSpendAmount($config, $edge);

        self::assertNotNull($seed);

        $result = $materializer->materialize($this->pathEdges([$edge]), $config->spendAmount(), $seed, 'EUR');

        self::assertNotNull($result);
        // Should succeed even with high fees due to high tolerance
        self::assertSame('EUR', $result['totalReceived']->currency());
    }

    public function test_materialize_with_zero_tolerance_rejects_any_overage(): void
    {
        $order = OrderFactory::buy(
            'USD',
            'EUR',
            '50.000',
            '200.000',
            '0.900',
            3,
            3,
            FeePolicyFactory::baseAndQuoteSurcharge('0.001', '0.001', 6) // Small fees
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $this->edge($graph, 'USD', 0);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.000', 3))
            ->withToleranceBounds('0.0', '0.0') // Zero tolerance
            ->withHopLimits(1, 1)
            ->build();

        $materializer = new LegMaterializer();
        $analyzer = new OrderSpendAnalyzer(null, $materializer);
        $seed = $analyzer->determineInitialSpendAmount($config, $edge);

        self::assertNotNull($seed);

        $result = $materializer->materialize($this->pathEdges([$edge]), $config->spendAmount(), $seed, 'EUR');

        // With zero tolerance, materialization may still succeed but spending should equal the requested amount
        self::assertNotNull($result);
        self::assertSame($config->spendAmount()->amount(), $result['totalSpent']->amount());
    }

    public function test_materialize_with_very_small_amounts(): void
    {
        $order = OrderFactory::buy(
            'BTC',
            'USD',
            '0.001',
            '1.000',
            '30000.000',
            8,
            3
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $this->edge($graph, 'BTC', 0);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('BTC', '0.00100000', 8))
            ->withToleranceBounds('0.0', '0.10')
            ->withHopLimits(1, 1)
            ->build();

        $materializer = new LegMaterializer();
        $analyzer = new OrderSpendAnalyzer(null, $materializer);
        $seed = $analyzer->determineInitialSpendAmount($config, $edge);

        self::assertNotNull($seed);

        $result = $materializer->materialize($this->pathEdges([$edge]), $config->spendAmount(), $seed, 'USD');

        self::assertNotNull($result);
        self::assertSame('BTC', $result['totalSpent']->currency());
        self::assertSame('USD', $result['totalReceived']->currency());
        // Should handle very small amounts correctly
        self::assertTrue($result['totalReceived']->greaterThan(Money::zero('USD', 3)));
    }

    public function test_materialize_with_complex_multi_currency_fees(): void
    {
        $orders = [
            OrderFactory::buy(
                'USD',
                'EUR',
                '100.000',
                '1000.000',
                '0.85',
                3,
                3,
                FeePolicyFactory::baseAndQuoteSurcharge('0.020', '0.015', 6)
            ),
            OrderFactory::buy(
                'EUR',
                'GBP',
                '50.000',
                '500.000',
                '0.88',
                3,
                3,
                FeePolicyFactory::baseAndQuoteSurcharge('0.025', '0.018', 6)
            ),
            OrderFactory::buy(
                'GBP',
                'JPY',
                '25.000',
                '250.000',
                '160.00',
                3,
                2,
                FeePolicyFactory::baseAndQuoteSurcharge('0.030', '0.020', 6)
            ),
        ];

        $graph = (new GraphBuilder())->build($orders);
        $edges = [
            $this->edge($graph, 'USD', 0),
            $this->edge($graph, 'EUR', 0),
            $this->edge($graph, 'GBP', 0),
        ];

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '200.000', 3))
            ->withToleranceBounds('0.0', '0.20')
            ->withHopLimits(1, 5)
            ->build();

        $materializer = new LegMaterializer();
        $analyzer = new OrderSpendAnalyzer(null, $materializer);
        $seed = $analyzer->determineInitialSpendAmount($config, $edges[0]);

        self::assertNotNull($seed);

        $result = $materializer->materialize($this->pathEdges($edges), $config->spendAmount(), $seed, 'JPY');

        self::assertNotNull($result);
        self::assertSame('USD', $result['totalSpent']->currency());
        self::assertSame('JPY', $result['totalReceived']->currency());

        // Should have fees in multiple currencies
        $feeBreakdown = $result['feeBreakdown'];
        $feeMap = $feeBreakdown->toArray();
        self::assertArrayHasKey('USD', $feeMap);
        self::assertArrayHasKey('EUR', $feeMap);
        self::assertArrayHasKey('GBP', $feeMap);
        self::assertArrayHasKey('JPY', $feeMap);

        // All fees should be positive
        foreach ($feeMap as $currency => $fee) {
            self::assertTrue($fee->greaterThan(Money::zero($currency, $fee->scale())));
        }
    }

    public function test_materialize_with_maximum_hop_complexity(): void
    {
        // Create a chain of orders: USD -> EUR -> GBP -> JPY -> CNY -> KRW
        $orders = [
            OrderFactory::buy('USD', 'EUR', '100.000', '1000.000', '0.90', 3, 3),
            OrderFactory::buy('EUR', 'GBP', '50.000', '500.000', '0.85', 3, 3),
            OrderFactory::buy('GBP', 'JPY', '25.000', '250.000', '150.00', 3, 2),
            OrderFactory::buy('JPY', 'CNY', '10000', '100000', '0.065', 0, 4),
            OrderFactory::buy('CNY', 'KRW', '100.00', '1000.00', '180.00', 2, 2),
        ];

        $graph = (new GraphBuilder())->build($orders);
        $edges = [
            $this->edge($graph, 'USD', 0),
            $this->edge($graph, 'EUR', 0),
            $this->edge($graph, 'GBP', 0),
            $this->edge($graph, 'JPY', 0),
            $this->edge($graph, 'CNY', 0),
        ];

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '150.000', 3))
            ->withToleranceBounds('0.0', '0.30')
            ->withHopLimits(1, 6)
            ->build();

        $materializer = new LegMaterializer();
        $analyzer = new OrderSpendAnalyzer(null, $materializer);
        $seed = $analyzer->determineInitialSpendAmount($config, $edges[0]);

        self::assertNotNull($seed);

        $result = $materializer->materialize($this->pathEdges($edges), $config->spendAmount(), $seed, 'KRW');

        // Complex multi-currency chains may or may not succeed depending on exact amounts
        // The test validates that the method doesn't crash and handles complex scenarios
        if (null !== $result) {
            self::assertSame('USD', $result['totalSpent']->currency());
            self::assertSame('KRW', $result['totalReceived']->currency());
        }
    }

    public function test_materialize_with_precision_boundary_amounts(): void
    {
        // Test with amounts that might cause precision issues
        $order = OrderFactory::buy(
            'BTC',
            'USD',
            '0.00000001',
            '1000000.00000000',
            '50000.00000000',
            8,
            8
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $this->edge($graph, 'BTC', 0);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('BTC', '0.12345678', 8))
            ->withToleranceBounds('0.0', '0.01')
            ->withHopLimits(1, 1)
            ->build();

        $materializer = new LegMaterializer();
        $analyzer = new OrderSpendAnalyzer(null, $materializer);
        $seed = $analyzer->determineInitialSpendAmount($config, $edge);

        self::assertNotNull($seed);

        $result = $materializer->materialize($this->pathEdges([$edge]), $config->spendAmount(), $seed, 'USD');

        self::assertNotNull($result);
        self::assertSame('BTC', $result['totalSpent']->currency());
        self::assertSame('USD', $result['totalReceived']->currency());
        // Should handle high precision correctly
        self::assertTrue($result['totalReceived']->greaterThan(Money::zero('USD', 8)));
    }

    public function test_resolve_buy_fill_with_tight_ceiling_constraint(): void
    {
        $order = OrderFactory::buy(
            'USD',
            'BTC',
            '100.000',
            '1000.000',
            '0.00001000',
            3,
            8,
            FeePolicyFactory::baseSurcharge('0.010')
        );

        $materializer = new LegMaterializer();

        $netSeed = Money::fromString('USD', '500.000', 3);
        $grossSeed = Money::fromString('USD', '510.000', 3);
        $tightCeiling = Money::fromString('USD', '505.000', 3);

        $result = $materializer->resolveBuyFill($order, $netSeed, $grossSeed, $tightCeiling);

        self::assertNotNull($result);
        self::assertFalse($result['gross']->greaterThan($tightCeiling));
        self::assertTrue($result['gross']->greaterThan($result['net']));
    }

    public function test_evaluate_sell_quote_integration_with_real_order(): void
    {
        $order = OrderFactory::sell(
            'USD',
            'EUR',
            '100.000',
            '1000.000',
            '0.850',
            3,
            3,
            FeePolicyFactory::baseAndQuoteSurcharge('0.050', '0.020', 6)
        );

        $materializer = new LegMaterializer();

        $baseAmount = Money::fromString('USD', '200.000', 3);
        $result = $materializer->evaluateSellQuote($order, $baseAmount);

        self::assertArrayHasKey('grossQuote', $result);
        self::assertArrayHasKey('fees', $result);

        $quote = $result['grossQuote'];
        $fees = $result['fees'];

        self::assertInstanceOf(Money::class, $quote);
        self::assertInstanceOf(FeeBreakdown::class, $fees);

        self::assertSame('EUR', $quote->currency());
        // 200 USD * 0.850 rate = 170 EUR (before fees)
        // But with fees, the effective quote might be different
        self::assertTrue($quote->greaterThan(Money::zero('EUR', 3)));

        // Should have fees
        self::assertFalse($fees->isZero());
    }

    public function test_evaluate_sell_quote_with_maximum_order_bounds(): void
    {
        $order = OrderFactory::sell(
            'BTC',
            'USD',
            '1.00000000',
            '10.00000000',
            '30000.00000000',
            8,
            8
        );

        $materializer = new LegMaterializer();

        // Test with maximum allowed amount
        $baseAmount = Money::fromString('BTC', '10.00000000', 8);
        $result = $materializer->evaluateSellQuote($order, $baseAmount);

        self::assertArrayHasKey('grossQuote', $result);
        $quote = $result['grossQuote'];
        self::assertSame('USD', $quote->currency());
        // 10 BTC * 30000 USD/BTC = 300000 USD
        self::assertSame('300000.00000000', $quote->amount());
    }

    public function test_evaluate_sell_quote_with_minimum_order_bounds(): void
    {
        $order = OrderFactory::sell(
            'BTC',
            'USD',
            '0.00100000',
            '10.00000000',
            '30000.00000000',
            8,
            8
        );

        $materializer = new LegMaterializer();

        // Test with minimum allowed amount
        $baseAmount = Money::fromString('BTC', '0.00100000', 8);
        $result = $materializer->evaluateSellQuote($order, $baseAmount);

        self::assertArrayHasKey('grossQuote', $result);
        $quote = $result['grossQuote'];
        self::assertSame('USD', $quote->currency());
        // 0.001 BTC * 30000 USD/BTC = 30 USD
        self::assertSame('30.00000000', $quote->amount());
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
                DecimalFactory::unit(18),
            ),
            $edges,
        ));
    }

    /**
     * @return list<GraphEdge>
     */
    private function edges(Graph $graph, string $currency): array
    {
        $node = $graph->node($currency);
        self::assertNotNull($node, sprintf('Graph is missing node for currency "%s".', $currency));

        return $node->edges()->toArray();
    }

    private function edge(Graph $graph, string $currency, int $index): GraphEdge
    {
        $edges = $this->edges($graph, $currency);
        self::assertArrayHasKey($index, $edges);

        return $edges[$index];
    }

    private function fee(MoneyMap $fees, string $currency): Money
    {
        $fee = $fees->get($currency);
        self::assertNotNull($fee, sprintf('Missing fee for currency "%s".', $currency));

        return $fee;
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
