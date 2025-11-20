<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder;

use SomeWork\P2PPathFinder\Application\PathFinder\Result\Heap\CandidateHeapEntry;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Heap\CandidatePriorityQueue;

/**
 * @internal
 */
final class CandidateResultHeap
{
    private CandidatePriorityQueue $heap;

    public function __construct(private readonly int $scale)
    {
        $this->heap = new CandidatePriorityQueue($this->scale);
    }

    public function __clone()
    {
        $this->heap = clone $this->heap;
    }

    public function insert(CandidateHeapEntry $entry): true
    {
        $this->heap->insert($entry, $entry->priority());

        return true;
    }

    public function push(CandidateHeapEntry $entry): void
    {
        $this->heap->insert($entry, $entry->priority());
    }

    public function extract(): CandidateHeapEntry
    {
        /** @var CandidateHeapEntry $entry */
        $entry = $this->heap->extract();

        return $entry;
    }

    public function isEmpty(): bool
    {
        return 0 === $this->heap->count();
    }

    public function count(): int
    {
        return $this->heap->count();
    }
}
