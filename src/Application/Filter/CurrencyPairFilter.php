<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Filter;

use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\ValueObject\AssetPair;

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
