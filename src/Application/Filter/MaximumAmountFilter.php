<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Filter;

use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

/**
 * Accepts orders whose maximum fill amount does not exceed the configured threshold.
 */
final class MaximumAmountFilter implements OrderFilterInterface
{
    public function __construct(private readonly Money $amount)
    {
    }

    public function accepts(Order $order): bool
    {
        $maximum = $order->bounds()->max();
        if ($maximum->currency() !== $this->amount->currency()) {
            return false;
        }

        $amount = $this->amount->withScale($maximum->scale());

        return !$maximum->lessThan($amount);
    }
}
