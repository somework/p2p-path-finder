<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Domain\Order;

/**
 * Order side enum (BUY or SELL).
 *
 * @api
 */
enum OrderSide: string
{
    case BUY = 'buy';
    case SELL = 'sell';
}
