<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder;

use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchQueueEntry;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchState;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchStatePriority;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchStatePriorityQueue;

/**
 * @internal
 */
final class SearchStateQueue
{
    private SearchStatePriorityQueue $queue;

    /**
     * @phpstan-param positive-int $scale
     *
     * @psalm-param positive-int $scale
     */
    public function __construct(private readonly int $scale)
    {
        $this->queue = new SearchStatePriorityQueue($this->scale);
    }

    public function __clone()
    {
        $this->queue = clone $this->queue;
    }

    public function push(SearchQueueEntry $entry): void
    {
        $this->queue->insert($entry, $entry->priority());
    }

    public function extract(): SearchState
    {
        /** @var SearchQueueEntry $entry */
        $entry = $this->queue->extract();

        return $entry->state();
    }

    public function isEmpty(): bool
    {
        return 0 === $this->queue->count();
    }

    public function count(): int
    {
        return $this->queue->count();
    }

    /**
     * Compares two priorities using the queue's scale.
     *
     * This method provides a test seam for verifying priority comparison logic
     * without exposing the internal SearchStatePriorityQueue implementation.
     * The comparison logic intentionally mirrors SearchStatePriorityQueue::compare().
     *
     * @return int Negative if priority1 < priority2, positive if priority1 > priority2, zero if equal
     */
    public function compare(SearchStatePriority $priority1, SearchStatePriority $priority2): int
    {
        return $priority1->compare($priority2, $this->scale);
    }
}
