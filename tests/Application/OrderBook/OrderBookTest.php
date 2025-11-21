<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\OrderBook;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Filter\CurrencyPairFilter;
use SomeWork\P2PPathFinder\Application\Filter\MaximumAmountFilter;
use SomeWork\P2PPathFinder\Application\Filter\MinimumAmountFilter;
use SomeWork\P2PPathFinder\Application\Filter\OrderFilterInterface;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Tests\Fixture\CurrencyScenarioFactory;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

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
            /**
             * @var int
             */
            private $counter;

            public function __construct(int &$counter)
            {
                $this->counter = &$counter;
            }

            public function accepts(\SomeWork\P2PPathFinder\Domain\Order\Order $order): bool
            {
                ++$this->counter;

                return false;
            }
        };

        $fallthroughInvocations = 0;
        $fallthroughFilter = new class($fallthroughInvocations) implements OrderFilterInterface {
            /**
             * @var int
             */
            private $counter;

            public function __construct(int &$counter)
            {
                $this->counter = &$counter;
            }

            public function accepts(\SomeWork\P2PPathFinder\Domain\Order\Order $order): bool
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
        $filter = new MinimumAmountFilter(CurrencyScenarioFactory::money('BTC', '0.500', 3));
        $filtered = iterator_to_array($book->filter($filter));

        self::assertCount(1000, $filtered);
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
}
