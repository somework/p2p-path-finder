<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Filter;

use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

/**
 * Accepts orders whose minimum fill amount does not exceed the configured threshold.
 */
final class MinimumAmountFilter implements OrderFilterInterface
{
    public function __construct(private readonly Money $amount)
    {
    }

    #[\Override]
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
