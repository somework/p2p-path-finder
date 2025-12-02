<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Integration\Application\Order\Filter;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Order\Filter\CurrencyPairFilter;
use SomeWork\P2PPathFinder\Application\Order\Filter\MaximumAmountFilter;
use SomeWork\P2PPathFinder\Application\Order\Filter\MinimumAmountFilter;
use SomeWork\P2PPathFinder\Domain\Order\OrderBook;
use SomeWork\P2PPathFinder\Tests\Fixture\CurrencyScenarioFactory;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

/**
 * Integration tests for OrderBook filter interactions and multi-filter scenarios.
 *
 * Tests how filters work together and interact with OrderBook collections.
 */
final class OrderBookFilterIntegrationTest extends TestCase
{
    public function test_filter_composes_multiple_criteria(): void
    {
        $book = new OrderBook([
            OrderFactory::buy(),
            OrderFactory::buy(minAmount: '0.600', maxAmount: '2.000', rate: '30500'),
            OrderFactory::buy(minAmount: '0.050', maxAmount: '0.400', rate: '29900'),
            OrderFactory::buy(base: 'ETH', rate: '2000'),
        ]);

        $filters = [
            new CurrencyPairFilter(CurrencyScenarioFactory::assetPair('BTC', 'USD')),
            new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.500', 3)),
            new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.500', 3)),
        ];

        $filtered = iterator_to_array($book->filter(...$filters));

        self::assertCount(1, $filtered);
        self::assertTrue($filtered[0]->bounds()->min()->equals(CurrencyScenarioFactory::money('BTC', '0.100', 3)));
    }

    public function test_amount_filters_ignore_currency_mismatches(): void
    {
        $order = OrderFactory::buy();
        $book = new OrderBook([$order]);
        $foreignMoney = CurrencyScenarioFactory::money('ETH', '1.000', 3);

        $minFilter = new MinimumAmountFilter($foreignMoney);
        $maxFilter = new MaximumAmountFilter($foreignMoney);

        $minFiltered = iterator_to_array($book->filter($minFilter));
        $maxFiltered = iterator_to_array($book->filter($maxFilter));

        self::assertCount(0, $minFiltered);
        self::assertCount(0, $maxFiltered);
    }

    public function test_filter_returns_empty_when_all_orders_rejected(): void
    {
        $book = new OrderBook([
            OrderFactory::buy(minAmount: '0.100', maxAmount: '0.500'),
            OrderFactory::buy(minAmount: '0.200', maxAmount: '0.600'),
        ]);

        $filter = new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '1.000', 3));
        $filtered = iterator_to_array($book->filter($filter));

        self::assertCount(0, $filtered);
    }

    public function test_filter_accepts_all_orders_when_all_pass(): void
    {
        $first = OrderFactory::buy(minAmount: '0.100', maxAmount: '1.000');
        $second = OrderFactory::buy(minAmount: '0.200', maxAmount: '2.000');
        $third = OrderFactory::buy(minAmount: '0.300', maxAmount: '3.000');

        $book = new OrderBook([$first, $second, $third]);

        $filter = new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.500', 3)); // All orders have min <= 0.500
        $filtered = iterator_to_array($book->filter($filter));

        self::assertCount(3, $filtered);
        self::assertSame([$first, $second, $third], $filtered);
    }

    public function test_filter_handles_empty_order_book(): void
    {
        $book = new OrderBook([]);

        $filter = new CurrencyPairFilter(CurrencyScenarioFactory::assetPair('BTC', 'USD'));
        $filtered = iterator_to_array($book->filter($filter));

        self::assertCount(0, $filtered);
    }

    public function test_multiple_filters_with_complex_criteria(): void
    {
        $orders = [
            // Should pass: BTC/USD, min=0.200 <= 0.250 (min filter), max=0.800 >= 0.700 (max filter)
            OrderFactory::buy(minAmount: '0.200', maxAmount: '0.800', rate: '30000'),
            // Should fail: min too high (0.300 > 0.250)
            OrderFactory::buy(minAmount: '0.300', maxAmount: '0.500', rate: '30100'),
            // Should fail: max too low (0.600 < 0.700)
            OrderFactory::buy(minAmount: '0.150', maxAmount: '0.600', rate: '30200'),
            // Should fail: wrong currency pair
            OrderFactory::buy(base: 'ETH', minAmount: '0.200', maxAmount: '0.800', rate: '2000'),
        ];

        $book = new OrderBook($orders);

        $filters = [
            new CurrencyPairFilter(CurrencyScenarioFactory::assetPair('BTC', 'USD')),
            new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.250', 3)), // accepts min <= 0.250
            new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.700', 3)), // accepts max >= 0.700
        ];

        $filtered = iterator_to_array($book->filter(...$filters));

        self::assertCount(1, $filtered);
        self::assertSame($orders[0], $filtered[0]);
    }

    public function test_filter_chain_preserves_order(): void
    {
        $first = OrderFactory::buy(minAmount: '0.100', maxAmount: '1.000');
        $second = OrderFactory::buy(minAmount: '0.200', maxAmount: '2.000');
        $third = OrderFactory::buy(minAmount: '0.300', maxAmount: '3.000');

        $book = new OrderBook([$first, $second, $third]);

        $filters = [
            new CurrencyPairFilter(CurrencyScenarioFactory::assetPair('BTC', 'USD')),
            new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.500', 3)), // All orders have min <= 0.500
        ];

        $filtered = iterator_to_array($book->filter(...$filters));

        self::assertCount(3, $filtered);
        self::assertSame([$first, $second, $third], $filtered);
    }
}
