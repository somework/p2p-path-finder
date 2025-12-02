<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\Graph;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\GraphEdge;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\OrderSpendAnalyzer;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Tests\Fixture\FeePolicyFactory;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

use function sprintf;

#[CoversClass(OrderSpendAnalyzer::class)]
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
        $edge = $this->edge($graph, 'EUR', 0);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.000', 3))
            ->withToleranceBounds('0.0', '0.25')
            ->withHopLimits(1, 1)
            ->build();

        $analyzer = new OrderSpendAnalyzer();
        $seed = $analyzer->determineInitialSpendAmount($config, $edge);

        self::assertNotNull($seed);
        self::assertTrue($seed['net']->equals(Money::fromString('EUR', '120.000', 3)));
        self::assertTrue($seed['gross']->equals(Money::fromString('EUR', '121.200', 3)));
        self::assertTrue($seed['grossCeiling']->equals(Money::fromString('EUR', '125.000', 3)));
    }

    public function test_it_rejects_buy_seed_when_configured_window_does_not_overlap_order_bounds(): void
    {
        $order = OrderFactory::buy(
            base: 'USD',
            quote: 'EUR',
            minAmount: '100.00',
            maxAmount: '150.00',
            rate: '0.9000',
            amountScale: 2,
            rateScale: 4,
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $this->edge($graph, 'USD', 0);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '50.00', 2))
            ->withToleranceBounds('0.0', '0.0')
            ->withHopLimits(1, 1)
            ->build();

        $analyzer = new OrderSpendAnalyzer();
        $seed = $analyzer->determineInitialSpendAmount($config, $edge);

        self::assertNull($seed);
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
        $edge = $this->edge($graph, 'EUR', 0);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.000', 3))
            ->withToleranceBounds('0.0', '0.05')
            ->withHopLimits(1, 1)
            ->build();

        $analyzer = new OrderSpendAnalyzer();
        $seed = $analyzer->determineInitialSpendAmount($config, $edge);

        self::assertNull($seed);
    }

    public function test_it_trims_buy_seed_when_leg_resolution_consumes_tolerance_budget(): void
    {
        $order = OrderFactory::buy(
            base: 'USD',
            quote: 'EUR',
            minAmount: '50.00',
            maxAmount: '75.00',
            rate: '0.9500',
            amountScale: 2,
            rateScale: 4,
            feePolicy: FeePolicyFactory::baseAndQuoteSurcharge('0.060', '0.020', 3),
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $this->edge($graph, 'USD', 0);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '55.00', 2))
            ->withToleranceBounds('0.0', '0.01')
            ->withHopLimits(1, 1)
            ->build();

        $analyzer = new OrderSpendAnalyzer();
        $seed = $analyzer->determineInitialSpendAmount($config, $edge);

        self::assertNotNull($seed);
        self::assertTrue($seed['gross']->equals(Money::fromString('USD', '55.55', 2)));
        self::assertTrue($seed['grossCeiling']->equals(Money::fromString('USD', '55.55', 2)));
        self::assertTrue($seed['net']->equals(Money::fromString('USD', '52.41', 2)));
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
        $edge = $this->edge($graph, 'USD', 0);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '14000.00', 2))
            ->withToleranceBounds('0.1', '0.1')
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
        $edge = $this->edge($graph, 'USD', 0);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '14000.00', 2))
            ->withToleranceBounds('0.0', '0.05')
            ->withHopLimits(1, 1)
            ->build();

        $analyzer = new OrderSpendAnalyzer();
        $seed = $analyzer->determineInitialSpendAmount($config, $edge);

        self::assertNull($seed);
    }

    public function test_it_clamps_buy_seed_to_order_maximum_when_desired_exceeds_capacity(): void
    {
        $order = OrderFactory::buy(
            base: 'EUR',
            quote: 'USD',
            minAmount: '100.00',
            maxAmount: '150.00',
            rate: '1.0500',
            amountScale: 2,
            rateScale: 4,
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $this->edge($graph, 'EUR', 0);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '200.00', 2))
            ->withToleranceBounds('0.5', '0.6')
            ->withHopLimits(1, 1)
            ->build();

        $analyzer = new OrderSpendAnalyzer();
        $seed = $analyzer->determineInitialSpendAmount($config, $edge);

        self::assertNotNull($seed);
        self::assertTrue($seed['net']->equals(Money::fromString('EUR', '150.00', 2)));
        self::assertTrue($seed['gross']->equals(Money::fromString('EUR', '150.00', 2)));
        self::assertTrue($seed['grossCeiling']->equals(Money::fromString('EUR', '150.00', 2)));
    }

    public function test_it_rejects_sell_seed_when_quote_fees_exceed_tolerance_budget(): void
    {
        $order = OrderFactory::sell(
            base: 'BTC',
            quote: 'USD',
            minAmount: '0.500',
            maxAmount: '0.750',
            rate: '100.00',
            amountScale: 3,
            rateScale: 2,
            feePolicy: FeePolicyFactory::baseAndQuoteSurcharge('0.000000', '0.50', 3),
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $this->edge($graph, 'USD', 0);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '100.00', 2))
            ->withToleranceBounds('0.0', '0.0')
            ->withHopLimits(1, 1)
            ->build();

        $analyzer = new OrderSpendAnalyzer();
        $seed = $analyzer->determineInitialSpendAmount($config, $edge);

        self::assertNull($seed);
    }

    public function test_it_rejects_sell_seed_when_quote_fee_eliminates_effective_window(): void
    {
        $order = OrderFactory::sell(
            base: 'BTC',
            quote: 'USD',
            minAmount: '1.000',
            maxAmount: '1.000',
            rate: '100.00',
            amountScale: 3,
            rateScale: 2,
            feePolicy: FeePolicyFactory::baseAndQuoteSurcharge('0.000000', '0.60', 3),
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $this->edge($graph, 'USD', 0);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '150.00', 2))
            ->withToleranceBounds('0.1', '0.1')
            ->withHopLimits(1, 1)
            ->build();

        $analyzer = new OrderSpendAnalyzer();
        $seed = $analyzer->determineInitialSpendAmount($config, $edge);

        self::assertNull($seed);
    }

    public function test_determine_spend_currency_uses_base_for_buy_orders(): void
    {
        $method = new ReflectionMethod(OrderSpendAnalyzer::class, 'determineSpendCurrency');
        $method->setAccessible(true);

        $order = OrderFactory::buy(
            base: 'EUR',
            quote: 'USD',
            minAmount: '50.00',
            maxAmount: '150.00',
            rate: '1.0500',
            amountScale: 2,
            rateScale: 4,
        );

        self::assertSame('EUR', $method->invoke(new OrderSpendAnalyzer(), $order));
    }

    public function test_determine_spend_currency_uses_quote_for_sell_orders(): void
    {
        $method = new ReflectionMethod(OrderSpendAnalyzer::class, 'determineSpendCurrency');
        $method->setAccessible(true);

        $order = OrderFactory::sell(
            base: 'BTC',
            quote: 'USDT',
            minAmount: '0.010',
            maxAmount: '0.500',
            rate: '30000',
            amountScale: 3,
            rateScale: 4,
        );

        self::assertSame('USDT', $method->invoke(new OrderSpendAnalyzer(), $order));
    }

    public function test_it_handles_buy_orders_with_zero_minimum_amount(): void
    {
        $order = OrderFactory::buy(
            base: 'EUR',
            quote: 'USD',
            minAmount: '0.00',
            maxAmount: '100.00',
            rate: '1.100',
            amountScale: 2,
            rateScale: 3,
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $this->edge($graph, 'EUR', 0);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '50.00', 2))
            ->withToleranceBounds('0.0', '0.2')
            ->withHopLimits(1, 1)
            ->build();

        $analyzer = new OrderSpendAnalyzer();
        $seed = $analyzer->determineInitialSpendAmount($config, $edge);

        self::assertNotNull($seed);
        self::assertTrue($seed['net']->equals(Money::fromString('EUR', '50.00', 2)));
    }

    public function test_it_rejects_sell_orders_with_very_narrow_effective_bounds(): void
    {
        $order = OrderFactory::sell(
            base: 'BTC',
            quote: 'USD',
            minAmount: '0.999',
            maxAmount: '1.001',
            rate: '30000.00',
            amountScale: 3,
            rateScale: 2,
            feePolicy: FeePolicyFactory::baseAndQuoteSurcharge('0.000', '0.010'), // Small quote fee
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $this->edge($graph, 'USD', 0);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '30000.00', 2))
            ->withToleranceBounds('0.0', '0.001') // Very tight tolerance
            ->withHopLimits(1, 1)
            ->build();

        $analyzer = new OrderSpendAnalyzer();
        $seed = $analyzer->determineInitialSpendAmount($config, $edge);

        // Very narrow bounds with tight tolerance may result in no viable spend amount
        self::assertNull($seed);
    }

    public function test_it_handles_buy_orders_with_high_precision_scales(): void
    {
        $order = OrderFactory::buy(
            base: 'BTC',
            quote: 'USD',
            minAmount: '0.00100000',
            maxAmount: '0.10000000',
            rate: '30000.00000000',
            amountScale: 8,
            rateScale: 8,
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $this->edge($graph, 'BTC', 0);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('BTC', '0.05000000', 8))
            ->withToleranceBounds('0.0', '0.05')
            ->withHopLimits(1, 1)
            ->build();

        $analyzer = new OrderSpendAnalyzer();
        $seed = $analyzer->determineInitialSpendAmount($config, $edge);

        self::assertNotNull($seed);
        self::assertSame(8, $seed['net']->scale());
        self::assertSame(8, $seed['gross']->scale());
        self::assertTrue($seed['net']->greaterThan(Money::zero('BTC', 8)));
    }

    public function test_it_handles_buy_orders_with_very_small_amounts(): void
    {
        $order = OrderFactory::buy(
            base: 'EUR',
            quote: 'USD',
            minAmount: '10.00',
            maxAmount: '100.00',
            rate: '1.100',
            amountScale: 2,
            rateScale: 3,
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $this->edge($graph, 'EUR', 0);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '9.00', 2)) // Close to minimum
            ->withToleranceBounds('0.0', '0.2')
            ->withHopLimits(1, 1)
            ->build();

        $analyzer = new OrderSpendAnalyzer();
        $seed = $analyzer->determineInitialSpendAmount($config, $edge);

        // Should clamp to minimum order amount
        self::assertNotNull($seed);
        self::assertTrue($seed['net']->equals(Money::fromString('EUR', '10.00', 2)));
    }

    public function test_it_handles_orders_with_only_base_fees(): void
    {
        $order = OrderFactory::buy(
            base: 'EUR',
            quote: 'USD',
            minAmount: '100.00',
            maxAmount: '200.00',
            rate: '1.100',
            amountScale: 2,
            rateScale: 3,
            feePolicy: FeePolicyFactory::baseSurcharge('0.050'),
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $this->edge($graph, 'EUR', 0);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '120.00', 2))
            ->withToleranceBounds('0.0', '0.3')
            ->withHopLimits(1, 1)
            ->build();

        $analyzer = new OrderSpendAnalyzer();
        $seed = $analyzer->determineInitialSpendAmount($config, $edge);

        self::assertNotNull($seed);
        // Gross should be higher than net due to base fee
        self::assertTrue($seed['gross']->greaterThan($seed['net']));
    }

    public function test_it_rejects_sell_orders_with_high_quote_fees(): void
    {
        $order = OrderFactory::sell(
            base: 'BTC',
            quote: 'USD',
            minAmount: '0.100',
            maxAmount: '1.000',
            rate: '30000.00',
            amountScale: 3,
            rateScale: 2,
            feePolicy: FeePolicyFactory::baseAndQuoteSurcharge('0.000', '0.050'), // 5% quote fee
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $this->edge($graph, 'USD', 0);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '1500.00', 2))
            ->withToleranceBounds('0.0', '0.01') // Very tight tolerance
            ->withHopLimits(1, 1)
            ->build();

        $analyzer = new OrderSpendAnalyzer();
        $seed = $analyzer->determineInitialSpendAmount($config, $edge);

        // High quote fees with tight tolerance may make the order unviable
        // This tests the rejection logic for high-fee scenarios
        self::assertNull($seed);
    }

    public function test_it_handles_orders_with_zero_fees(): void
    {
        $order = OrderFactory::buy(
            base: 'EUR',
            quote: 'USD',
            minAmount: '50.00',
            maxAmount: '150.00',
            rate: '1.050',
            amountScale: 2,
            rateScale: 3,
            // No fee policy specified = zero fees
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $this->edge($graph, 'EUR', 0);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '75.00', 2))
            ->withToleranceBounds('0.0', '0.2')
            ->withHopLimits(1, 1)
            ->build();

        $analyzer = new OrderSpendAnalyzer();
        $seed = $analyzer->determineInitialSpendAmount($config, $edge);

        self::assertNotNull($seed);
        // With zero fees, gross should equal net
        self::assertTrue($seed['gross']->equals($seed['net']));
    }

    public function test_it_handles_config_with_equal_min_max_spend_amounts(): void
    {
        $order = OrderFactory::buy(
            base: 'EUR',
            quote: 'USD',
            minAmount: '80.00',
            maxAmount: '120.00',
            rate: '1.100',
            amountScale: 2,
            rateScale: 3,
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $this->edge($graph, 'EUR', 0);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('EUR', '100.00', 2))
            ->withToleranceBounds('0.0', '0.0') // Zero tolerance makes min=max
            ->withHopLimits(1, 1)
            ->build();

        $analyzer = new OrderSpendAnalyzer();
        $seed = $analyzer->determineInitialSpendAmount($config, $edge);

        self::assertNotNull($seed);
        self::assertTrue($seed['net']->equals(Money::fromString('EUR', '100.00', 2)));
    }

    public function test_it_handles_sell_orders_with_precision_boundary_amounts(): void
    {
        $order = OrderFactory::sell(
            base: 'BTC',
            quote: 'USD',
            minAmount: '0.00000001',
            maxAmount: '100.00000000',
            rate: '50000.00000000',
            amountScale: 8,
            rateScale: 8,
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $this->edge($graph, 'USD', 0);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '1.00000000', 8))
            ->withToleranceBounds('0.0', '0.01')
            ->withHopLimits(1, 1)
            ->build();

        $analyzer = new OrderSpendAnalyzer();
        $seed = $analyzer->determineInitialSpendAmount($config, $edge);

        self::assertNotNull($seed);
        self::assertSame(8, $seed['net']->scale());
        self::assertTrue($seed['net']->equals(Money::fromString('USD', '1.00000000', 8)));
    }

    public function test_it_rejects_when_sell_order_min_bound_causes_negative_effective_amount(): void
    {
        $order = OrderFactory::sell(
            base: 'BTC',
            quote: 'USD',
            minAmount: '1.000',
            maxAmount: '10.000',
            rate: '100.00',
            amountScale: 3,
            rateScale: 2,
            feePolicy: FeePolicyFactory::baseAndQuoteSurcharge('0.000', '0.99'), // 99% quote fee
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $this->edge($graph, 'USD', 0);

        $config = PathSearchConfig::builder()
            ->withSpendAmount(Money::fromString('USD', '50.00', 2))
            ->withToleranceBounds('0.0', '0.1')
            ->withHopLimits(1, 1)
            ->build();

        $analyzer = new OrderSpendAnalyzer();
        $seed = $analyzer->determineInitialSpendAmount($config, $edge);

        // Should reject because the minimum effective quote amount becomes negative or zero
        self::assertNull($seed);
    }

    public function test_determine_order_spend_bounds_with_buy_order(): void
    {
        $method = new ReflectionMethod(OrderSpendAnalyzer::class, 'determineOrderSpendBounds');
        $method->setAccessible(true);

        $order = OrderFactory::buy(
            base: 'EUR',
            quote: 'USD',
            minAmount: '100.00',
            maxAmount: '200.00',
            rate: '1.100',
            amountScale: 2,
            rateScale: 3,
            feePolicy: FeePolicyFactory::baseSurcharge('0.050'),
        );

        $analyzer = new OrderSpendAnalyzer();
        $bounds = $method->invoke($analyzer, $order);

        self::assertCount(2, $bounds);
        self::assertInstanceOf(Money::class, $bounds[0]);
        self::assertInstanceOf(Money::class, $bounds[1]);
        self::assertSame('EUR', $bounds[0]->currency());
        self::assertSame('EUR', $bounds[1]->currency());
        // Min gross should be less than max gross due to fees
        self::assertTrue($bounds[0]->lessThan($bounds[1]));
    }

    public function test_determine_order_spend_bounds_with_sell_order(): void
    {
        $method = new ReflectionMethod(OrderSpendAnalyzer::class, 'determineOrderSpendBounds');
        $method->setAccessible(true);

        $order = OrderFactory::sell(
            base: 'BTC',
            quote: 'USD',
            minAmount: '1.000',
            maxAmount: '2.000',
            rate: '30000.00',
            amountScale: 3,
            rateScale: 2,
            feePolicy: FeePolicyFactory::baseAndQuoteSurcharge('0.000', '0.020'),
        );

        $analyzer = new OrderSpendAnalyzer();
        $bounds = $method->invoke($analyzer, $order);

        self::assertCount(2, $bounds);
        self::assertInstanceOf(Money::class, $bounds[0]);
        self::assertInstanceOf(Money::class, $bounds[1]);
        self::assertSame('USD', $bounds[0]->currency());
        self::assertSame('USD', $bounds[1]->currency());
        // Min gross should be less than max gross
        self::assertTrue($bounds[0]->lessThan($bounds[1]));
    }

    public function test_determine_order_spend_bounds_buy_with_base_fees(): void
    {
        $method = new ReflectionMethod(OrderSpendAnalyzer::class, 'determineOrderSpendBounds');
        $method->setAccessible(true);

        $order = OrderFactory::buy(
            base: 'EUR',
            quote: 'USD',
            minAmount: '100.00',
            maxAmount: '200.00',
            rate: '1.100',
            amountScale: 2,
            rateScale: 3,
            feePolicy: FeePolicyFactory::baseSurcharge('0.050'),
        );

        $analyzer = new OrderSpendAnalyzer();
        $bounds = $method->invoke($analyzer, $order);

        self::assertCount(2, $bounds);
        self::assertInstanceOf(Money::class, $bounds[0]);
        self::assertInstanceOf(Money::class, $bounds[1]);
        self::assertSame('EUR', $bounds[0]->currency());
        self::assertSame('EUR', $bounds[1]->currency());
        // With base fees, gross amounts should be higher than net amounts
        self::assertTrue($bounds[0]->greaterThan(Money::fromString('EUR', '100.00', 2)));
        self::assertTrue($bounds[1]->greaterThan(Money::fromString('EUR', '200.00', 2)));
    }

    public function test_determine_order_spend_bounds_buy_with_quote_fees(): void
    {
        $method = new ReflectionMethod(OrderSpendAnalyzer::class, 'determineOrderSpendBounds');
        $method->setAccessible(true);

        $order = OrderFactory::buy(
            base: 'EUR',
            quote: 'USD',
            minAmount: '100.00',
            maxAmount: '200.00',
            rate: '1.100',
            amountScale: 2,
            rateScale: 3,
            feePolicy: FeePolicyFactory::baseAndQuoteSurcharge('0.000', '0.030'),
        );

        $analyzer = new OrderSpendAnalyzer();
        $bounds = $method->invoke($analyzer, $order);

        self::assertCount(2, $bounds);
        self::assertInstanceOf(Money::class, $bounds[0]);
        self::assertInstanceOf(Money::class, $bounds[1]);
        self::assertSame('EUR', $bounds[0]->currency());
        self::assertSame('EUR', $bounds[1]->currency());
        // Quote fees on BUY orders don't affect gross base amounts
        self::assertTrue($bounds[0]->equals(Money::fromString('EUR', '100.00', 2)));
        self::assertTrue($bounds[1]->equals(Money::fromString('EUR', '200.00', 2)));
    }

    public function test_determine_order_spend_bounds_sell_with_base_fees(): void
    {
        $method = new ReflectionMethod(OrderSpendAnalyzer::class, 'determineOrderSpendBounds');
        $method->setAccessible(true);

        $order = OrderFactory::sell(
            base: 'BTC',
            quote: 'USD',
            minAmount: '1.000',
            maxAmount: '2.000',
            rate: '30000.00',
            amountScale: 3,
            rateScale: 2,
            feePolicy: FeePolicyFactory::baseSurcharge('0.010'),
        );

        $analyzer = new OrderSpendAnalyzer();
        $bounds = $method->invoke($analyzer, $order);

        self::assertCount(2, $bounds);
        self::assertInstanceOf(Money::class, $bounds[0]);
        self::assertInstanceOf(Money::class, $bounds[1]);
        self::assertSame('USD', $bounds[0]->currency());
        self::assertSame('USD', $bounds[1]->currency());
        // Base fees on SELL orders don't affect gross quote amounts
        self::assertTrue($bounds[0]->equals(Money::fromString('USD', '30000.00', 2)));
        self::assertTrue($bounds[1]->equals(Money::fromString('USD', '60000.00', 2)));
    }

    public function test_determine_order_spend_bounds_sell_with_combined_fees(): void
    {
        $method = new ReflectionMethod(OrderSpendAnalyzer::class, 'determineOrderSpendBounds');
        $method->setAccessible(true);

        $order = OrderFactory::sell(
            base: 'BTC',
            quote: 'USD',
            minAmount: '0.500',
            maxAmount: '1.000',
            rate: '25000.00',
            amountScale: 3,
            rateScale: 2,
            feePolicy: FeePolicyFactory::baseAndQuoteSurcharge('0.020', '0.015'),
        );

        $analyzer = new OrderSpendAnalyzer();
        $bounds = $method->invoke($analyzer, $order);

        self::assertCount(2, $bounds);
        self::assertInstanceOf(Money::class, $bounds[0]);
        self::assertInstanceOf(Money::class, $bounds[1]);
        self::assertSame('USD', $bounds[0]->currency());
        self::assertSame('USD', $bounds[1]->currency());
        // Combined fees with quote fee should result in higher gross quotes for SELL orders
        self::assertTrue($bounds[0]->greaterThan(Money::fromString('USD', '12500.00', 2)));
        self::assertTrue($bounds[1]->greaterThan(Money::fromString('USD', '25000.00', 2)));
    }

    public function test_determine_order_spend_bounds_with_zero_fees(): void
    {
        $method = new ReflectionMethod(OrderSpendAnalyzer::class, 'determineOrderSpendBounds');
        $method->setAccessible(true);

        $order = OrderFactory::buy(
            base: 'EUR',
            quote: 'USD',
            minAmount: '50.00',
            maxAmount: '100.00',
            rate: '1.050',
            amountScale: 2,
            rateScale: 3,
            // No fee policy = zero fees
        );

        $analyzer = new OrderSpendAnalyzer();
        $bounds = $method->invoke($analyzer, $order);

        self::assertCount(2, $bounds);
        self::assertInstanceOf(Money::class, $bounds[0]);
        self::assertInstanceOf(Money::class, $bounds[1]);
        self::assertSame('EUR', $bounds[0]->currency());
        self::assertSame('EUR', $bounds[1]->currency());
        // With zero fees, gross should equal net for buy orders
        self::assertTrue($bounds[0]->equals(Money::fromString('EUR', '50.00', 2)));
        self::assertTrue($bounds[1]->equals(Money::fromString('EUR', '100.00', 2)));
    }

    public function test_determine_order_spend_bounds_with_high_precision(): void
    {
        $method = new ReflectionMethod(OrderSpendAnalyzer::class, 'determineOrderSpendBounds');
        $method->setAccessible(true);

        $order = OrderFactory::sell(
            base: 'BTC',
            quote: 'USD',
            minAmount: '0.00100000',
            maxAmount: '0.00200000',
            rate: '30000.00000000',
            amountScale: 8,
            rateScale: 8,
            feePolicy: FeePolicyFactory::baseAndQuoteSurcharge('0.00000000', '0.00100000'),
        );

        $analyzer = new OrderSpendAnalyzer();
        $bounds = $method->invoke($analyzer, $order);

        self::assertCount(2, $bounds);
        self::assertInstanceOf(Money::class, $bounds[0]);
        self::assertInstanceOf(Money::class, $bounds[1]);
        self::assertSame('USD', $bounds[0]->currency());
        self::assertSame('USD', $bounds[1]->currency());
        self::assertSame(8, $bounds[0]->scale());
        self::assertSame(8, $bounds[1]->scale());
        // High precision should be preserved, with quote fees making amounts slightly higher
        self::assertTrue($bounds[0]->greaterThan(Money::fromString('USD', '30.00000000', 8)));
        self::assertTrue($bounds[1]->greaterThan(Money::fromString('USD', '60.00000000', 8)));
    }

    public function test_determine_order_spend_bounds_with_scale_normalization(): void
    {
        $method = new ReflectionMethod(OrderSpendAnalyzer::class, 'determineOrderSpendBounds');
        $method->setAccessible(true);

        $order = OrderFactory::buy(
            base: 'EUR',
            quote: 'USD',
            minAmount: '100.000',
            maxAmount: '200.000',
            rate: '1.1000',
            amountScale: 3,
            rateScale: 4,
            feePolicy: FeePolicyFactory::baseSurcharge('0.050'),
        );

        $analyzer = new OrderSpendAnalyzer();
        $bounds = $method->invoke($analyzer, $order);

        self::assertCount(2, $bounds);
        // Both bounds should have the same scale (normalized to the maximum)
        self::assertSame($bounds[0]->scale(), $bounds[1]->scale());
        self::assertGreaterThanOrEqual(3, $bounds[0]->scale());
    }

    public function test_determine_spend_currency_edge_cases(): void
    {
        $method = new ReflectionMethod(OrderSpendAnalyzer::class, 'determineSpendCurrency');
        $method->setAccessible(true);

        // Test with different currency pairs
        $orders = [
            OrderFactory::buy(base: 'USD', quote: 'EUR', minAmount: '100.00', maxAmount: '200.00', rate: '0.85', amountScale: 2, rateScale: 2),
            OrderFactory::buy(base: 'BTC', quote: 'ETH', minAmount: '0.1', maxAmount: '1.0', rate: '15.0', amountScale: 1, rateScale: 1),
            OrderFactory::sell(base: 'ETH', quote: 'USD', minAmount: '10.0', maxAmount: '50.0', rate: '2000.0', amountScale: 1, rateScale: 1),
            OrderFactory::sell(base: 'JPY', quote: 'KRW', minAmount: '1000', maxAmount: '5000', rate: '10.0', amountScale: 0, rateScale: 1),
        ];

        $analyzer = new OrderSpendAnalyzer();

        // BUY orders should return base currency
        self::assertSame('USD', $method->invoke($analyzer, $orders[0]));
        self::assertSame('BTC', $method->invoke($analyzer, $orders[1]));

        // SELL orders should return quote currency
        self::assertSame('USD', $method->invoke($analyzer, $orders[2]));
        self::assertSame('KRW', $method->invoke($analyzer, $orders[3]));
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
}
