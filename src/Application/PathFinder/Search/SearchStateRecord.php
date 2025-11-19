<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Search;

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
