<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Search;

use InvalidArgumentException;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathCost;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\RouteSignature;

final class SearchStatePriority
{
    /**
     * @var int<0, max>
     */
    private readonly int $hops;

    /**
     * @var int<0, max>
     */
    private readonly int $order;

    public function __construct(
        private readonly PathCost $cost,
        int $hops,
        private readonly RouteSignature $routeSignature,
        int $order,
    ) {
        $this->hops = self::guardNonNegative($hops, 'Queue priorities require a non-negative hop count.');
        $this->order = self::guardNonNegative($order, 'Queue priorities require a non-negative insertion order.');
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

    /**
     * @phpstan-param positive-int $scale
     *
     * @psalm-param positive-int $scale
     */
    public function compare(self $other, int $scale): int
    {
        $comparison = $this->cost->compare($other->cost(), $scale);
        if (0 !== $comparison) {
            return -$comparison;
        }

        $comparison = $this->hops <=> $other->hops();
        if (0 !== $comparison) {
            return -$comparison;
        }

        $comparison = $this->routeSignature->compare($other->routeSignature());
        if (0 !== $comparison) {
            return -$comparison;
        }

        return $other->order() <=> $this->order;
    }

    /**
     * @phpstan-assert int<0, max> $value
     *
     * @psalm-assert int<0, max> $value
     *
     * @return int<0, max>
     */
    private static function guardNonNegative(int $value, string $message): int
    {
        if ($value < 0) {
            throw new InvalidArgumentException($message);
        }

        return $value;
    }
}
