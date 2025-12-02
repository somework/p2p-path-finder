<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Engine\State;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * @internal
 */
final class SearchStateRecord
{
    public function __construct(
        private readonly BigDecimal $cost,
        private readonly int $hops,
        private readonly SearchStateSignature $signature
    ) {
        if ($this->hops < 0) {
            throw new InvalidArgumentException('Recorded hop counts must be non-negative.');
        }
    }

    /**
     * @return numeric-string
     *
     * @phpstan-return numeric-string
     */
    public function cost(): string
    {
        /** @var numeric-string $value */
        $value = $this->cost->__toString();

        return $value;
    }

    public function decimal(): BigDecimal
    {
        return $this->cost;
    }

    public function hops(): int
    {
        return $this->hops;
    }

    public function signature(): SearchStateSignature
    {
        return $this->signature;
    }

    /**
     * Determines if this record dominates another.
     *
     * Record A dominates Record B if:
     * - A.cost ≤ B.cost AND
     * - A.hops ≤ B.hops
     *
     * Dominated records can be safely pruned as they represent strictly worse paths.
     *
     * @param self $other The record to compare against
     * @param int  $scale The decimal scale for cost comparison
     *
     * @return bool True if this record dominates the other
     */
    public function dominates(self $other, int $scale): bool
    {
        $comparison = $this->scaleDecimal($this->cost, $scale)
            ->compareTo($this->scaleDecimal($other->cost, $scale));

        return $comparison <= 0 && $this->hops <= $other->hops();
    }

    private function scaleDecimal(BigDecimal $decimal, int $scale): BigDecimal
    {
        if ($scale < 0) {
            throw new InvalidArgumentException('Comparison scale must be non-negative.');
        }

        return $decimal->toScale($scale, RoundingMode::HALF_UP);
    }
}
