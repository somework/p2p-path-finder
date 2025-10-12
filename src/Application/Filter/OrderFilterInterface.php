<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Filter;

use SomeWork\P2PPathFinder\Domain\Order\Order;

/**
 * Strategy describing whether an order can participate in a path search.
 */
interface OrderFilterInterface
{
    /**
     * Determines if the provided order satisfies the filter conditions.
     */
    public function accepts(Order $order): bool;
}
