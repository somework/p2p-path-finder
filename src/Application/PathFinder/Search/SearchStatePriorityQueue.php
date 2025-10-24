<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Search;

use InvalidArgumentException;
use SplPriorityQueue;

/**
 * @internal
 *
 * @extends SplPriorityQueue<SearchStatePriority, SearchQueueEntry>
 */
final class SearchStatePriorityQueue extends SplPriorityQueue
{
    public function __construct(private readonly int $scale)
    {
        $this->setExtractFlags(self::EXTR_DATA);
    }

    /**
     * @psalm-suppress ImplementedParamTypeMismatch
     *
     * @param SearchStatePriority $priority1
     * @param SearchStatePriority $priority2
     */
    public function compare(mixed $priority1, mixed $priority2): int
    {
        if (!$priority1 instanceof SearchStatePriority) {
            throw new InvalidArgumentException('Search state priority queue expects search state priorities.');
        }

        if (!$priority2 instanceof SearchStatePriority) {
            throw new InvalidArgumentException('Search state priority queue expects search state priorities.');
        }

        return $priority1->compare($priority2, $this->scale);
    }
}
