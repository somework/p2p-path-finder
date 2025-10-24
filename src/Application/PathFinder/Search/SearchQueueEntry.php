<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Search;

final class SearchQueueEntry
{
    public function __construct(private readonly SearchState $state, private readonly SearchStatePriority $priority)
    {
    }

    public function state(): SearchState
    {
        return $this->state;
    }

    public function priority(): SearchStatePriority
    {
        return $this->priority;
    }
}
