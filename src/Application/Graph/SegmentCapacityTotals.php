<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Graph;

use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

/**
 * Aggregates mandatory and maximum segment capacities for a specific measure.
 *
 * @internal
 */
final class SegmentCapacityTotals
{
    public function __construct(
        private readonly Money $mandatory,
        private readonly Money $maximum,
    ) {
        if ($mandatory->currency() !== $maximum->currency()) {
            throw new InvalidInput('Segment capacity totals must share the same currency.');
        }
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
