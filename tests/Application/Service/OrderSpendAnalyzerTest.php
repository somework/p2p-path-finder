<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Service;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\Service\OrderSpendAnalyzer;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Tests\Fixture\FeePolicyFactory;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

final class OrderSpendAnalyzerTest extends TestCase
{
    public function test_it_clamps_buy_seed_to_order_minimum_with_base_surcharge(): void
    {
        $order = OrderFactory::buy(
            base: 'EUR',
            quote: 'USD',
            minAmount: '120.000',
            maxAmount: '250.000',
            rate: '1.100',
            amountScale: 3,
            rateScale: 3,
            feePolicy: FeePolicyFactory::baseSurcharge('0.010'),
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $graph['EUR']['edges'][0];

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.000', 3))
            ->withToleranceBounds(0.0, 0.25)
            ->withHopLimits(1, 1)
            ->build();

        $analyzer = new OrderSpendAnalyzer();
        $seed = $analyzer->determineInitialSpendAmount($config, $edge);

        self::assertNotNull($seed);
        self::assertTrue($seed['net']->equals(Money::fromString('EUR', '120.000', 3)));
        self::assertTrue($seed['gross']->equals(Money::fromString('EUR', '121.200', 3)));
        self::assertTrue($seed['grossCeiling']->equals(Money::fromString('EUR', '125.000', 3)));
    }

    public function test_it_rejects_buy_seed_when_minimum_gross_exceeds_upper_tolerance(): void
    {
        $order = OrderFactory::buy(
            base: 'EUR',
            quote: 'USD',
            minAmount: '120.000',
            maxAmount: '250.000',
            rate: '1.100',
            amountScale: 3,
            rateScale: 3,
            feePolicy: FeePolicyFactory::baseSurcharge('0.010'),
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $graph['EUR']['edges'][0];

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.000', 3))
            ->withToleranceBounds(0.0, 0.05)
            ->withHopLimits(1, 1)
            ->build();

        $analyzer = new OrderSpendAnalyzer();
        $seed = $analyzer->determineInitialSpendAmount($config, $edge);

        self::assertNull($seed);
    }

    public function test_it_clamps_sell_seed_to_effective_bounds(): void
    {
        $order = OrderFactory::sell(
            base: 'BTC',
            quote: 'USD',
            minAmount: '0.500',
            maxAmount: '1.500',
            rate: '30000',
            amountScale: 3,
            rateScale: 8,
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $graph['USD']['edges'][0];

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '14000.00', 2))
            ->withToleranceBounds(0.1, 0.1)
            ->withHopLimits(1, 1)
            ->build();

        $analyzer = new OrderSpendAnalyzer();
        $seed = $analyzer->determineInitialSpendAmount($config, $edge);

        self::assertNotNull($seed);
        self::assertTrue($seed['net']->equals(Money::fromString('USD', '15000.00', 2)));
        self::assertTrue($seed['gross']->equals(Money::fromString('USD', '15000.00', 2)));
        self::assertTrue($seed['grossCeiling']->equals(Money::fromString('USD', '15000.00', 2)));
    }

    public function test_it_rejects_sell_seed_when_bounds_do_not_overlap(): void
    {
        $order = OrderFactory::sell(
            base: 'BTC',
            quote: 'USD',
            minAmount: '0.500',
            maxAmount: '1.500',
            rate: '30000',
            amountScale: 3,
            rateScale: 8,
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $graph['USD']['edges'][0];

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '14000.00', 2))
            ->withToleranceBounds(0.0, 0.05)
            ->withHopLimits(1, 1)
            ->build();

        $analyzer = new OrderSpendAnalyzer();
        $seed = $analyzer->determineInitialSpendAmount($config, $edge);

        self::assertNull($seed);
    }
}
