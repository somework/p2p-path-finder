<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Domain\Order;

enum OrderSide: string
{
    case BUY = 'buy';
    case SELL = 'sell';
}
