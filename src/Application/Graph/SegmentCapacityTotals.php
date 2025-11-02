<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Graph;

use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

final class SegmentCapacityTotals
{
    public function __construct(
        private readonly Money $mandatory,
        private readonly Money $maximum,
    ) {
    }

    public function mandatory(): Money
    {
        return $this->mandatory;
    }

    public function maximum(): Money
    {
        return $this->maximum;
    }

    public function scale(): int
    {
        return $this->mandatory->scale();
    }

    public function optionalHeadroom(): Money
    {
        return $this->maximum->subtract($this->mandatory, $this->scale());
    }
}
