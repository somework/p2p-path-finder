<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Engine\Queue;

use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathCost;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\RouteSignature;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Queue\StatePriorityQueue;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\State\SearchQueueEntry;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\State\SearchState;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\State\SearchStatePriority;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\PathEdgeSequence;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Helpers\DecimalFactory;

use function explode;

/**
 * Search state priorities must order by cost, hop count, route signature, then insertion order.
 */
#[CoversClass(StatePriorityQueue::class)]
final class StatePriorityQueueTest extends TestCase
{
    public function test_insert_accepts_prepackaged_entries(): void
    {
        $queue = new StatePriorityQueue(18);

        $stateA = $this->state('A', DecimalFactory::decimal('0.1'));
        $entryA = new SearchQueueEntry(
            $stateA,
            $this->priority(DecimalFactory::decimal('0.1'), $stateA->hops(), '', 1),
        );
        $queue->push($entryA);

        $stateB = $this->state('B', DecimalFactory::decimal('0.2'));
        $queue->push(new SearchQueueEntry(
            $stateB,
            $this->priority(DecimalFactory::decimal('0.2'), $stateB->hops(), '', 2),
        ));

        self::assertSame($stateA, $queue->extract());
        self::assertSame($stateB, $queue->extract());
    }

    public function test_queue_preserves_fifo_with_bigdecimal_costs(): void
    {
        $queue = new StatePriorityQueue(18);
        $signature = RouteSignature::fromNodes(['SRC', 'MID']);

        $stateA = $this->state('A', DecimalFactory::decimal('0.4'));
        $queue->push(
            new SearchQueueEntry(
                $stateA,
                new SearchStatePriority(
                    new PathCost(BigDecimal::of('0.4')),
                    $stateA->hops(),
                    $signature,
                    1,
                ),
            ),
        );

        $stateB = $this->state('B', DecimalFactory::decimal('0.4'));
        $queue->push(
            new SearchQueueEntry(
                $stateB,
                new SearchStatePriority(
                    new PathCost(BigDecimal::of('0.4')),
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
        $queue = new StatePriorityQueue(18);

        $lowerCost = $this->priority(DecimalFactory::decimal('0.01'), 0, '', 0);
        $higherCost = $this->priority(DecimalFactory::decimal('0.02'), 0, '', 1);

        self::assertSame(1, $queue->compare($lowerCost, $higherCost));
        self::assertSame(-1, $queue->compare($higherCost, $lowerCost));

        $fewerHops = $this->priority(DecimalFactory::decimal('0.03'), 1, '', 1);
        $moreHops = $this->priority(DecimalFactory::decimal('0.03'), 2, '', 0);

        self::assertSame(1, $queue->compare($fewerHops, $moreHops));
        self::assertSame(-1, $queue->compare($moreHops, $fewerHops));

        $alpha = $this->priority(DecimalFactory::decimal('0.03'), 2, 'A->B', 1);
        $beta = $this->priority(DecimalFactory::decimal('0.03'), 2, 'A->C', 0);

        self::assertSame(1, $queue->compare($alpha, $beta));
        self::assertSame(-1, $queue->compare($beta, $alpha));

        $earlier = $this->priority(DecimalFactory::decimal('0.03'), 2, 'A->C', 0);
        $later = $this->priority(DecimalFactory::decimal('0.03'), 2, 'A->C', 1);

        self::assertSame(1, $queue->compare($earlier, $later));
        self::assertSame(-1, $queue->compare($later, $earlier));
    }

    public function test_compare_prefers_lexicographically_smaller_signature_on_equal_cost_and_hops(): void
    {
        $queue = new StatePriorityQueue(18);

        $alpha = $this->priority(DecimalFactory::decimal('0.05'), 1, 'A->B', 0);
        $beta = $this->priority(DecimalFactory::decimal('0.05'), 1, 'A->C', 1);

        self::assertSame(1, $queue->compare($alpha, $beta));
        self::assertSame(-1, $queue->compare($beta, $alpha));
    }

    public function test_compare_returns_zero_for_identical_priorities(): void
    {
        $queue = new StatePriorityQueue(18);

        $first = $this->priority(DecimalFactory::decimal('0.03'), 2, 'A->B->C', 5);
        $second = $this->priority(DecimalFactory::decimal('0.03'), 2, 'A->B->C', 5);

        self::assertSame(0, $queue->compare($first, $second));
        self::assertSame(0, $queue->compare($second, $first));
    }

    public function test_priority_rejects_blank_route_nodes(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Route signature nodes cannot be empty (index 1).');

        $this->priority(DecimalFactory::decimal('0.01'), 1, 'SRC-> ', 0);
    }

    public function test_empty_queue_operations(): void
    {
        $queue = new StatePriorityQueue(18);

        self::assertTrue($queue->isEmpty());
        self::assertSame(0, $queue->count());

        $this->expectException(\RuntimeException::class);
        $queue->extract();
    }

    public function test_queue_count_and_empty_after_operations(): void
    {
        $queue = new StatePriorityQueue(18);

        // Initially empty
        self::assertTrue($queue->isEmpty());
        self::assertSame(0, $queue->count());

        // Add one item
        $state = $this->state('A', DecimalFactory::decimal('0.1'));
        $entry = new SearchQueueEntry(
            $state,
            $this->priority(DecimalFactory::decimal('0.1'), $state->hops(), '', 1),
        );
        $queue->push($entry);

        self::assertFalse($queue->isEmpty());
        self::assertSame(1, $queue->count());

        // Extract the item
        $extracted = $queue->extract();

        self::assertSame($state, $extracted);
        self::assertTrue($queue->isEmpty());
        self::assertSame(0, $queue->count());
    }

    public function test_queue_cloning_behavior(): void
    {
        $original = new StatePriorityQueue(18);

        // Add items to original
        $stateA = $this->state('A', DecimalFactory::decimal('0.1'));
        $stateB = $this->state('B', DecimalFactory::decimal('0.2'));

        $original->push(new SearchQueueEntry(
            $stateA,
            $this->priority(DecimalFactory::decimal('0.1'), $stateA->hops(), '', 1),
        ));
        $original->push(new SearchQueueEntry(
            $stateB,
            $this->priority(DecimalFactory::decimal('0.2'), $stateB->hops(), '', 2),
        ));

        // Clone the queue
        $clone = clone $original;

        // Both should have the same items
        self::assertFalse($original->isEmpty());
        self::assertFalse($clone->isEmpty());
        self::assertSame(2, $original->count());
        self::assertSame(2, $clone->count());

        // Extract from original
        $originalExtracted = $original->extract();
        self::assertSame($stateA, $originalExtracted);

        // Clone should still have both items
        self::assertSame(2, $clone->count());
        $cloneExtracted = $clone->extract();
        self::assertSame($stateA, $cloneExtracted);

        // Now both should have one item left
        self::assertSame(1, $original->count());
        self::assertSame(1, $clone->count());
    }

    public function test_constructor_with_different_scales(): void
    {
        // Test with various valid scales
        $scales = [1, 2, 8, 18, 50];

        foreach ($scales as $scale) {
            $queue = new StatePriorityQueue($scale);

            // Should be able to create and use the queue
            self::assertTrue($queue->isEmpty());
            self::assertSame(0, $queue->count());
        }
    }

    public function test_single_element_queue_operations(): void
    {
        $queue = new StatePriorityQueue(18);

        $state = $this->state('SINGLE', DecimalFactory::decimal('0.5'));
        $entry = new SearchQueueEntry(
            $state,
            $this->priority(DecimalFactory::decimal('0.5'), $state->hops(), '', 1),
        );

        $queue->push($entry);

        self::assertFalse($queue->isEmpty());
        self::assertSame(1, $queue->count());

        $extracted = $queue->extract();
        self::assertSame($state, $extracted);

        self::assertTrue($queue->isEmpty());
        self::assertSame(0, $queue->count());
    }

    public function test_large_queue_operations(): void
    {
        $queue = new StatePriorityQueue(18);

        // Add many items with different priorities
        for ($i = 0; $i < 100; ++$i) {
            $cost = DecimalFactory::decimal((string) ($i / 100.0));
            $state = $this->state('NODE'.$i, $cost);

            $queue->push(new SearchQueueEntry(
                $state,
                $this->priority($cost, $state->hops(), '', $i),
            ));
        }

        self::assertSame(100, $queue->count());

        // Extract all items - should be in priority order (lowest cost first)
        $extracted = [];
        for ($i = 0; $i < 100; ++$i) {
            $extracted[] = $queue->extract();
        }

        self::assertTrue($queue->isEmpty());
        self::assertSame(0, $queue->count());

        // First extracted should be the one with lowest cost (NODE0 with cost 0.00)
        self::assertSame('NODE0', $extracted[0]->node());
    }

    public function test_priority_comparison_edge_cases(): void
    {
        $queue = new StatePriorityQueue(18);

        // Test with very small differences
        $tiny1 = $this->priority(DecimalFactory::decimal('0.000000000000000001'), 0, '', 1);
        $tiny2 = $this->priority(DecimalFactory::decimal('0.000000000000000002'), 0, '', 2);

        self::assertSame(1, $queue->compare($tiny1, $tiny2)); // tiny1 < tiny2
        self::assertSame(-1, $queue->compare($tiny2, $tiny1)); // tiny2 > tiny1

        // Test with zero cost
        $zeroCost = $this->priority(DecimalFactory::decimal('0'), 0, '', 1);
        $nonZeroCost = $this->priority(DecimalFactory::decimal('0.000000000000000001'), 0, '', 2);

        self::assertSame(1, $queue->compare($zeroCost, $nonZeroCost)); // zero < non-zero
        self::assertSame(-1, $queue->compare($nonZeroCost, $zeroCost));

        // Test with maximum hop count differences
        $fewHops = $this->priority(DecimalFactory::decimal('1.0'), 0, '', 1);
        $manyHops = $this->priority(DecimalFactory::decimal('1.0'), 1000, '', 2);

        self::assertSame(1, $queue->compare($fewHops, $manyHops)); // fewer hops preferred
        self::assertSame(-1, $queue->compare($manyHops, $fewHops));

        // Test with very long route signatures
        $shortSig = $this->priority(DecimalFactory::decimal('1.0'), 1, 'A->B', 1);
        $longSig = $this->priority(DecimalFactory::decimal('1.0'), 1, 'A->B->C->D->E->F->G->H->I->J', 2);

        self::assertSame(1, $queue->compare($shortSig, $longSig)); // shorter signature preferred
        self::assertSame(-1, $queue->compare($longSig, $shortSig));
    }

    public function test_queue_with_mixed_priority_types(): void
    {
        $queue = new StatePriorityQueue(18);

        // Add items with different combinations of cost, hops, and signatures
        $entries = [
            ['cost' => '0.1', 'hops' => 0, 'sig' => '', 'order' => 1, 'node' => 'LOW_COST'],
            ['cost' => '0.2', 'hops' => 0, 'sig' => '', 'order' => 2, 'node' => 'MED_COST'],
            ['cost' => '0.2', 'hops' => 1, 'sig' => '', 'order' => 3, 'node' => 'HIGH_HOPS'],
            ['cost' => '0.2', 'hops' => 0, 'sig' => 'A->B', 'order' => 4, 'node' => 'WITH_SIG'],
            ['cost' => '0.2', 'hops' => 0, 'sig' => 'A->C', 'order' => 5, 'node' => 'SIG_DIFF'],
        ];

        foreach ($entries as $entry) {
            $state = $this->state($entry['node'], DecimalFactory::decimal($entry['cost']));
            $queue->push(new SearchQueueEntry(
                $state,
                $this->priority(DecimalFactory::decimal($entry['cost']), $entry['hops'], $entry['sig'], $entry['order']),
            ));
        }

        // Extract in priority order: LOW_COST (lowest cost), then MED_COST (same cost/hops as WITH_SIG/SIG_DIFF but empty sig has highest priority), then WITH_SIG, then SIG_DIFF, then HIGH_HOPS (most hops)
        $expectedOrder = ['LOW_COST', 'MED_COST', 'WITH_SIG', 'SIG_DIFF', 'HIGH_HOPS'];

        foreach ($expectedOrder as $expectedNode) {
            $extracted = $queue->extract();
            self::assertSame($expectedNode, $extracted->node());
        }

        self::assertTrue($queue->isEmpty());
    }

    public function test_queue_handles_identical_priorities(): void
    {
        $queue = new StatePriorityQueue(18);

        // Add items with identical priorities (same cost, hops, signature, but different order)
        $state1 = $this->state('FIRST', DecimalFactory::decimal('1.0'));
        $state2 = $this->state('SECOND', DecimalFactory::decimal('1.0'));
        $state3 = $this->state('THIRD', DecimalFactory::decimal('1.0'));

        // Use different order values to test that identical priorities are handled
        $queue->push(new SearchQueueEntry($state1, $this->priority(DecimalFactory::decimal('1.0'), 1, 'SAME', 1)));
        $queue->push(new SearchQueueEntry($state2, $this->priority(DecimalFactory::decimal('1.0'), 1, 'SAME', 2)));
        $queue->push(new SearchQueueEntry($state3, $this->priority(DecimalFactory::decimal('1.0'), 1, 'SAME', 3)));

        // Extract all - order may vary due to priority queue implementation
        $extracted = [];
        while (!$queue->isEmpty()) {
            $extracted[] = $queue->extract()->node();
        }

        // All states should be extracted
        self::assertCount(3, $extracted);
        self::assertContains('FIRST', $extracted);
        self::assertContains('SECOND', $extracted);
        self::assertContains('THIRD', $extracted);
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
