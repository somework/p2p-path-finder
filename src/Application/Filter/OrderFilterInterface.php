<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Filter;

use SomeWork\P2PPathFinder\Domain\Order\Order;

interface OrderFilterInterface
{
    public function accepts(Order $order): bool;
}
