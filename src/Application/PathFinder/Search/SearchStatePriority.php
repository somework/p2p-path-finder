<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Search;

use InvalidArgumentException;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;

final class SearchStatePriority
{
    /**
     * @param numeric-string $cost
     *
     * @phpstan-param int        $hops
     * @phpstan-param int        $order
     *
     * @psalm-param int<0, max>  $hops
     * @psalm-param int<0, max>  $order
     */
    public function __construct(
        private readonly string $cost,
        private readonly int $hops,
        private readonly string $routeSignature,
        private readonly int $order,
    ) {
        /*
         * @psalm-suppress DocblockTypeContradiction
         */
        if ($this->hops < 0) {
            throw new InvalidArgumentException('Queue priorities require a non-negative hop count.');
        }

        /*
         * @psalm-suppress DocblockTypeContradiction
         */
        if ($this->order < 0) {
            throw new InvalidArgumentException('Queue priorities require a non-negative insertion order.');
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

    /**
     * @phpstan-param positive-int $scale
     *
     * @psalm-param positive-int $scale
     */
    public function compare(self $other, int $scale): int
    {
        $comparison = BcMath::comp($this->cost, $other->cost(), $scale);
        if (0 !== $comparison) {
            return -$comparison;
        }

        $comparison = $this->hops <=> $other->hops();
        if (0 !== $comparison) {
            return -$comparison;
        }

        $comparison = $this->routeSignature <=> $other->routeSignature();
        if (0 !== $comparison) {
            return -$comparison;
        }

        return $other->order() <=> $this->order;
    }
}
