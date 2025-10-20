<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Fixture;

use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;

final class BottleneckOrderBookFactory
{
    /**
     * Builds a deterministic order book where every hop has tight bounds around
     * a large mandatory minimum. The optional headroom is intentionally
     * constrained to stress capacity checks in the path finder.
     */
    public static function create(): OrderBook
    {
        $orders = [
            OrderFactory::sell('SRC', 'HUBA', '120.000', '122.000', '1.000', 3, 3),
            OrderFactory::sell('HUBA', 'HUBAA', '120.000', '122.000', '1.000', 3, 3),
            OrderFactory::sell('HUBAA', 'DST', '120.000', '122.000', '1.000', 3, 3),
            OrderFactory::sell('SRC', 'HUBB', '120.000', '121.500', '1.000', 3, 3),
            OrderFactory::sell('HUBB', 'HUBBA', '120.000', '121.500', '1.000', 3, 3),
            OrderFactory::sell('HUBBA', 'DST', '120.000', '121.500', '1.000', 3, 3),
        ];

        return new OrderBook($orders);
    }
}
