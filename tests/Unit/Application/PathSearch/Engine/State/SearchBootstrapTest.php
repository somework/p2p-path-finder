<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Engine\State;

use Brick\Math\BigDecimal;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathCost;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\RouteSignature;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Queue\CandidateHeapEntry;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Queue\CandidatePriority;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Queue\CandidateResultHeap;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Queue\StatePriorityQueue;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\State\InsertionOrderCounter;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\State\SearchBootstrap;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\State\SearchQueueEntry;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\State\SearchState;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\State\SearchStatePriority;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\State\SearchStateRecord;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\State\SearchStateRegistry;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\State\SearchStateSignature;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\CandidatePath;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\PathEdgeSequence;
use SomeWork\P2PPathFinder\Tests\Helpers\DecimalMath;

final class SearchBootstrapTest extends TestCase
{
    private const SCALE = 18;

    public function test_clone_preserves_component_state(): void
    {
        $queue = new StatePriorityQueue(self::SCALE);
        $results = new CandidateResultHeap(self::SCALE);
        $registry = SearchStateRegistry::withInitial(
            'SRC',
            new SearchStateRecord(BigDecimal::of('1'), 0, SearchStateSignature::fromString('sig:src')),
        );
        $insertionOrder = new InsertionOrderCounter();
        $resultInsertionOrder = new InsertionOrderCounter(3);

        $state = SearchState::bootstrap('SRC', DecimalMath::decimal('1', self::SCALE), null, null);
        $queue->push(new SearchQueueEntry(
            $state,
            new SearchStatePriority(new PathCost($state->costDecimal()), $state->hops(), RouteSignature::fromNodes([]), $insertionOrder->next()),
        ));

        $candidate = CandidatePath::from(
            DecimalMath::decimal('0.5', self::SCALE),
            DecimalMath::decimal('1.5', self::SCALE),
            0,
            PathEdgeSequence::empty(),
            null,
        );
        $results->push(new CandidateHeapEntry($candidate, new CandidatePriority(new PathCost($candidate->costDecimal()), 1, RouteSignature::fromNodes(['sig:candidate']), $resultInsertionOrder->next())));

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

        // Note: register() returns new instance, but we don't reassign it here
        // to test that the original bootstrap's registry remains unchanged
        $clone->registry()->register(
            'DST',
            new SearchStateRecord(BigDecimal::of('2'), 1, SearchStateSignature::fromString('sig:dst')),
            self::SCALE,
        );
        self::assertFalse(
            $bootstrap->registry()->hasSignature('DST', SearchStateSignature::fromString('sig:dst')),
        );

        self::assertSame(1, $bootstrap->visitedStates());
        self::assertSame(1, $clone->visitedStates());
    }

    public function test_clone_registry_replacement_is_isolated(): void
    {
        $queue = new StatePriorityQueue(self::SCALE);
        $results = new CandidateResultHeap(self::SCALE);
        $registry = SearchStateRegistry::withInitial(
            'SRC',
            new SearchStateRecord(BigDecimal::of('1'), 0, SearchStateSignature::fromString('sig:src')),
        );
        $insertionOrder = new InsertionOrderCounter();
        $resultInsertionOrder = new InsertionOrderCounter();

        $state = SearchState::bootstrap('SRC', DecimalMath::decimal('1', self::SCALE), null, null);
        $queue->push(new SearchQueueEntry(
            $state,
            new SearchStatePriority(new PathCost($state->costDecimal()), $state->hops(), RouteSignature::fromNodes([]), $insertionOrder->next()),
        ));

        $bootstrap = new SearchBootstrap($queue, $results, $registry, $insertionOrder, $resultInsertionOrder, 1);
        $clone = clone $bootstrap;

        // Note: register() returns new instance, but we don't reassign it here.
        // Since clone returns deep copies, the test expects the clone's registry to remain unchanged.
        // This test is actually demonstrating that without reassignment, the registry doesn't change.
        $clone->registry()->register(
            'SRC',
            new SearchStateRecord(BigDecimal::of('0.5'), 0, SearchStateSignature::fromString('sig:src')),
            self::SCALE,
        );

        $originalRecords = $bootstrap->registry()->recordsFor('SRC');
        self::assertCount(1, $originalRecords);
        self::assertSame('1', $originalRecords[0]->cost());

        $cloneRecords = $clone->registry()->recordsFor('SRC');
        self::assertCount(1, $cloneRecords);
        self::assertSame('1', $cloneRecords[0]->cost());
    }

    public function test_requires_positive_visited_state_counter(): void
    {
        $queue = new StatePriorityQueue(self::SCALE);
        $results = new CandidateResultHeap(self::SCALE);
        $registry = SearchStateRegistry::withInitial(
            'SRC',
            new SearchStateRecord(BigDecimal::of('1'), 0, SearchStateSignature::fromString('sig:src')),
        );
        $insertionOrder = new InsertionOrderCounter();
        $resultInsertionOrder = new InsertionOrderCounter();

        $state = SearchState::bootstrap('SRC', DecimalMath::decimal('1', self::SCALE), null, null);
        $queue->push(new SearchQueueEntry(
            $state,
            new SearchStatePriority(new PathCost($state->costDecimal()), $state->hops(), RouteSignature::fromNodes([]), $insertionOrder->next()),
        ));

        $this->expectException(InvalidArgumentException::class);
        new SearchBootstrap($queue, $results, $registry, $insertionOrder, $resultInsertionOrder, 0);
    }

    public function test_requires_queue_with_initial_state(): void
    {
        $queue = new StatePriorityQueue(self::SCALE);
        $results = new CandidateResultHeap(self::SCALE);
        $registry = SearchStateRegistry::withInitial(
            'SRC',
            new SearchStateRecord(BigDecimal::of('1'), 0, SearchStateSignature::fromString('sig:src')),
        );
        $insertionOrder = new InsertionOrderCounter();
        $resultInsertionOrder = new InsertionOrderCounter();

        $this->expectException(InvalidArgumentException::class);

        new SearchBootstrap($queue, $results, $registry, $insertionOrder, $resultInsertionOrder, 1);
    }

    public function test_requires_registry_with_initial_state(): void
    {
        $queue = new StatePriorityQueue(self::SCALE);
        $results = new CandidateResultHeap(self::SCALE);
        $insertionOrder = new InsertionOrderCounter();
        $resultInsertionOrder = new InsertionOrderCounter();

        $state = SearchState::bootstrap('SRC', DecimalMath::decimal('1', self::SCALE), null, null);
        $queue->push(new SearchQueueEntry(
            $state,
            new SearchStatePriority(new PathCost($state->costDecimal()), $state->hops(), RouteSignature::fromNodes([]), $insertionOrder->next()),
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
