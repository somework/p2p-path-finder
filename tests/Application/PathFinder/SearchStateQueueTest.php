<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathCost;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\RouteSignature;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchQueueEntry;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchState;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchStatePriority;
use SomeWork\P2PPathFinder\Application\PathFinder\SearchStateQueue;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\PathEdgeSequence;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

use function explode;

/**
 * Search state priorities must order by cost, hop count, route signature, then insertion order.
 */
final class SearchStateQueueTest extends TestCase
{
    public function test_insert_accepts_prepackaged_entries(): void
    {
        $queue = new SearchStateQueue(18);

        $stateA = $this->state('A', '0.100000000000000000');
        $entryA = new SearchQueueEntry(
            $stateA,
            $this->priority('0.100000000000000000', $stateA->hops(), '', 1),
        );
        $queue->insert($entryA);

        $stateB = $this->state('B', '0.200000000000000000');
        $queue->insert(new SearchQueueEntry(
            $stateB,
            $this->priority('0.200000000000000000', $stateB->hops(), '', 2),
        ));

        self::assertSame($stateA, $queue->extract());
        self::assertSame($stateB, $queue->extract());
    }

    public function test_compare_prefers_lower_cost_then_fewer_hops_signature_and_fifo(): void
    {
        $queue = new SearchStateQueue(18);

        $lowerCost = $this->priority(BcMath::normalize('0.010000000000000000', 18), 0, '', 0);
        $higherCost = $this->priority(BcMath::normalize('0.020000000000000000', 18), 0, '', 1);

        self::assertSame(1, $queue->compare($lowerCost, $higherCost));
        self::assertSame(-1, $queue->compare($higherCost, $lowerCost));

        $fewerHops = $this->priority(BcMath::normalize('0.030000000000000000', 18), 1, '', 1);
        $moreHops = $this->priority(BcMath::normalize('0.030000000000000000', 18), 2, '', 0);

        self::assertSame(1, $queue->compare($fewerHops, $moreHops));
        self::assertSame(-1, $queue->compare($moreHops, $fewerHops));

        $alpha = $this->priority(BcMath::normalize('0.030000000000000000', 18), 2, 'A->B', 1);
        $beta = $this->priority(BcMath::normalize('0.030000000000000000', 18), 2, 'A->C', 0);

        self::assertSame(1, $queue->compare($alpha, $beta));
        self::assertSame(-1, $queue->compare($beta, $alpha));

        $earlier = $this->priority(BcMath::normalize('0.030000000000000000', 18), 2, 'A->C', 0);
        $later = $this->priority(BcMath::normalize('0.030000000000000000', 18), 2, 'A->C', 1);

        self::assertSame(1, $queue->compare($earlier, $later));
        self::assertSame(-1, $queue->compare($later, $earlier));
    }

    public function test_compare_prefers_lexicographically_smaller_signature_on_equal_cost_and_hops(): void
    {
        $queue = new SearchStateQueue(18);

        $alpha = $this->priority(BcMath::normalize('0.050000000000000000', 18), 1, 'A->B', 0);
        $beta = $this->priority(BcMath::normalize('0.050000000000000000', 18), 1, 'A->C', 1);

        self::assertSame(1, $queue->compare($alpha, $beta));
        self::assertSame(-1, $queue->compare($beta, $alpha));
    }

    public function test_compare_returns_zero_for_identical_priorities(): void
    {
        $queue = new SearchStateQueue(18);

        $first = $this->priority(BcMath::normalize('0.030000000000000000', 18), 2, 'A->B->C', 5);
        $second = $this->priority(BcMath::normalize('0.030000000000000000', 18), 2, 'A->B->C', 5);

        self::assertSame(0, $queue->compare($first, $second));
        self::assertSame(0, $queue->compare($second, $first));
    }

    public function test_priority_rejects_blank_route_nodes(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Route signature nodes cannot be empty (index 1).');

        $this->priority(BcMath::normalize('0.010000000000000000', 18), 1, 'SRC-> ', 0);
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

    /**
     * @param numeric-string $cost
     */
    private function priority(string $cost, int $hops, string $signature, int $order): SearchStatePriority
    {
        $nodes = '' === $signature ? [] : explode('->', $signature);

        return new SearchStatePriority(new PathCost($cost), $hops, new RouteSignature($nodes), $order);
    }
}
