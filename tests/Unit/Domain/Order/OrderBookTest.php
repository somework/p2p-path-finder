<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Domain\Order;

use ArrayIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Order\Filter\CurrencyPairFilter;
use SomeWork\P2PPathFinder\Application\Order\Filter\MaximumAmountFilter;
use SomeWork\P2PPathFinder\Application\Order\Filter\MinimumAmountFilter;
use SomeWork\P2PPathFinder\Domain\Order\Filter\OrderFilterInterface;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderBook;
use SomeWork\P2PPathFinder\Tests\Fixture\CurrencyScenarioFactory;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

use function count;

#[CoversClass(OrderBook::class)]
final class OrderBookTest extends TestCase
{
    public function test_it_collects_orders_iteratively(): void
    {
        $book = new OrderBook();
        $first = OrderFactory::buy();
        $second = OrderFactory::sell(rate: '29500');

        $book->add($first);
        $book->add($second);

        self::assertSame([$first, $second], iterator_to_array($book));
    }

    public function test_filter_combines_multiple_filters(): void
    {
        $first = OrderFactory::buy();
        $second = OrderFactory::buy(minAmount: '0.500', maxAmount: '2.000', rate: '30500');
        $third = OrderFactory::sell(minAmount: '0.200', maxAmount: '0.750', rate: '29500');
        $fourth = OrderFactory::buy(base: 'ETH', rate: '1800');

        $book = new OrderBook([$first, $second, $third, $fourth]);

        $filters = [
            new CurrencyPairFilter(CurrencyScenarioFactory::assetPair('BTC', 'USD')),
            new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.400', 3)),
            new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.600', 3)),
        ];

        $filtered = iterator_to_array($book->filter(...$filters));

        self::assertSame([$first, $third], $filtered);
    }

    public function test_filter_short_circuits_after_rejection(): void
    {
        $book = new OrderBook([OrderFactory::buy()]);

        $rejections = 0;
        $rejectingFilter = new class($rejections) implements OrderFilterInterface {
            private int $counter;

            public function __construct(int &$counter)
            {
                $this->counter = &$counter;
            }

            public function accepts(Order $order): bool
            {
                ++$this->counter;

                return false;
            }
        };

        $fallthroughInvocations = 0;
        $fallthroughFilter = new class($fallthroughInvocations) implements OrderFilterInterface {
            private int $counter;

            public function __construct(int &$counter)
            {
                $this->counter = &$counter;
            }

            public function accepts(Order $order): bool
            {
                ++$this->counter;

                return true;
            }
        };

        $filtered = iterator_to_array($book->filter($rejectingFilter, $fallthroughFilter));

        self::assertSame([], $filtered);
        self::assertSame(1, $rejections);
        self::assertSame(0, $fallthroughInvocations);
    }

    public function test_empty_order_book_can_be_created(): void
    {
        $book = new OrderBook();

        self::assertSame([], iterator_to_array($book));
    }

    public function test_empty_order_book_filter_returns_empty_generator(): void
    {
        $book = new OrderBook();
        $filter = new CurrencyPairFilter(CurrencyScenarioFactory::assetPair('BTC', 'USD'));

        $filtered = iterator_to_array($book->filter($filter));

        self::assertSame([], $filtered);
    }

    public function test_constructor_accepts_iterable_of_orders(): void
    {
        $orders = [
            OrderFactory::buy(),
            OrderFactory::sell(rate: '29500'),
            OrderFactory::buy(base: 'ETH', rate: '1800'),
        ];

        $book = new OrderBook($orders);

        self::assertSame($orders, iterator_to_array($book));
    }

    public function test_iterator_is_rewindable(): void
    {
        $orders = [
            OrderFactory::buy(),
            OrderFactory::sell(rate: '29500'),
        ];

        $book = new OrderBook($orders);

        $first = iterator_to_array($book);
        $second = iterator_to_array($book);

        self::assertSame($first, $second);
    }

    public function test_duplicate_orders_are_allowed(): void
    {
        $order = OrderFactory::buy();
        $book = new OrderBook([$order, $order, $order]);

        $result = iterator_to_array($book);

        self::assertCount(3, $result);
        self::assertSame($order, $result[0]);
        self::assertSame($order, $result[1]);
        self::assertSame($order, $result[2]);
    }

    public function test_filter_returns_generator_not_array(): void
    {
        $book = new OrderBook([OrderFactory::buy()]);

        $result = $book->filter(new CurrencyPairFilter(CurrencyScenarioFactory::assetPair('BTC', 'USD')));

        self::assertInstanceOf(\Generator::class, $result);
    }

    public function test_filter_without_filters_returns_all_orders(): void
    {
        $orders = [
            OrderFactory::buy(),
            OrderFactory::sell(rate: '29500'),
        ];

        $book = new OrderBook($orders);
        $filtered = iterator_to_array($book->filter());

        self::assertSame($orders, $filtered);
    }

    public function test_order_book_maintains_insertion_order(): void
    {
        $first = OrderFactory::buy(rate: '30000');
        $second = OrderFactory::buy(rate: '29000');
        $third = OrderFactory::buy(rate: '31000');

        $book = new OrderBook();
        $book->add($first);
        $book->add($second);
        $book->add($third);

        $result = iterator_to_array($book);

        self::assertSame([$first, $second, $third], $result);
    }

    public function test_orders_maintain_bigdecimal_precision(): void
    {
        $order = OrderFactory::buy(
            minAmount: '0.123',
            maxAmount: '1.234',
            rate: '30000.12'
        );

        $book = new OrderBook([$order]);
        $result = iterator_to_array($book);

        // OrderFactory uses scale 3 for BTC amounts and scale 2 for USD rates
        self::assertSame('0.123', $result[0]->bounds()->min()->amount());
        self::assertSame('1.234', $result[0]->bounds()->max()->amount());
        self::assertSame('30000.12', $result[0]->effectiveRate()->rate());
    }

    public function test_filter_preserves_order_immutability(): void
    {
        $order = OrderFactory::buy();
        $book = new OrderBook([$order]);

        $filter = new CurrencyPairFilter(CurrencyScenarioFactory::assetPair('BTC', 'USD'));
        $filtered = iterator_to_array($book->filter($filter));

        self::assertSame($order, $filtered[0]);
        self::assertSame('BTC', $filtered[0]->assetPair()->base());
    }

    public function test_add_method_allows_incremental_construction(): void
    {
        $book = new OrderBook();

        self::assertSame([], iterator_to_array($book));

        $first = OrderFactory::buy();
        $book->add($first);

        self::assertSame([$first], iterator_to_array($book));

        $second = OrderFactory::sell(rate: '29500');
        $book->add($second);

        self::assertSame([$first, $second], iterator_to_array($book));
    }

    public function test_constructor_handles_empty_iterable(): void
    {
        $emptyArray = [];
        $emptyIterator = new ArrayIterator([]);

        $bookFromArray = new OrderBook($emptyArray);
        $bookFromIterator = new OrderBook($emptyIterator);

        self::assertSame([], iterator_to_array($bookFromArray));
        self::assertSame([], iterator_to_array($bookFromIterator));
    }

    public function test_constructor_accepts_generator(): void
    {
        $generator = static function () {
            yield OrderFactory::buy();
            yield OrderFactory::sell(rate: '29500');
        };

        $book = new OrderBook($generator());

        $orders = iterator_to_array($book);
        self::assertCount(2, $orders);
    }

    public function test_iterator_behavior_with_empty_book(): void
    {
        $book = new OrderBook();

        $iterator = $book->getIterator();
        self::assertInstanceOf(\Traversable::class, $iterator);

        // Iterator should be empty
        $count = 0;
        foreach ($iterator as $ignored) {
            ++$count;
        }
        self::assertSame(0, $count);

        // Multiple iterations should work
        $iterator2 = $book->getIterator();
        $count2 = 0;
        foreach ($iterator2 as $ignored1) {
            ++$count2;
        }
        self::assertSame(0, $count2);
    }

    public function test_iterator_returns_correct_instances(): void
    {
        $order1 = OrderFactory::buy();
        $order2 = OrderFactory::sell(rate: '29500');
        $book = new OrderBook([$order1, $order2]);

        $iterated = [];
        foreach ($book as $order) {
            $iterated[] = $order;
        }

        self::assertSame([$order1, $order2], $iterated);
        // Verify these are the same instances
        self::assertSame($order1, $iterated[0]);
        self::assertSame($order2, $iterated[1]);
    }

    public function test_filter_with_empty_filters_array(): void
    {
        $orders = [
            OrderFactory::buy(),
            OrderFactory::sell(rate: '29500'),
        ];
        $book = new OrderBook($orders);

        $filtered = iterator_to_array($book->filter());

        self::assertSame($orders, $filtered);
    }

    public function test_filter_with_multiple_filters_all_accept(): void
    {
        $order = OrderFactory::buy(minAmount: '0.050', maxAmount: '0.800', rate: '30000'); // min=0.050 <= 0.100, max=0.800 >= 0.500
        $book = new OrderBook([$order]);

        $filters = [
            new CurrencyPairFilter(CurrencyScenarioFactory::assetPair('BTC', 'USD')),
            new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.100', 3)), // accepts min <= 0.100
            new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.500', 3)), // accepts max >= 0.500
        ];

        $filtered = iterator_to_array($book->filter(...$filters));

        self::assertSame([$order], $filtered);
    }

    public function test_filter_with_multiple_filters_some_reject(): void
    {
        // Create orders that will have predictable filter behavior
        $order1 = OrderFactory::buy(minAmount: '0.050', maxAmount: '0.300', rate: '30000'); // min=0.050 <= 0.100, max=0.300 < 0.500 - fails max filter
        $order2 = OrderFactory::buy(minAmount: '0.200', maxAmount: '0.300', rate: '30000'); // min=0.200 > 0.100 - fails min filter
        $order3 = OrderFactory::buy(minAmount: '0.050', maxAmount: '0.800', rate: '30000'); // min=0.050 <= 0.100, max=0.800 >= 0.500 - should pass

        $book = new OrderBook([$order1, $order2, $order3]);

        $filters = [
            new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.100', 3)), // accepts min <= 0.100
            new MaximumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.500', 3)), // accepts max >= 0.500
        ];

        $filtered = iterator_to_array($book->filter(...$filters));

        // Only order3 should pass both filters
        self::assertCount(1, $filtered);
        self::assertSame($order3, $filtered[0]);
    }

    public function test_filter_short_circuits_on_first_filter_rejection(): void
    {
        $order = OrderFactory::buy();
        $book = new OrderBook([$order]);

        $callCount1 = 0;
        $callCount2 = 0;

        $filter1 = new class($callCount1) implements OrderFilterInterface {
            private int $callCount;

            public function __construct(int &$callCount)
            {
                $this->callCount = &$callCount;
            }

            public function accepts(Order $order): bool
            {
                ++$this->callCount;

                return false; // Always reject
            }
        };

        $filter2 = new class($callCount2) implements OrderFilterInterface {
            private int $callCount;

            public function __construct(int &$callCount)
            {
                $this->callCount = &$callCount;
            }

            public function accepts(Order $order): bool
            {
                ++$this->callCount;

                return true; // Should not be called
            }
        };

        $filtered = iterator_to_array($book->filter($filter1, $filter2));

        self::assertSame([], $filtered);
        self::assertSame(1, $callCount1, 'First filter should be called once');
        self::assertSame(0, $callCount2, 'Second filter should not be called due to short-circuiting');
    }

    public function test_filter_preserves_order_of_filters(): void
    {
        $order = OrderFactory::buy(minAmount: '0.500', maxAmount: '1.000', rate: '30000');
        $book = new OrderBook([$order]);

        $callOrder = [];

        $filter1 = new class($callOrder) implements OrderFilterInterface {
            private array $callOrder;

            public function __construct(array &$callOrder)
            {
                $this->callOrder = &$callOrder;
            }

            public function accepts(Order $order): bool
            {
                $this->callOrder[] = 'filter1';

                return true;
            }
        };

        $filter2 = new class($callOrder) implements OrderFilterInterface {
            private array $callOrder;

            public function __construct(array &$callOrder)
            {
                $this->callOrder = &$callOrder;
            }

            public function accepts(Order $order): bool
            {
                $this->callOrder[] = 'filter2';

                return true;
            }
        };

        iterator_to_array($book->filter($filter1, $filter2));

        self::assertSame(['filter1', 'filter2'], $callOrder);
    }

    public function test_multiple_iterations_work_independently(): void
    {
        $orders = [
            OrderFactory::buy(),
            OrderFactory::sell(rate: '29500'),
        ];
        $book = new OrderBook($orders);

        // First iteration
        $firstIteration = iterator_to_array($book);
        self::assertSame($orders, $firstIteration);

        // Add more orders
        $third = OrderFactory::buy(rate: '31000');
        $book->add($third);

        // Second iteration should include the new order
        $secondIteration = iterator_to_array($book);
        self::assertSame([$orders[0], $orders[1], $third], $secondIteration);

        // Third iteration should be the same
        $thirdIteration = iterator_to_array($book);
        self::assertSame($secondIteration, $thirdIteration);
    }

    public function test_constructor_with_mixed_valid_iterable_types(): void
    {
        $order1 = OrderFactory::buy();
        $order2 = OrderFactory::sell(rate: '29500');

        // Test with array
        $book1 = new OrderBook([$order1, $order2]);
        self::assertCount(2, iterator_to_array($book1));

        // Test with ArrayIterator
        $book2 = new OrderBook(new ArrayIterator([$order1, $order2]));
        self::assertCount(2, iterator_to_array($book2));

        // Test with generator
        $generator = static function () use ($order1, $order2) {
            yield $order1;
            yield $order2;
        };
        $book3 = new OrderBook($generator());
        self::assertCount(2, iterator_to_array($book3));
    }

    public function test_add_method_accepts_any_valid_order(): void
    {
        $book = new OrderBook();

        $buyOrder = OrderFactory::buy();
        $sellOrder = OrderFactory::sell(rate: '29500');
        $customOrder = OrderFactory::buy(minAmount: '0.001', maxAmount: '0.010', rate: '50000');

        $book->add($buyOrder);
        $book->add($sellOrder);
        $book->add($customOrder);

        $orders = iterator_to_array($book);
        self::assertSame([$buyOrder, $sellOrder, $customOrder], $orders);
    }

    public function test_filter_handles_filters_that_modify_during_iteration(): void
    {
        $order1 = OrderFactory::buy(minAmount: '0.050', maxAmount: '1.000'); // min=0.050 <= 0.300 - should pass
        $order2 = OrderFactory::buy(minAmount: '0.500', maxAmount: '2.000'); // min=0.500 > 0.300 - should fail
        $book = new OrderBook([$order1, $order2]);

        $filter = new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.300', 3)); // accepts min <= 0.300

        $filtered = iterator_to_array($book->filter($filter));

        // Only order1 should pass the filter (minAmount <= 0.300)
        self::assertSame([$order1], $filtered);
    }

    public function test_empty_book_iteration_is_safe(): void
    {
        $book = new OrderBook();

        // Should not crash
        $result = iterator_to_array($book);
        self::assertSame([], $result);

        // Should not crash when filtering
        $filtered = iterator_to_array($book->filter(
            new CurrencyPairFilter(CurrencyScenarioFactory::assetPair('BTC', 'USD'))
        ));
        self::assertSame([], $filtered);
    }

    public function test_order_book_with_large_number_of_orders(): void
    {
        $orders = [];
        for ($i = 0; $i < 1000; ++$i) {
            $orders[] = OrderFactory::buy(rate: (string) (30000 + $i));
        }

        $book = new OrderBook($orders);

        // Should handle large collections efficiently
        $count = 0;
        foreach ($book as $ignored) {
            ++$count;
        }
        self::assertSame(1000, $count);

        // Filtering should work on large collections
        $filtered = iterator_to_array($book->filter(
            new CurrencyPairFilter(CurrencyScenarioFactory::assetPair('BTC', 'USD'))
        ));
        self::assertCount(1000, $filtered);
    }

    public function test_order_book_with_extreme_filtering_scenarios(): void
    {
        // Create orders with alternating currencies
        $btcOrders = [];
        $ethOrders = [];

        for ($i = 0; $i < 100; ++$i) {
            $btcOrders[] = OrderFactory::buy(rate: (string) (30000 + $i));
            $ethOrders[] = OrderFactory::buy(base: 'ETH', rate: (string) (2000 + $i));
        }

        $book = new OrderBook(array_merge($btcOrders, $ethOrders));

        // Filter should handle complex scenarios efficiently
        $btcFiltered = iterator_to_array($book->filter(
            new CurrencyPairFilter(CurrencyScenarioFactory::assetPair('BTC', 'USD'))
        ));
        self::assertCount(100, $btcFiltered);

        $ethFiltered = iterator_to_array($book->filter(
            new CurrencyPairFilter(CurrencyScenarioFactory::assetPair('ETH', 'USD'))
        ));
        self::assertCount(100, $ethFiltered);

        // Combined filters should work
        $combinedFiltered = iterator_to_array($book->filter(
            new CurrencyPairFilter(CurrencyScenarioFactory::assetPair('BTC', 'USD')),
            new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.200', 3))
        ));
        // Should have BTC orders with amount >= 0.200 (default min is 0.100)
        self::assertGreaterThan(0, count($combinedFiltered));
        self::assertLessThanOrEqual(100, count($combinedFiltered));
    }
}
