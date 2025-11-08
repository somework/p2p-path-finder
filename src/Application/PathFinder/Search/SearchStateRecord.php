<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Search;

use InvalidArgumentException;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;

/**
 * @internal
 */
final class SearchStateRecord
{
    /**
     * @param numeric-string $cost
     */
    public function __construct(
        private readonly string $cost,
        private readonly int $hops,
        private readonly SearchStateSignature $signature
    ) {
        if ($this->hops < 0) {
            throw new InvalidArgumentException('Recorded hop counts must be non-negative.');
        }

        BcMath::ensureNumeric($this->cost, $this->cost);
    }

    /**
     * @return numeric-string
     */
    public function cost(): string
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
        return BcMath::comp($this->cost, $other->cost(), $scale) <= 0 && $this->hops <= $other->hops();
    }
}
