<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\Order\Filter;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Order\Filter\MinimumAmountFilter;
use SomeWork\P2PPathFinder\Tests\Fixture\CurrencyScenarioFactory;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

/**
 * Unit tests for MinimumAmountFilter.
 */
final class MinimumAmountFilterTest extends TestCase
{
    // ==================== Positive Test Cases ====================

    public function test_minimum_amount_filter_accepts_order_at_exact_boundary(): void
    {
        $order = OrderFactory::buy(minAmount: '0.100', maxAmount: '1.000');
        $filter = new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.100', 3));

        self::assertTrue($filter->accepts($order));
    }

    public function test_minimum_amount_filter_accepts_order_below_boundary(): void
    {
        $order = OrderFactory::buy(minAmount: '0.050', maxAmount: '1.000');
        $filter = new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.100', 3));

        self::assertTrue($filter->accepts($order));
    }

    public function test_minimum_amount_filter_accepts_order_with_higher_precision(): void
    {
        $order = OrderFactory::buy(minAmount: '0.099999', maxAmount: '1.000', amountScale: 6);
        $filter = new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.100', 3));

        self::assertTrue($filter->accepts($order));
    }

    // ==================== Negative Test Cases ====================

    public function test_minimum_amount_filter_rejects_order_above_boundary(): void
    {
        $order = OrderFactory::buy(minAmount: '0.101', maxAmount: '1.000');
        $filter = new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.100', 3));

        self::assertFalse($filter->accepts($order));
    }

    public function test_minimum_amount_filter_rejects_currency_mismatch(): void
    {
        $order = OrderFactory::buy(); // BTC order
        $filter = new MinimumAmountFilter(CurrencyScenarioFactory::money('ETH', '0.100', 3));

        self::assertFalse($filter->accepts($order));
    }

    public function test_minimum_amount_filter_rejects_with_lower_precision(): void
    {
        $order = OrderFactory::buy(minAmount: '0.101', maxAmount: '1.000', amountScale: 3);
        $filter = new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.100', 2)); // Lower precision

        self::assertFalse($filter->accepts($order));
    }

    // ==================== Edge Cases ====================

    public function test_minimum_amount_filter_with_zero_minimum_amount(): void
    {
        $order = OrderFactory::buy(minAmount: '0.000', maxAmount: '1.000');
        $filter = new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.000', 3));

        self::assertTrue($filter->accepts($order));
    }

    public function test_minimum_amount_filter_with_very_small_amounts(): void
    {
        $order = OrderFactory::buy(minAmount: '0.000000001', maxAmount: '1.000', amountScale: 9);
        $filter = new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.000000002', 9));

        self::assertTrue($filter->accepts($order));
    }

    public function test_minimum_amount_filter_with_very_large_amounts(): void
    {
        $order = OrderFactory::buy(minAmount: '1000000.000', maxAmount: '2000000.000', amountScale: 3);
        $filter = new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '500000.000', 3));

        self::assertFalse($filter->accepts($order));
    }

    public function test_minimum_amount_filter_with_scale_mismatch(): void
    {
        // Order with scale 8, filter with scale 3
        $order = OrderFactory::buy(minAmount: '0.10000000', maxAmount: '1.00000000', amountScale: 8);
        $filter = new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.100', 3));

        // Should accept since 0.10000000 at scale 8 equals 0.100 at scale 3
        self::assertTrue($filter->accepts($order));
    }

    public function test_minimum_amount_filter_with_precision_boundary(): void
    {
        // Order min: 0.1005 at scale 4 rounds to 0.101 at scale 3
        $order = OrderFactory::buy(minAmount: '0.1005', maxAmount: '1.000', amountScale: 4);
        $filter = new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.100', 3));

        // 0.1005 at scale 4 rounds up to 0.101 at scale 3, which is > 0.100
        self::assertFalse($filter->accepts($order));
    }

    public function test_minimum_amount_filter_with_sell_orders(): void
    {
        $order = OrderFactory::sell(minAmount: '0.050', maxAmount: '1.000');
        $filter = new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.100', 3));

        self::assertTrue($filter->accepts($order));
    }

    public function test_minimum_amount_filter_with_custom_fee_policy(): void
    {
        $order = OrderFactory::buy(
            minAmount: '0.100',
            maxAmount: '1.000',
            feePolicy: \SomeWork\P2PPathFinder\Tests\Fixture\FeePolicyFactory::baseSurcharge('0.01')
        );
        $filter = new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.100', 3));

        // Filter checks order bounds, not fee-adjusted amounts
        self::assertTrue($filter->accepts($order));
    }

    // ==================== Boundary and Special Cases ====================

    public function test_minimum_amount_filter_at_maximum_precision(): void
    {
        $order = OrderFactory::buy(minAmount: '0.123456789', maxAmount: '1.000', amountScale: 9);
        $filter = new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.123456788', 9));

        self::assertFalse($filter->accepts($order));
    }

    public function test_minimum_amount_filter_with_fractional_cents(): void
    {
        $order = OrderFactory::buy(minAmount: '0.001', maxAmount: '1.000', amountScale: 3);
        $filter = new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.001', 3));

        self::assertTrue($filter->accepts($order));
    }
}
