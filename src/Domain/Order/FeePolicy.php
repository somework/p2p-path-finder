<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Domain\Order;

use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

interface FeePolicy
{
    public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): Money;
}
