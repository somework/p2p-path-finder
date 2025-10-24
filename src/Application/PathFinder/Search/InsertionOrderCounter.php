<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Search;

final class InsertionOrderCounter
{
    public function __construct(private int $value = 0)
    {
        if ($this->value < 0) {
            throw new \InvalidArgumentException('Insertion counters must start at a non-negative value.');
        }
    }

    public function next(): int
    {
        return $this->value++;
    }
}
