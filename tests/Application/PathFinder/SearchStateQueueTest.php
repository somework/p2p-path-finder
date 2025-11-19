<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder;

use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathCost;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\RouteSignature;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchQueueEntry;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchState;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchStatePriority;
use SomeWork\P2PPathFinder\Application\PathFinder\SearchStateQueue;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\PathEdgeSequence;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Application\Support\DecimalFactory;

use function explode;

/**
 * Search state priorities must order by cost, hop count, route signature, then insertion order.
 */
final class SearchStateQueueTest extends TestCase
{
    public function test_insert_accepts_prepackaged_entries(): void
    {
        $queue = new SearchStateQueue(18);

        $stateA = $this->state('A', DecimalFactory::decimal('0.100000000000000000'));
        $entryA = new SearchQueueEntry(
            $stateA,
            $this->priority(DecimalFactory::decimal('0.100000000000000000'), $stateA->hops(), '', 1),
        );
        $queue->insert($entryA);

        $stateB = $this->state('B', DecimalFactory::decimal('0.200000000000000000'));
        $queue->insert(new SearchQueueEntry(
            $stateB,
            $this->priority(DecimalFactory::decimal('0.200000000000000000'), $stateB->hops(), '', 2),
        ));

        self::assertSame($stateA, $queue->extract());
        self::assertSame($stateB, $queue->extract());
    }

    public function test_queue_preserves_fifo_with_bigdecimal_costs(): void
    {
        $queue = new SearchStateQueue(18);
        $signature = RouteSignature::fromNodes(['SRC', 'MID']);

        $stateA = $this->state('A', DecimalFactory::decimal('0.400000000000000000'));
        $queue->push(
            new SearchQueueEntry(
                $stateA,
                new SearchStatePriority(
                    new PathCost(BigDecimal::of('0.400000000000000000')),
                    $stateA->hops(),
                    $signature,
                    1,
                ),
            ),
        );

        $stateB = $this->state('B', DecimalFactory::decimal('0.400000000000000000'));
        $queue->push(
            new SearchQueueEntry(
                $stateB,
                new SearchStatePriority(
                    new PathCost(BigDecimal::of('0.400000000000000000')),
                    $stateB->hops(),
                    $signature,
                    2,
                ),
            ),
        );

        self::assertSame($stateA, $queue->extract());
        self::assertSame($stateB, $queue->extract());
    }

    public function test_compare_prefers_lower_cost_then_fewer_hops_signature_and_fifo(): void
    {
        $queue = new SearchStateQueue(18);

        $lowerCost = $this->priority(DecimalFactory::decimal('0.010000000000000000'), 0, '', 0);
        $higherCost = $this->priority(DecimalFactory::decimal('0.020000000000000000'), 0, '', 1);

        self::assertSame(1, $queue->compare($lowerCost, $higherCost));
        self::assertSame(-1, $queue->compare($higherCost, $lowerCost));

        $fewerHops = $this->priority(DecimalFactory::decimal('0.030000000000000000'), 1, '', 1);
        $moreHops = $this->priority(DecimalFactory::decimal('0.030000000000000000'), 2, '', 0);

        self::assertSame(1, $queue->compare($fewerHops, $moreHops));
        self::assertSame(-1, $queue->compare($moreHops, $fewerHops));

        $alpha = $this->priority(DecimalFactory::decimal('0.030000000000000000'), 2, 'A->B', 1);
        $beta = $this->priority(DecimalFactory::decimal('0.030000000000000000'), 2, 'A->C', 0);

        self::assertSame(1, $queue->compare($alpha, $beta));
        self::assertSame(-1, $queue->compare($beta, $alpha));

        $earlier = $this->priority(DecimalFactory::decimal('0.030000000000000000'), 2, 'A->C', 0);
        $later = $this->priority(DecimalFactory::decimal('0.030000000000000000'), 2, 'A->C', 1);

        self::assertSame(1, $queue->compare($earlier, $later));
        self::assertSame(-1, $queue->compare($later, $earlier));
    }

    public function test_compare_prefers_lexicographically_smaller_signature_on_equal_cost_and_hops(): void
    {
        $queue = new SearchStateQueue(18);

        $alpha = $this->priority(DecimalFactory::decimal('0.050000000000000000'), 1, 'A->B', 0);
        $beta = $this->priority(DecimalFactory::decimal('0.050000000000000000'), 1, 'A->C', 1);

        self::assertSame(1, $queue->compare($alpha, $beta));
        self::assertSame(-1, $queue->compare($beta, $alpha));
    }

    public function test_compare_returns_zero_for_identical_priorities(): void
    {
        $queue = new SearchStateQueue(18);

        $first = $this->priority(DecimalFactory::decimal('0.030000000000000000'), 2, 'A->B->C', 5);
        $second = $this->priority(DecimalFactory::decimal('0.030000000000000000'), 2, 'A->B->C', 5);

        self::assertSame(0, $queue->compare($first, $second));
        self::assertSame(0, $queue->compare($second, $first));
    }

    public function test_priority_rejects_blank_route_nodes(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Route signature nodes cannot be empty (index 1).');

        $this->priority(DecimalFactory::decimal('0.010000000000000000'), 1, 'SRC-> ', 0);
    }

    private function state(string $node, BigDecimal $cost): SearchState
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

    private function priority(BigDecimal $cost, int $hops, string $signature, int $order): SearchStatePriority
    {
        $nodes = '' === $signature ? [] : explode('->', $signature);

        return new SearchStatePriority(new PathCost($cost), $hops, RouteSignature::fromNodes($nodes), $order);
    }
}
