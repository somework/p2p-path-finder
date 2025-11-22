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
 * @api
 *
 * @implements IteratorAggregate<int, Order>
 */
final class OrderBook implements IteratorAggregate
{
    /** @var list<Order> */
    private array $orders = [];

    /**
     * @api
     *
     * @param iterable<Order> $orders
     */
    public function __construct(iterable $orders = [])
    {
        foreach ($orders as $order) {
            $this->add($order);
        }
    }

    /**
     * Appends an order to the in-memory order book.
     *
     * @api
     */
    public function add(Order $order): void
    {
        $this->orders[] = $order;
    }

    /**
     * @api
     *
     * @return Traversable<int, Order>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->orders);
    }

    /**
     * @api
     *
     * @return Generator<int, Order>
     *
     * @example
     * ```php
     * use SomeWork\P2PPathFinder\Application\Filter\MinimumAmountFilter;
     * use SomeWork\P2PPathFinder\Application\Filter\MaximumAmountFilter;
     *
     * // Apply multiple filters to reduce order book size
     * $filtered = $orderBook->filter(
     *     new MinimumAmountFilter(Money::fromString('BTC', '0.01', 8)),
     *     new MaximumAmountFilter(Money::fromString('BTC', '10.0', 8))
     * );
     *
     * // Filtered orders can be iterated or converted to array
     * $filteredOrders = iterator_to_array($filtered);
     * ```
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
