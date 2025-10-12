<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\OrderBook;

use ArrayIterator;
use Generator;
use IteratorAggregate;
use SomeWork\P2PPathFinder\Application\Filter\OrderFilterInterface;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use Traversable;

/**
 * @implements IteratorAggregate<int, Order>
 */
final class OrderBook implements IteratorAggregate
{
    /** @var list<Order> */
    private array $orders = [];

    /**
     * @param iterable<Order> $orders
     */
    public function __construct(iterable $orders = [])
    {
        foreach ($orders as $order) {
            $this->add($order);
        }
    }

    public function add(Order $order): void
    {
        $this->orders[] = $order;
    }

    /**
     * @return Traversable<int, Order>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->orders);
    }

    /**
     * @return Generator<int, Order>
     */
    public function filter(OrderFilterInterface ...$filters): Generator
    {
        foreach ($this->orders as $order) {
            $accepted = true;

            foreach ($filters as $filter) {
                if (!$filter->accepts($order)) {
                    $accepted = false;

                    break;
                }
            }

            if ($accepted) {
                yield $order;
            }
        }
    }
}
