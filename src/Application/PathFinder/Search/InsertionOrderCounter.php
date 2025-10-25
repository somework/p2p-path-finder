<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Search;

final class InsertionOrderCounter
{
    public function __construct(
        /**
         * @var int<0, max>
         */
        private int $value = 0
    ) {
        /* @phpstan-ignore-next-line */
        /** @psalm-suppress DocblockTypeContradiction */
        if ($this->value < 0) {
            throw new \InvalidArgumentException('Insertion counters must start at a non-negative value.');
        }
    }

    /**
     * @phpstan-return int<0, max>
     *
     * @psalm-return int<0, max>
     */
    public function next(): int
    {
        return $this->value++;
    }
}
