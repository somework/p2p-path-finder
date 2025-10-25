<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Search;

use InvalidArgumentException;
use SomeWork\P2PPathFinder\Application\PathFinder\CandidateResultHeap;
use SomeWork\P2PPathFinder\Application\PathFinder\SearchStateQueue;

final class SearchBootstrap
{
    private SearchStateQueue $queue;

    private CandidateResultHeap $results;

    private SearchStateRegistry $registry;

    private InsertionOrderCounter $insertionOrder;

    private InsertionOrderCounter $resultInsertionOrder;

    public function __construct(
        SearchStateQueue $queue,
        CandidateResultHeap $results,
        SearchStateRegistry $registry,
        InsertionOrderCounter $insertionOrder,
        InsertionOrderCounter $resultInsertionOrder,
        private readonly int $visitedStates,
    ) {
        if ($queue->isEmpty()) {
            throw new InvalidArgumentException('Search queue must contain the initial state.');
        }

        if ($registry->isEmpty()) {
            throw new InvalidArgumentException('Search registry must contain the initial state.');
        }

        if ($this->visitedStates < 1) {
            throw new InvalidArgumentException('Visited state counter must be at least one.');
        }

        $this->queue = $queue;
        $this->results = $results;
        $this->registry = $registry;
        $this->insertionOrder = $insertionOrder;
        $this->resultInsertionOrder = $resultInsertionOrder;
    }

    public function queue(): SearchStateQueue
    {
        return $this->queue;
    }

    public function results(): CandidateResultHeap
    {
        return $this->results;
    }

    public function registry(): SearchStateRegistry
    {
        return $this->registry;
    }

    public function insertionOrder(): InsertionOrderCounter
    {
        return $this->insertionOrder;
    }

    public function resultInsertionOrder(): InsertionOrderCounter
    {
        return $this->resultInsertionOrder;
    }

    public function visitedStates(): int
    {
        return $this->visitedStates;
    }

    public function __clone()
    {
        $this->queue = clone $this->queue;
        $this->results = clone $this->results;
        $this->registry = clone $this->registry;
        $this->insertionOrder = clone $this->insertionOrder;
        $this->resultInsertionOrder = clone $this->resultInsertionOrder;
    }
}
