<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph;

use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

/**
 * Represents the minimum and maximum capacity for a given measurement on an edge.
 *
 * @internal
 */
final class EdgeCapacity
{
    public function __construct(
        private readonly Money $min,
        private readonly Money $max,
    ) {
        if ($min->currency() !== $max->currency()) {
            throw new InvalidInput('Edge capacity bounds must share the same currency.');
        }

        if ($min->greaterThan($max)) {
            throw new InvalidInput('Edge capacity minimum cannot exceed maximum.');
        }
    }

    public function min(): Money
    {
        return $this->min;
    }

    public function max(): Money
    {
        return $this->max;
    }
}
