<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Integration\Domain\Order;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Order\Filter\CurrencyPairFilter;
use SomeWork\P2PPathFinder\Application\Order\Filter\MaximumAmountFilter;
use SomeWork\P2PPathFinder\Application\Order\Filter\MinimumAmountFilter;
use SomeWork\P2PPathFinder\Domain\Order\OrderBook;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Tests\Fixture\CurrencyScenarioFactory;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

use function count;

#[CoversClass(OrderBook::class)]
final class OrderBookIntegrationTest extends TestCase
{
    public function test_large_order_book_can_be_iterated(): void
    {
        $orders = [];
        for ($i = 0; $i < 1000; ++$i) {
            $orders[] = OrderFactory::buy(rate: (string) (30000 + $i));
        }

        $book = new OrderBook($orders);
        $result = iterator_to_array($book);

        self::assertCount(1000, $result);
        self::assertSame($orders, $result);
    }

    public function test_large_order_book_can_be_filtered(): void
    {
        $orders = [];
        for ($i = 0; $i < 1000; ++$i) {
            $orders[] = OrderFactory::buy(minAmount: '0.100', maxAmount: '1.000', rate: (string) (30000 + $i));
        }

        $book = new OrderBook($orders);
        $filter = new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.500', 3)); // All orders have min=0.100 <= 0.500
        $filtered = iterator_to_array($book->filter($filter));

        self::assertCount(1000, $filtered); // All should pass since min=0.100 <= 0.500
    }

    public function test_very_large_order_book_memory_efficiency(): void
    {
        $orders = [];
        for ($i = 0; $i < 10000; ++$i) {
            $orders[] = OrderFactory::buy(rate: (string) (30000 + $i % 1000));
        }

        $book = new OrderBook($orders);

        // Test that we can iterate without memory issues
        $count = 0;
        foreach ($book as $order) {
            ++$count;
            if ($count >= 5000) {
                break; // Early termination test
            }
        }

        self::assertSame(5000, $count);

        // Test full iteration
        $result = iterator_to_array($book);
        self::assertCount(10000, $result);
    }

    public function test_large_order_book_with_complex_filters(): void
    {
        $orders = [];
        for ($i = 0; $i < 5000; ++$i) {
            // Create mix of buy/sell orders with varying amounts
            $side = 0 === $i % 2 ? OrderSide::BUY : OrderSide::SELL;
            $minAmount = (string) (0.010 + ($i % 10) * 0.005);
            $maxAmount = (string) (0.100 + ($i % 20) * 0.010);
            $rate = (string) (30000 + $i % 500);

            if (OrderSide::BUY === $side) {
                $orders[] = OrderFactory::buy(minAmount: $minAmount, maxAmount: $maxAmount, rate: $rate);
            } else {
                $orders[] = OrderFactory::sell(minAmount: $minAmount, maxAmount: $maxAmount, rate: $rate);
            }
        }

        $book = new OrderBook($orders);

        // Apply multiple complex filters
        $filters = [
            new CurrencyPairFilter(CurrencyScenarioFactory::assetPair('BTC', 'USD')),
            new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.020', 3)),
            new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.200', 3)),
        ];

        $filtered = iterator_to_array($book->filter(...$filters));

        // Should have some orders filtered out
        self::assertLessThan(5000, count($filtered));
        self::assertGreaterThan(0, count($filtered));
    }

    public function test_large_order_book_filtering_performance(): void
    {
        $orders = [];
        for ($i = 0; $i < 2000; ++$i) {
            $orders[] = OrderFactory::buy(
                minAmount: (string) (0.001 + ($i % 100) * 0.001),
                maxAmount: (string) (0.010 + ($i % 100) * 0.001),
                rate: (string) (30000 + $i % 1000)
            );
        }

        $book = new OrderBook($orders);

        $startTime = microtime(true);

        // Apply filter that will reject many orders
        $filter = new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.050', 3));
        $filtered = iterator_to_array($book->filter($filter));

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;

        // Should complete in reasonable time (less than 1 second for 2000 orders)
        self::assertLessThan(1.0, $executionTime);

        // Should filter out most orders (only orders with min <= 0.050 pass)
        self::assertLessThan(2000, count($filtered));
        self::assertGreaterThan(0, count($filtered));
    }

    public function test_order_book_with_highly_selective_filters(): void
    {
        // Create orders where only a few will match very specific criteria
        $orders = [];
        for ($i = 0; $i < 1000; ++$i) {
            $minAmount = 500 === $i ? '0.050' : '0.100'; // Only one order has min=0.050
            $maxAmount = 500 === $i ? '0.500' : '1.000'; // Only one order has max=0.500
            $orders[] = OrderFactory::buy(minAmount: $minAmount, maxAmount: $maxAmount, rate: (string) (30000 + $i));
        }

        $book = new OrderBook($orders);

        // Very selective filters that should match only one order
        $filters = [
            new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.075', 3)), // Only accepts min <= 0.075
            new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.600', 3)), // Only accepts max >= 0.600
        ];

        $filtered = iterator_to_array($book->filter(...$filters));

        // Only the special order should pass (min=0.050 <= 0.075 AND max=0.500 >= 0.600? Wait, that's wrong)
        // Let me fix this logic
        $filtersCorrect = [
            new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.075', 3)), // accepts min <= 0.075
            new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.400', 3)), // accepts max >= 0.400
        ];

        $filteredCorrect = iterator_to_array($book->filter(...$filtersCorrect));

        // Only the order with min=0.050 and max=0.500 should pass
        self::assertCount(1, $filteredCorrect);
    }

    public function test_multiple_iterations_on_large_order_book(): void
    {
        $orders = [];
        for ($i = 0; $i < 1500; ++$i) {
            $orders[] = OrderFactory::buy(rate: (string) (30000 + $i % 100));
        }

        $book = new OrderBook($orders);

        // First iteration
        $firstIteration = iterator_to_array($book);
        self::assertCount(1500, $firstIteration);

        // Second iteration should be identical
        $secondIteration = iterator_to_array($book);
        self::assertSame($firstIteration, $secondIteration);

        // Partial iterations
        $partialCount = 0;
        foreach ($book as $order) {
            ++$partialCount;
            if ($partialCount >= 750) {
                break;
            }
        }
        self::assertSame(750, $partialCount);

        // Full iteration after partial should still work
        $thirdIteration = iterator_to_array($book);
        self::assertCount(1500, $thirdIteration);
        self::assertSame($firstIteration, $thirdIteration);
    }

    public function test_large_order_book_with_empty_filter_results(): void
    {
        $orders = [];
        for ($i = 0; $i < 1000; ++$i) {
            // All orders have min=0.100
            $orders[] = OrderFactory::buy(minAmount: '0.100', maxAmount: '1.000', rate: (string) (30000 + $i));
        }

        $book = new OrderBook($orders);

        // Filter that rejects all orders (min=0.100 > 0.050, so doesn't pass min filter)
        $filter = new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.050', 3));
        $filtered = iterator_to_array($book->filter($filter));

        self::assertCount(0, $filtered);
    }

    public function test_large_order_book_mixed_operations(): void
    {
        $book = new OrderBook();

        // Add orders incrementally
        for ($i = 0; $i < 1000; ++$i) {
            $book->add(OrderFactory::buy(rate: (string) (30000 + $i)));
        }

        // Test iteration
        $iterated = iterator_to_array($book);
        self::assertCount(1000, $iterated);

        // Test filtering
        $filter = new CurrencyPairFilter(CurrencyScenarioFactory::assetPair('BTC', 'USD'));
        $filtered = iterator_to_array($book->filter($filter));
        self::assertCount(1000, $filtered);

        // Add more orders
        for ($i = 1000; $i < 1500; ++$i) {
            $book->add(OrderFactory::sell(rate: (string) (30000 + $i)));
        }

        // Test combined operations
        $finalCount = iterator_to_array($book);
        self::assertCount(1500, $finalCount);
    }

    public function test_order_book_scalability_boundary(): void
    {
        // Test the boundary where performance might degrade
        $orders = [];
        for ($i = 0; $i < 5000; ++$i) {
            $orders[] = OrderFactory::buy(
                minAmount: (string) (0.001 + ($i % 1000) * 0.0001),
                maxAmount: (string) (0.010 + ($i % 1000) * 0.0001),
                rate: (string) (30000 + $i % 10000)
            );
        }

        $book = new OrderBook($orders);

        $startTime = microtime(true);
        $result = iterator_to_array($book);
        $endTime = microtime(true);

        self::assertCount(5000, $result);
        self::assertLessThan(2.0, $endTime - $startTime); // Should complete in reasonable time
    }

    public function test_complex_filtering_with_large_dataset(): void
    {
        $orders = [];
        $expectedPassing = 0;

        for ($i = 0; $i < 3000; ++$i) {
            // Create orders that mostly won't pass the filters
            $baseMin = 0.100 + ($i % 50) * 0.010;
            $baseMax = $baseMin + 0.050 + ($i % 30) * 0.005; // Ensure max > min always
            $minAmount = (string) $baseMin;
            $maxAmount = (string) $baseMax;
            $rate = (string) (30000 + $i % 2000);

            // Create some orders that will DEFINITELY pass both filters
            if (0 === $i % 200) {
                // These will pass: min <= 0.030 and max >= 0.200
                $minAmount = '0.020';
                $maxAmount = '0.250';
                ++$expectedPassing;
            }

            $orders[] = OrderFactory::buy(minAmount: $minAmount, maxAmount: $maxAmount, rate: $rate);
        }

        $book = new OrderBook($orders);

        $filters = [
            new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.030', 3)), // accepts min <= 0.030
            new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.200', 3)), // accepts max >= 0.200
        ];

        $filtered = iterator_to_array($book->filter(...$filters));

        // Should have exactly the orders we designed to pass (every 200th order)
        self::assertCount($expectedPassing, $filtered);
        self::assertSame(15, $expectedPassing); // 3000 / 200 = 15
    }
}
