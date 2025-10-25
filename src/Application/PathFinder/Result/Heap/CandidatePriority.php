<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Result\Heap;

use InvalidArgumentException;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;

final class CandidatePriority
{
    /**
     * @param numeric-string $cost
     */
    public function __construct(
        private readonly string $cost,
        private readonly int $hops,
        private readonly string $routeSignature,
        private readonly int $order
    ) {
        if ($this->hops < 0) {
            throw new InvalidArgumentException('Candidate priorities require a non-negative hop count.');
        }

        if ($this->order < 0) {
            throw new InvalidArgumentException('Candidate priorities require a non-negative insertion order.');
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

    public function routeSignature(): string
    {
        return $this->routeSignature;
    }

    public function order(): int
    {
        return $this->order;
    }

    public function compare(self $other, int $scale): int
    {
        $comparison = BcMath::comp($this->cost, $other->cost(), $scale);
        if (0 !== $comparison) {
            return $comparison;
        }

        $comparison = $this->hops <=> $other->hops();
        if (0 !== $comparison) {
            return $comparison;
        }

        $comparison = $this->routeSignature <=> $other->routeSignature();
        if (0 !== $comparison) {
            return $comparison;
        }

        return $this->order <=> $other->order();
    }
}
