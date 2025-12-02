<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\Order\Filter;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Order\Filter\MaximumAmountFilter;
use SomeWork\P2PPathFinder\Application\Order\Filter\MinimumAmountFilter;
use SomeWork\P2PPathFinder\Tests\Fixture\CurrencyScenarioFactory;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

/**
 * Unit tests for MaximumAmountFilter.
 */
final class MaximumAmountFilterTest extends TestCase
{
    // ==================== Positive Test Cases ====================

    public function test_maximum_amount_filter_accepts_order_at_exact_boundary(): void
    {
        $order = OrderFactory::buy(minAmount: '0.100', maxAmount: '1.000');
        $filter = new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '1.000', 3));

        self::assertTrue($filter->accepts($order));
    }

    public function test_maximum_amount_filter_accepts_order_above_boundary(): void
    {
        $order = OrderFactory::buy(minAmount: '0.100', maxAmount: '1.500');
        $filter = new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '1.000', 3));

        self::assertTrue($filter->accepts($order));
    }

    public function test_maximum_amount_filter_accepts_order_with_lower_precision(): void
    {
        $order = OrderFactory::buy(minAmount: '0.100', maxAmount: '1.000', amountScale: 3);
        $filter = new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '1.00', 2)); // Lower precision

        self::assertTrue($filter->accepts($order));
    }

    // ==================== Negative Test Cases ====================

    public function test_maximum_amount_filter_rejects_order_below_boundary(): void
    {
        $order = OrderFactory::buy(minAmount: '0.100', maxAmount: '0.999');
        $filter = new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '1.000', 3));

        self::assertFalse($filter->accepts($order));
    }

    public function test_maximum_amount_filter_rejects_currency_mismatch(): void
    {
        $order = OrderFactory::buy(); // BTC order
        $filter = new MaximumAmountFilter(CurrencyScenarioFactory::money('ETH', '1.000', 3));

        self::assertFalse($filter->accepts($order));
    }

    public function test_maximum_amount_filter_rejects_with_higher_precision(): void
    {
        $order = OrderFactory::buy(minAmount: '0.100', maxAmount: '0.999999', amountScale: 6);
        $filter = new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '1.000', 3));

        self::assertFalse($filter->accepts($order));
    }

    // ==================== Edge Cases ====================

    public function test_maximum_amount_filter_with_zero_maximum_amount(): void
    {
        $order = OrderFactory::buy(minAmount: '0.000', maxAmount: '0.000');
        $filter = new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.000', 3));

        self::assertTrue($filter->accepts($order));
    }

    public function test_maximum_amount_filter_with_very_small_amounts(): void
    {
        $order = OrderFactory::buy(minAmount: '0.000000001', maxAmount: '0.000000002', amountScale: 9);
        $filter = new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.000000001', 9));

        self::assertTrue($filter->accepts($order));
    }

    public function test_maximum_amount_filter_with_very_large_amounts(): void
    {
        $order = OrderFactory::buy(minAmount: '1000000.000', maxAmount: '2000000.000', amountScale: 3);
        $filter = new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '3000000.000', 3));

        self::assertFalse($filter->accepts($order));
    }

    public function test_maximum_amount_filter_with_scale_mismatch(): void
    {
        // Order with scale 8, filter with scale 3
        $order = OrderFactory::buy(minAmount: '0.10000000', maxAmount: '1.50000000', amountScale: 8);
        $filter = new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '1.500', 3));

        // Should accept since 1.50000000 at scale 8 equals 1.500 at scale 3
        self::assertTrue($filter->accepts($order));
    }

    public function test_maximum_amount_filter_with_precision_boundary(): void
    {
        // Order max: 1.005 at scale 3, filter: 1.00 at scale 2
        $order = OrderFactory::buy(minAmount: '0.100', maxAmount: '1.005', amountScale: 3);
        $filter = new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '1.00', 2));

        // Order max 1.005 >= filter amount 1.00 (scaled to 1.000)
        self::assertTrue($filter->accepts($order));
    }

    public function test_maximum_amount_filter_with_sell_orders(): void
    {
        $order = OrderFactory::sell(minAmount: '0.100', maxAmount: '2.000');
        $filter = new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '1.500', 3));

        self::assertTrue($filter->accepts($order));
    }

    public function test_maximum_amount_filter_with_custom_fee_policy(): void
    {
        $order = OrderFactory::buy(
            minAmount: '0.100',
            maxAmount: '1.000',
            feePolicy: \SomeWork\P2PPathFinder\Tests\Fixture\FeePolicyFactory::baseSurcharge('0.01')
        );
        $filter = new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '1.000', 3));

        // Filter checks order bounds, not fee-adjusted amounts
        self::assertTrue($filter->accepts($order));
    }

    public function test_amount_filters_ignore_currency_mismatches(): void
    {
        $order = OrderFactory::buy();
        $foreignMoney = CurrencyScenarioFactory::money('ETH', '1.000', 3);

        $minFilter = new MinimumAmountFilter($foreignMoney);
        $maxFilter = new MaximumAmountFilter($foreignMoney);

        self::assertFalse($minFilter->accepts($order));
        self::assertFalse($maxFilter->accepts($order));
    }

    // ==================== Boundary and Special Cases ====================

    public function test_maximum_amount_filter_at_maximum_precision(): void
    {
        $order = OrderFactory::buy(minAmount: '0.100', maxAmount: '0.123456789', amountScale: 9);
        $filter = new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.123456788', 9));

        self::assertTrue($filter->accepts($order));
    }

    public function test_maximum_amount_filter_with_fractional_cents(): void
    {
        $order = OrderFactory::buy(minAmount: '0.001', maxAmount: '0.002', amountScale: 3);
        $filter = new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.001', 3));

        self::assertTrue($filter->accepts($order));
    }

    public function test_maximum_amount_filter_boundary_precision_loss(): void
    {
        // Order max: 1.005 at scale 3, filter: 1.006 at scale 3
        $order = OrderFactory::buy(minAmount: '0.100', maxAmount: '1.005', amountScale: 3);
        $filter = new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '1.006', 3));

        // Order max 1.005 < filter amount 1.006, so rejects
        self::assertFalse($filter->accepts($order));
    }
}
