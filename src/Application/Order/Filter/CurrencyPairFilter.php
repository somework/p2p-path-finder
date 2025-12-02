<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Order\Filter;

use SomeWork\P2PPathFinder\Domain\Money\AssetPair;
use SomeWork\P2PPathFinder\Domain\Order\Filter\OrderFilterInterface;
use SomeWork\P2PPathFinder\Domain\Order\Order;

/**
 * Accepts orders that match an exact asset pair.
 */
final class CurrencyPairFilter implements OrderFilterInterface
{
    public function __construct(private readonly AssetPair $assetPair)
    {
    }

    public function accepts(Order $order): bool
    {
        $orderPair = $order->assetPair();

        return $orderPair->base() === $this->assetPair->base()
            && $orderPair->quote() === $this->assetPair->quote();
    }
}
