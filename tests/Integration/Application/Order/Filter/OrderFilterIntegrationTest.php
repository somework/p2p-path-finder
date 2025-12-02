<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Integration\Application\Order\Filter;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Order\Filter\CurrencyPairFilter;
use SomeWork\P2PPathFinder\Application\Order\Filter\MaximumAmountFilter;
use SomeWork\P2PPathFinder\Application\Order\Filter\MinimumAmountFilter;
use SomeWork\P2PPathFinder\Application\Order\Filter\ToleranceWindowFilter;
use SomeWork\P2PPathFinder\Domain\Order\OrderBook;
use SomeWork\P2PPathFinder\Tests\Fixture\CurrencyScenarioFactory;
use SomeWork\P2PPathFinder\Tests\Fixture\FeePolicyFactory;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

use function count;
use function sprintf;

/**
 * Integration tests for order filter combinations, edge cases, and error paths.
 *
 * Tests filter interactions, boundary conditions, scale mismatches, and performance
 * with complex filter chains.
 */
final class OrderFilterIntegrationTest extends TestCase
{
    // ==================== Filter Chain Interactions ====================

    public function test_filter_chain_with_conflicting_constraints(): void
    {
        $orders = [
            OrderFactory::buy(minAmount: '0.100', maxAmount: '0.500', rate: '30000'),
            OrderFactory::buy(minAmount: '0.600', maxAmount: '2.000', rate: '30100'),
            OrderFactory::buy(minAmount: '2.100', maxAmount: '5.000', rate: '30200'),
        ];

        $book = new OrderBook($orders);

        // Conflicting: min must be >= 0.5 AND max must be <= 1.0
        // Only order with range overlapping [0.5, 1.0] should pass
        $filters = [
            new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.500', 3)),
            new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '1.000', 3)),
        ];

        $filtered = iterator_to_array($book->filter(...$filters));

        self::assertCount(0, $filtered, 'Conflicting constraints should filter out all orders');
    }

    public function test_filter_chain_with_complementary_constraints(): void
    {
        $orders = [
            OrderFactory::buy(minAmount: '0.100', maxAmount: '0.500', rate: '30000'),
            OrderFactory::buy(minAmount: '0.400', maxAmount: '0.900', rate: '30100'),
            OrderFactory::buy(minAmount: '1.000', maxAmount: '5.000', rate: '30200'),
        ];

        $book = new OrderBook($orders);

        // Complementary: order.min <= 0.5 AND order.max >= 0.8 AND rate in tolerance
        $filters = [
            new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.500', 3)),
            new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.800', 3)),
            new ToleranceWindowFilter(CurrencyScenarioFactory::exchangeRate('BTC', 'USD', '30100', 2), '0.01'),
        ];

        $filtered = iterator_to_array($book->filter(...$filters));

        // Only second order: min 0.400 <= 0.500 AND max 0.900 >= 0.800 AND rate within tolerance
        self::assertCount(1, $filtered);
        self::assertStringContainsString('30100', $filtered[0]->effectiveRate()->rate());
    }

    public function test_all_orders_filtered_returns_empty(): void
    {
        $orders = [
            OrderFactory::buy(base: 'BTC', quote: 'USD', rate: '30000'),
            OrderFactory::buy(base: 'BTC', quote: 'USD', rate: '30100'),
            OrderFactory::buy(base: 'BTC', quote: 'USD', rate: '30200'),
        ];

        $book = new OrderBook($orders);

        // Filter for ETH pairs - should filter out all BTC orders
        $filter = new CurrencyPairFilter(CurrencyScenarioFactory::assetPair('ETH', 'USD'));
        $filtered = iterator_to_array($book->filter($filter));

        self::assertCount(0, $filtered);
    }

    // ==================== Tolerance Window at Exact Boundaries ====================

    public function test_tolerance_window_at_exact_boundaries(): void
    {
        $reference = CurrencyScenarioFactory::exchangeRate('BTC', 'USD', '30000.00', 2);
        $filter = new ToleranceWindowFilter($reference, '0.05'); // 5% = ±1500

        // Exact lower bound: 30000 - 1500 = 28500
        $lowerBound = OrderFactory::buy(rate: '28500.00');
        self::assertTrue($filter->accepts($lowerBound), 'Lower boundary should be accepted');

        // Exact upper bound: 30000 + 1500 = 31500
        $upperBound = OrderFactory::buy(rate: '31500.00');
        self::assertTrue($filter->accepts($upperBound), 'Upper boundary should be accepted');

        // Just outside lower bound
        $belowLower = OrderFactory::buy(rate: '28499.99');
        self::assertFalse($filter->accepts($belowLower), 'Below lower boundary should be rejected');

        // Just outside upper bound
        $aboveUpper = OrderFactory::buy(rate: '31500.01');
        self::assertFalse($filter->accepts($aboveUpper), 'Above upper boundary should be rejected');
    }

    public function test_tolerance_window_with_rounding_at_boundary(): void
    {
        $reference = CurrencyScenarioFactory::exchangeRate('BTC', 'USD', '100.00', 2);
        $filter = new ToleranceWindowFilter($reference, '0.015'); // 1.5% = ±1.50

        // Boundaries: 98.50 to 101.50
        $atLower = OrderFactory::buy(rate: '98.50');
        $atUpper = OrderFactory::buy(rate: '101.50');

        self::assertTrue($filter->accepts($atLower));
        self::assertTrue($filter->accepts($atUpper));
    }

    // ==================== Scale Mismatches ====================

    public function test_filters_with_mixed_currency_scales(): void
    {
        // Order with scale 8 (like BTC)
        $order = OrderFactory::buy(minAmount: '0.00100000', maxAmount: '1.00000000', amountScale: 8);

        // Filter with scale 3
        $filter = new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.001', 3));

        // Should accept despite scale mismatch
        self::assertTrue($filter->accepts($order));
    }

    public function test_amount_filter_normalizes_scales_correctly(): void
    {
        // Order with high precision scale
        $order = OrderFactory::buy(
            minAmount: '0.120000000000000000',
            maxAmount: '1.000000000000000000',
            amountScale: 18
        );

        // Filter with low precision
        $minFilter = new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.12', 2));
        $maxFilter = new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '1.00', 2));

        // 0.12 at scale 18 equals 0.12 at scale 2
        self::assertTrue($minFilter->accepts($order));
        // 1.0 at scale 2 matches 1.0 at scale 18
        self::assertTrue($maxFilter->accepts($order));
    }

    public function test_tolerance_window_with_different_rate_scales(): void
    {
        // Reference with scale 2
        $reference = CurrencyScenarioFactory::exchangeRate('BTC', 'USD', '30000.00', 2);
        $filter = new ToleranceWindowFilter($reference, '0.05');

        // Order with scale 8
        $order = OrderFactory::buy(rate: '30100.00000000', rateScale: 8);

        self::assertTrue($filter->accepts($order));
    }

    // ==================== Currency Normalization ====================

    public function test_currency_pair_filter_case_sensitivity(): void
    {
        // AssetPair normalizes to uppercase
        $filter = new CurrencyPairFilter(CurrencyScenarioFactory::assetPair('btc', 'usd'));

        $upperOrder = OrderFactory::buy(base: 'BTC', quote: 'USD');
        $lowerOrder = OrderFactory::buy(base: 'btc', quote: 'usd');
        $mixedOrder = OrderFactory::buy(base: 'Btc', quote: 'UsD');

        // All should be accepted (currencies are normalized to uppercase)
        self::assertTrue($filter->accepts($upperOrder));
        self::assertTrue($filter->accepts($lowerOrder));
        self::assertTrue($filter->accepts($mixedOrder));
    }

    public function test_amount_filters_with_case_insensitive_currencies(): void
    {
        $order = OrderFactory::buy(base: 'BTC');

        // Filters created with lowercase currencies
        $minFilter = new MinimumAmountFilter(CurrencyScenarioFactory::money('btc', '0.100', 3));
        $maxFilter = new MaximumAmountFilter(CurrencyScenarioFactory::money('btc', '1.000', 3));

        self::assertTrue($minFilter->accepts($order));
        self::assertTrue($maxFilter->accepts($order));
    }

    // ==================== Extreme Values ====================

    public function test_filters_with_very_large_amounts(): void
    {
        $largeOrder = OrderFactory::buy(
            minAmount: '1000000001.000000000000000000',
            maxAmount: '999999999999.999999999999999999',
            amountScale: 18
        );

        $filter = new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '1000000000.0', 1));

        // Order min (1000000001.0) is greater than filter amount (1000000000.0)
        self::assertFalse($filter->accepts($largeOrder));
    }

    public function test_filters_with_very_small_amounts(): void
    {
        $tinyOrder = OrderFactory::buy(
            minAmount: '0.000000000000000001',
            maxAmount: '0.000000000000000100',
            amountScale: 18
        );

        // MaximumAmountFilter accepts if order.max >= filter.amount
        // Order max is 0.0000000000000001 (100 in smallest unit at scale 18)
        // Filter amount is 0.000000000000000050 (50 in smallest unit at scale 18)
        $filter = new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.000000000000000050', 18));

        // Order max (100) >= filter amount (50), should accept
        self::assertTrue($filter->accepts($tinyOrder));
    }

    public function test_tolerance_window_with_extreme_precision(): void
    {
        $reference = CurrencyScenarioFactory::exchangeRate(
            'ETH',
            'BTC',
            '0.045678901234567890',
            18
        );
        $filter = new ToleranceWindowFilter($reference, '0.000000000000000001');

        // Exact match
        $exactOrder = OrderFactory::buy(
            base: 'ETH',
            quote: 'BTC',
            rate: '0.045678901234567890',
            rateScale: 18
        );
        self::assertTrue($filter->accepts($exactOrder));

        // Slightly different
        $differentOrder = OrderFactory::buy(
            base: 'ETH',
            quote: 'BTC',
            rate: '0.045678901234567891',
            rateScale: 18
        );
        self::assertFalse($filter->accepts($differentOrder));
    }

    // ==================== Filters with Fee Policies ====================

    public function test_amount_filters_with_fee_policies(): void
    {
        // Order with fees - min/max bounds don't include fees
        $order = OrderFactory::buy(
            minAmount: '0.100',
            maxAmount: '1.000',
            amountScale: 3,
            feePolicy: FeePolicyFactory::baseSurcharge('0.01')
        );

        $minFilter = new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.100', 3));
        $maxFilter = new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '1.000', 3));

        // Filters check order bounds, not fee-adjusted amounts
        self::assertTrue($minFilter->accepts($order));
        self::assertTrue($maxFilter->accepts($order));
    }

    public function test_tolerance_window_with_fee_policies(): void
    {
        $reference = CurrencyScenarioFactory::exchangeRate('BTC', 'USD', '30000', 2);
        $filter = new ToleranceWindowFilter($reference, '0.05');

        // Order with fees - effective rate should include fee impact
        $order = OrderFactory::buy(
            rate: '30100',
            feePolicy: FeePolicyFactory::quotePercentageWithFixed('0.01', '0.00', 4)
        );

        // Effective rate is different from raw rate when fees are present
        self::assertTrue($filter->accepts($order));
    }

    // ==================== Empty and Edge Cases ====================

    public function test_filter_chain_with_no_filters(): void
    {
        $orders = [
            OrderFactory::buy(),
            OrderFactory::buy(rate: '30100'),
            OrderFactory::buy(rate: '30200'),
        ];

        $book = new OrderBook($orders);
        $filtered = iterator_to_array($book->filter(/* no filters */));

        self::assertCount(3, $filtered);
    }

    public function test_filter_with_single_order(): void
    {
        $order = OrderFactory::buy(minAmount: '0.100', maxAmount: '1.000');
        $book = new OrderBook([$order]);

        $filter = new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.100', 3));
        $filtered = iterator_to_array($book->filter($filter));

        self::assertCount(1, $filtered);
        self::assertSame($order, $filtered[0]);
    }

    public function test_multiple_currency_pairs_in_mixed_order_book(): void
    {
        $orders = [
            OrderFactory::buy(base: 'BTC', quote: 'USD'),
            OrderFactory::buy(base: 'ETH', quote: 'USD'),
            OrderFactory::buy(base: 'BTC', quote: 'EUR'),
            OrderFactory::buy(base: 'ETH', quote: 'BTC'),
        ];

        $book = new OrderBook($orders);

        $btcUsdFilter = new CurrencyPairFilter(CurrencyScenarioFactory::assetPair('BTC', 'USD'));
        $filtered = iterator_to_array($book->filter($btcUsdFilter));

        self::assertCount(1, $filtered);
        self::assertSame('BTC', $filtered[0]->assetPair()->base());
        self::assertSame('USD', $filtered[0]->assetPair()->quote());
    }

    // ==================== Filter Performance ====================

    public function test_filter_performance_with_large_order_book(): void
    {
        // Create 1000 orders
        $orders = [];
        for ($i = 0; $i < 1000; ++$i) {
            $orders[] = OrderFactory::buy(
                minAmount: sprintf('0.%03d', $i % 1000),
                maxAmount: sprintf('1.%03d', $i % 1000),
                rate: sprintf('%d', 30000 + ($i % 100))
            );
        }

        $book = new OrderBook($orders);

        $filters = [
            new CurrencyPairFilter(CurrencyScenarioFactory::assetPair('BTC', 'USD')),
            new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.500', 3)),
            new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.700', 3)),
            new ToleranceWindowFilter(CurrencyScenarioFactory::exchangeRate('BTC', 'USD', '30050', 2), '0.01'),
        ];

        $startTime = microtime(true);
        $filtered = iterator_to_array($book->filter(...$filters));
        $endTime = microtime(true);

        // Should complete in reasonable time (< 100ms)
        $duration = ($endTime - $startTime) * 1000;
        self::assertLessThan(100, $duration, 'Filtering 1000 orders should complete in < 100ms');

        // Verify some orders passed through
        self::assertGreaterThan(0, count($filtered));
    }

    public function test_filter_chain_preserves_order(): void
    {
        $first = OrderFactory::buy(minAmount: '0.100', maxAmount: '1.000', rate: '30000');
        $second = OrderFactory::buy(minAmount: '0.200', maxAmount: '2.000', rate: '30050');
        $third = OrderFactory::buy(minAmount: '0.300', maxAmount: '3.000', rate: '30100');

        $book = new OrderBook([$first, $second, $third]);

        $filter = new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.500', 3));
        $filtered = iterator_to_array($book->filter($filter));

        // All orders should pass and maintain original order
        self::assertCount(3, $filtered);
        self::assertSame($first, $filtered[0]);
        self::assertSame($second, $filtered[1]);
        self::assertSame($third, $filtered[2]);
    }

    // ==================== Boundary Precision Tests ====================

    public function test_minimum_amount_filter_with_precision_boundary(): void
    {
        // Order with amount that rounds differently at different scales
        $order = OrderFactory::buy(minAmount: '0.1005', maxAmount: '1.000', amountScale: 4);

        // Filter at scale 3: 0.1005 rounds to 0.101
        $filterScale3 = new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.100', 3));
        self::assertFalse($filterScale3->accepts($order)); // 0.1005 (scale 4) > 0.100 (scale 3) when comparing

        // Filter at scale 2: 0.1005 rounds to 0.10
        $filterScale2 = new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.10', 2));
        self::assertFalse($filterScale2->accepts($order));
    }

    public function test_maximum_amount_filter_with_precision_boundary(): void
    {
        $order = OrderFactory::buy(minAmount: '0.100', maxAmount: '1.005', amountScale: 3);

        // MaximumAmountFilter accepts if order.max is NOT less than filter amount
        // In other words: order.max >= filter.amount

        // Filter at scale 2: 1.00
        $filterScale2 = new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '1.00', 2));
        // Filter amount 1.00 is scaled to order scale 3 => 1.000
        // Order max 1.005 >= 1.000, so accepts
        self::assertTrue($filterScale2->accepts($order));

        // Filter at scale 3: 1.006 (slightly above order max)
        $filterAboveMax = new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '1.006', 3));
        // Order max 1.005 < 1.006, so rejects
        self::assertFalse($filterAboveMax->accepts($order));

        // Filter at scale 3: 1.005 (exactly at order max)
        $filterAtMax = new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '1.005', 3));
        // Order max 1.005 >= 1.005, so accepts
        self::assertTrue($filterAtMax->accepts($order));
    }
}
