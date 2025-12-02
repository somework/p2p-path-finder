<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph;

use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

use function sprintf;

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

        // Enforce mandatory <= maximum invariant (prevents negative headroom)
        if ($mandatory->greaterThan($maximum)) {
            throw new InvalidInput(sprintf('Segment capacity mandatory amount (%s %s) cannot exceed maximum (%s %s).', $mandatory->currency(), $mandatory->amount(), $maximum->currency(), $maximum->amount()));
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

    /**
     * Returns the optional capacity headroom (maximum - mandatory).
     *
     * This represents the additional capacity beyond mandatory minimums that
     * MAY be filled. When headroom is zero, only mandatory segments are relevant.
     *
     * @return Money The difference between maximum and mandatory capacity
     */
    public function optionalHeadroom(): Money
    {
        return $this->maximum->subtract($this->mandatory, $this->scale());
    }
}
