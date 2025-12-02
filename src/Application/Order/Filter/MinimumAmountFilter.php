<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Order\Filter;

use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\Filter\OrderFilterInterface;
use SomeWork\P2PPathFinder\Domain\Order\Order;

/**
 * Accepts orders whose minimum fill amount does not exceed the configured threshold.
 */
final class MinimumAmountFilter implements OrderFilterInterface
{
    public function __construct(private readonly Money $amount)
    {
    }

    public function accepts(Order $order): bool
    {
        $minimum = $order->bounds()->min();
        if ($minimum->currency() !== $this->amount->currency()) {
            return false;
        }

        $amount = $this->amount->withScale($minimum->scale());

        return !$minimum->greaterThan($amount);
    }
}
