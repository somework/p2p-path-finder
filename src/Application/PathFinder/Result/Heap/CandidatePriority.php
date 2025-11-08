<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Result\Heap;

use InvalidArgumentException;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathCost;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\RouteSignature;

/**
 * @internal
 */
final class CandidatePriority
{
    public function __construct(
        private readonly PathCost $cost,
        private readonly int $hops,
        private readonly RouteSignature $routeSignature,
        private readonly int $order
    ) {
        if ($this->hops < 0) {
            throw new InvalidArgumentException('Candidate priorities require a non-negative hop count.');
        }

        if ($this->order < 0) {
            throw new InvalidArgumentException('Candidate priorities require a non-negative insertion order.');
        }
    }

    public function cost(): PathCost
    {
        return $this->cost;
    }

    public function hops(): int
    {
        return $this->hops;
    }

    public function routeSignature(): RouteSignature
    {
        return $this->routeSignature;
    }

    public function order(): int
    {
        return $this->order;
    }

    public function compare(self $other, int $scale): int
    {
        $comparison = $this->cost->compare($other->cost(), $scale);
        if (0 !== $comparison) {
            return $comparison;
        }

        $comparison = $this->hops <=> $other->hops();
        if (0 !== $comparison) {
            return $comparison;
        }

        $comparison = $this->routeSignature->compare($other->routeSignature());
        if (0 !== $comparison) {
            return $comparison;
        }

        return $this->order <=> $other->order();
    }
}
