<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathFinder\CandidateResultHeap;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Heap\CandidateHeapEntry;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Heap\CandidatePriority;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\InsertionOrderCounter;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchBootstrap;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchQueueEntry;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchState;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchStatePriority;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchStateRecord;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchStateRegistry;
use SomeWork\P2PPathFinder\Application\PathFinder\SearchStateQueue;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\CandidatePath;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\PathEdgeSequence;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;

final class SearchBootstrapTest extends TestCase
{
    private const SCALE = 18;

    public function test_clone_preserves_component_state(): void
    {
        $queue = new SearchStateQueue(self::SCALE);
        $results = new CandidateResultHeap(self::SCALE);
        $registry = SearchStateRegistry::withInitial('SRC', new SearchStateRecord('1', 0, 'sig:src'));
        $insertionOrder = new InsertionOrderCounter();
        $resultInsertionOrder = new InsertionOrderCounter(3);

        $state = SearchState::bootstrap('SRC', BcMath::normalize('1', self::SCALE), null, null);
        $queue->push(new SearchQueueEntry(
            $state,
            new SearchStatePriority($state->cost(), $state->hops(), '', $insertionOrder->next()),
        ));

        $candidate = CandidatePath::from(
            BcMath::normalize('0.5', self::SCALE),
            BcMath::normalize('1.5', self::SCALE),
            0,
            PathEdgeSequence::empty(),
            null,
        );
        $results->push(new CandidateHeapEntry($candidate, new CandidatePriority($candidate->cost(), 1, 'sig:candidate', $resultInsertionOrder->next())));

        $bootstrap = new SearchBootstrap($queue, $results, $registry, $insertionOrder, $resultInsertionOrder, 1);
        $clone = clone $bootstrap;

        self::assertNotSame($bootstrap->queue(), $clone->queue());
        self::assertNotSame($bootstrap->results(), $clone->results());
        self::assertNotSame($bootstrap->registry(), $clone->registry());
        self::assertNotSame($bootstrap->insertionOrder(), $clone->insertionOrder());
        self::assertNotSame($bootstrap->resultInsertionOrder(), $clone->resultInsertionOrder());

        self::assertFalse($clone->queue()->isEmpty());
        self::assertSame('SRC', $clone->queue()->extract()->node());
        self::assertFalse($bootstrap->queue()->isEmpty());

        self::assertSame(1, $clone->insertionOrder()->next());
        self::assertSame(1, $bootstrap->insertionOrder()->next());

        self::assertSame(4, $clone->resultInsertionOrder()->next());
        self::assertSame(4, $bootstrap->resultInsertionOrder()->next());

        $clone->registry()->register('DST', new SearchStateRecord('2', 1, 'sig:dst'), self::SCALE);
        self::assertFalse($bootstrap->registry()->hasSignature('DST', 'sig:dst'));

        self::assertSame(1, $bootstrap->visitedStates());
        self::assertSame(1, $clone->visitedStates());
    }

    public function test_requires_positive_visited_state_counter(): void
    {
        $queue = new SearchStateQueue(self::SCALE);
        $results = new CandidateResultHeap(self::SCALE);
        $registry = SearchStateRegistry::withInitial('SRC', new SearchStateRecord('1', 0, 'sig:src'));
        $insertionOrder = new InsertionOrderCounter();
        $resultInsertionOrder = new InsertionOrderCounter();

        $state = SearchState::bootstrap('SRC', BcMath::normalize('1', self::SCALE), null, null);
        $queue->push(new SearchQueueEntry(
            $state,
            new SearchStatePriority($state->cost(), $state->hops(), '', $insertionOrder->next()),
        ));

        $this->expectException(InvalidArgumentException::class);
        new SearchBootstrap($queue, $results, $registry, $insertionOrder, $resultInsertionOrder, 0);
    }

    public function test_requires_queue_with_initial_state(): void
    {
        $queue = new SearchStateQueue(self::SCALE);
        $results = new CandidateResultHeap(self::SCALE);
        $registry = SearchStateRegistry::withInitial('SRC', new SearchStateRecord('1', 0, 'sig:src'));
        $insertionOrder = new InsertionOrderCounter();
        $resultInsertionOrder = new InsertionOrderCounter();

        $this->expectException(InvalidArgumentException::class);

        new SearchBootstrap($queue, $results, $registry, $insertionOrder, $resultInsertionOrder, 1);
    }

    public function test_requires_registry_with_initial_state(): void
    {
        $queue = new SearchStateQueue(self::SCALE);
        $results = new CandidateResultHeap(self::SCALE);
        $insertionOrder = new InsertionOrderCounter();
        $resultInsertionOrder = new InsertionOrderCounter();

        $state = SearchState::bootstrap('SRC', BcMath::normalize('1', self::SCALE), null, null);
        $queue->push(new SearchQueueEntry(
            $state,
            new SearchStatePriority($state->cost(), $state->hops(), '', $insertionOrder->next()),
        ));

        $this->expectException(InvalidArgumentException::class);

        new SearchBootstrap(
            $queue,
            $results,
            SearchStateRegistry::empty(),
            $insertionOrder,
            $resultInsertionOrder,
            1,
        );
    }
}
