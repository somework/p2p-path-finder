<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Search;

use InvalidArgumentException;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;

final class SearchStateRecord
{
    /**
     * @param numeric-string $cost
     */
    public function __construct(
        private readonly string $cost,
        private readonly int $hops,
        private readonly string $signature
    ) {
        if ($this->hops < 0) {
            throw new InvalidArgumentException('Recorded hop counts must be non-negative.');
        }

        if ('' === $this->signature) {
            throw new InvalidArgumentException('Search state signatures cannot be empty.');
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

    public function signature(): string
    {
        return $this->signature;
    }

    public function dominates(self $other, int $scale): bool
    {
        return BcMath::comp($this->cost, $other->cost(), $scale) <= 0 && $this->hops <= $other->hops();
    }
}
