<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Domain\Order;

use ArrayIterator;
use Generator;
use IteratorAggregate;
use SomeWork\P2PPathFinder\Domain\Order\Filter\OrderFilterInterface;
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
     * @param iterable<Order> $orders
     *
     * @api
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
     * @return Traversable<int, Order>
     *
     * @api
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->orders);
    }

    /**
     * @return Generator<int, Order>
     *
     * @api
     *
     * @example
     * ```php
     * use SomeWork\P2PPathFinder\Application\Order\Filter\MinimumAmountFilter;
     * use SomeWork\P2PPathFinder\Application\Order\Filter\MaximumAmountFilter;
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
