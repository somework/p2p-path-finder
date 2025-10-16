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
}
