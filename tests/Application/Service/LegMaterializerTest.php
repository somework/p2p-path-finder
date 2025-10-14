<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Service;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\Service\LegMaterializer;
use SomeWork\P2PPathFinder\Application\Service\OrderSpendAnalyzer;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\AssetPair;
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
            ->withToleranceBounds(0.0, 0.25)
            ->withHopLimits(1, 3)
            ->build();

        $materializer = new LegMaterializer();
        $analyzer = new OrderSpendAnalyzer(null, $materializer);

        $initialSeed = $analyzer->determineInitialSpendAmount($config, $edges[0]);
        self::assertNotNull($initialSeed);

        $materialized = $materializer->materialize($edges, $config->spendAmount(), $initialSeed, 'JPY');
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
            ->withToleranceBounds(0.0, 0.15)
            ->withHopLimits(1, 3)
            ->build();

        $materializer = new LegMaterializer();
        $analyzer = new OrderSpendAnalyzer(null, $materializer);

        $initialSeed = $analyzer->determineInitialSpendAmount($config, $edges[0]);
        self::assertNotNull($initialSeed);

        $materialized = $materializer->materialize($edges, $config->spendAmount(), $initialSeed, 'EUR');
        self::assertNotNull($materialized);

        self::assertSame('USD', $materialized['totalSpent']->currency());
        self::assertSame('104.082', $materialized['totalSpent']->amount());
        self::assertSame('EUR', $materialized['totalReceived']->currency());
        self::assertSame('185.409', $materialized['totalReceived']->amount());
        self::assertSame('USD', $materialized['toleranceSpent']->currency());
        self::assertSame('104.082', $materialized['toleranceSpent']->amount());
        self::assertTrue($config->maximumSpendAmount()->greaterThan($materialized['totalSpent']));

        $feeBreakdown = $materialized['feeBreakdown'];
        self::assertArrayHasKey('AAA', $feeBreakdown);
        self::assertArrayHasKey('USD', $feeBreakdown);
        self::assertArrayHasKey('EUR', $feeBreakdown);
        self::assertSame('7.925', $feeBreakdown['AAA']->amount());
        self::assertSame('2.041', $feeBreakdown['USD']->amount());
        self::assertSame('2.823', $feeBreakdown['EUR']->amount());

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
