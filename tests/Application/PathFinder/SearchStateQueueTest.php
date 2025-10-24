<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchQueueEntry;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchState;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchStatePriority;
use SomeWork\P2PPathFinder\Application\PathFinder\SearchStateQueue;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\PathEdgeSequence;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;

final class SearchStateQueueTest extends TestCase
{
    public function test_insert_accepts_prepackaged_entries(): void
    {
        $queue = new SearchStateQueue(18);

        $stateA = $this->state('A', '0.100000000000000000');
        $entryA = new SearchQueueEntry($stateA, new SearchStatePriority('0.100000000000000000', 1));
        $queue->insert($entryA);

        $stateB = $this->state('B', '0.200000000000000000');
        $queue->insert(new SearchQueueEntry($stateB, new SearchStatePriority('0.200000000000000000', 2)));

        self::assertSame($stateA, $queue->extract());
        self::assertSame($stateB, $queue->extract());
    }

    public function test_compare_prefers_lower_cost_and_follows_fifo_on_ties(): void
    {
        $queue = new SearchStateQueue(18);

        $lowerCost = new SearchStatePriority(BcMath::normalize('0.010000000000000000', 18), 0);
        $higherCost = new SearchStatePriority(BcMath::normalize('0.020000000000000000', 18), 1);

        self::assertSame(1, $queue->compare($lowerCost, $higherCost));
        self::assertSame(-1, $queue->compare($higherCost, $lowerCost));

        $earlier = new SearchStatePriority(BcMath::normalize('0.030000000000000000', 18), 1);
        $later = new SearchStatePriority(BcMath::normalize('0.030000000000000000', 18), 0);

        self::assertSame(-1, $queue->compare($earlier, $later));
        self::assertSame(1, $queue->compare($later, $earlier));
    }

    private function state(string $node, string $cost): SearchState
    {
        return SearchState::fromComponents(
            $node,
            $cost,
            $cost,
            0,
            PathEdgeSequence::empty(),
            null,
            null,
            [$node => true],
        );
    }
}
