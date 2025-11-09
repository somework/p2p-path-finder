<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathCost;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\RouteSignature;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchQueueEntry;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchState;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchStatePriority;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchStatePriorityQueue;
use SomeWork\P2PPathFinder\Application\PathFinder\SearchStateQueue;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\PathEdgeSequence;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;

use function count;

/**
 * Property-based tests covering the tie-breaking rules of {@see SearchStatePriority::compare()}.
 */
final class SearchStateQueueOrderingPropertyTest extends TestCase
{
    private const SCALE = 18;

    private const COST = '0.500000000000000000';

    /**
     * @param list<int> $permutation
     *
     * @dataProvider provideEqualCostPermutations
     */
    public function test_priority_queue_orders_equal_cost_states(array $permutation): void
    {
        $candidates = $this->buildEqualCostCandidates();
        $expectedIndices = $this->expectedOrderIndices($candidates);

        $queue = new SearchStatePriorityQueue(self::SCALE);

        foreach ($permutation as $index) {
            $candidate = $candidates[$index];
            $queue->insert($candidate['entry'], $candidate['priority']);
        }

        foreach ($expectedIndices as $expectedIndex) {
            $entry = $queue->extract();
            self::assertSame($candidates[$expectedIndex]['entry'], $entry);
        }

        self::assertSame(0, $queue->count());
    }

    /**
     * @param list<int> $permutation
     *
     * @dataProvider provideEqualCostPermutations
     */
    public function test_queue_wrapper_extracts_states_in_priority_order(array $permutation): void
    {
        $candidates = $this->buildEqualCostCandidates();
        $expectedIndices = $this->expectedOrderIndices($candidates);

        $queue = new SearchStateQueue(self::SCALE);

        foreach ($permutation as $index) {
            $candidate = $candidates[$index];
            $queue->push($candidate['entry']);
        }

        foreach ($expectedIndices as $expectedIndex) {
            $state = $queue->extract();
            self::assertSame($candidates[$expectedIndex]['state'], $state);
        }

        self::assertTrue($queue->isEmpty());
    }

    /**
     * @return iterable<string, array{list<int>}>
     */
    public static function provideEqualCostPermutations(): iterable
    {
        $indexes = range(0, count(self::baseDefinitions()) - 1);

        for ($seed = 0; $seed < 64; ++$seed) {
            yield 'seed-'.$seed => [self::permuteWithSeed($indexes, $seed)];
        }
    }

    /**
     * @return list<array{
     *     entry: SearchQueueEntry,
     *     state: SearchState,
     *     priority: SearchStatePriority,
     *     cost: string,
     *     hops: int,
     *     signature: string,
     *     order: int
     * }>
     */
    private function buildEqualCostCandidates(): array
    {
        $cost = BcMath::normalize(self::COST, self::SCALE);

        $candidates = [];
        foreach (self::baseDefinitions() as $definition) {
            $nodes = $definition['nodes'];
            $order = $definition['order'];
            $hops = max(count($nodes) - 1, 0);
            $terminalNode = $nodes[$hops] ?? $nodes[0];

            $state = SearchState::fromComponents(
                $terminalNode,
                $cost,
                $cost,
                $hops,
                PathEdgeSequence::empty(),
                null,
                null,
                $this->visitedRegistry($nodes),
            );

            $signature = RouteSignature::fromNodes($nodes);
            $priority = new SearchStatePriority(new PathCost($cost), $hops, $signature, $order);
            $entry = new SearchQueueEntry($state, $priority);

            $candidates[] = [
                'entry' => $entry,
                'state' => $state,
                'priority' => $priority,
                'cost' => $priority->cost()->value(),
                'hops' => $priority->hops(),
                'signature' => $signature->value(),
                'order' => $priority->order(),
            ];
        }

        return $candidates;
    }

    /**
     * @param list<array{cost: string, hops: int, signature: string, order: int}> $candidates
     *
     * @return list<int>
     */
    private function expectedOrderIndices(array $candidates): array
    {
        $indexed = [];
        foreach ($candidates as $index => $candidate) {
            $indexed[] = [
                'index' => $index,
                'cost' => $candidate['cost'],
                'hops' => $candidate['hops'],
                'signature' => $candidate['signature'],
                'order' => $candidate['order'],
            ];
        }

        usort($indexed, static function (array $left, array $right): int {
            $comparison = BcMath::comp($left['cost'], $right['cost'], self::SCALE);
            if (0 !== $comparison) {
                return $comparison;
            }

            $comparison = $left['hops'] <=> $right['hops'];
            if (0 !== $comparison) {
                return $comparison;
            }

            $comparison = $left['signature'] <=> $right['signature'];
            if (0 !== $comparison) {
                return $comparison;
            }

            return $left['order'] <=> $right['order'];
        });

        return array_column($indexed, 'index');
    }

    /**
     * @param list<int> $indexes
     *
     * @return list<int>
     */
    private static function permuteWithSeed(array $indexes, int $seed): array
    {
        $permutation = $indexes;
        $state = $seed;
        $count = count($permutation);

        for ($position = $count - 1; $position > 0; --$position) {
            $state = self::nextSeed($state);
            $swapIndex = $state % ($position + 1);

            $temporary = $permutation[$position];
            $permutation[$position] = $permutation[$swapIndex];
            $permutation[$swapIndex] = $temporary;
        }

        return $permutation;
    }

    private static function nextSeed(int $state): int
    {
        $state = ($state * 1103515245 + 12345) & 0x7FFFFFFF;

        return $state;
    }

    /**
     * @param non-empty-list<string> $nodes
     *
     * @return array<string, true>
     */
    private function visitedRegistry(array $nodes): array
    {
        $visited = [];
        foreach ($nodes as $node) {
            $visited[$node] = true;
        }

        return $visited;
    }

    /**
     * @return list<array{nodes: non-empty-list<string>, order: int}>
     */
    private static function baseDefinitions(): array
    {
        return [
            ['nodes' => ['SRC', 'ALPHA'], 'order' => 0],
            ['nodes' => ['SRC', 'BETA'], 'order' => 1],
            ['nodes' => ['SRC', 'ALPHA', 'OMEGA'], 'order' => 2],
            ['nodes' => ['SRC', 'BETA', 'OMEGA'], 'order' => 3],
            ['nodes' => ['SRC', 'ALPHA'], 'order' => 4],
            ['nodes' => ['SRC', 'ALPHA', 'MID', 'OMEGA'], 'order' => 5],
        ];
    }
}
