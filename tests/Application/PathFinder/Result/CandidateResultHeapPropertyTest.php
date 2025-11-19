<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder\Result;

use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathFinder\CandidateResultHeap;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Heap\CandidateHeapEntry;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Heap\CandidatePriority;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathCost;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\RouteSignature;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\CandidatePath;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\PathEdge;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\PathEdgeSequence;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\AssetPair;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Domain\ValueObject\OrderBounds;
use SomeWork\P2PPathFinder\Tests\Application\Support\DecimalFactory;
use SomeWork\P2PPathFinder\Tests\Support\DecimalMath;

use function array_column;
use function array_map;
use function count;
use function max;
use function range;
use function sprintf;
use function usort;

/**
 * Property-based regression tests for {@see CandidateResultHeap} ordering semantics.
 */
final class CandidateResultHeapPropertyTest extends TestCase
{
    private const SCALE = 18;

    /**
     * @param list<int> $permutation
     *
     * @dataProvider provideMixedCandidatePermutations
     */
    public function test_heap_matches_reference_sorter(array $permutation): void
    {
        $candidates = $this->buildCandidates(self::mixedDefinitions());
        $expectedIndices = $this->expectedOrderIndices($candidates);

        $heap = new CandidateResultHeap(self::SCALE);

        foreach ($permutation as $index) {
            $heap->push($candidates[$index]['entry']);
        }

        foreach ($expectedIndices as $expectedIndex) {
            $entry = $heap->extract();
            self::assertSame($candidates[$expectedIndex]['entry'], $entry);
        }

        self::assertTrue($heap->isEmpty());
    }

    /**
     * @param list<int> $permutation
     *
     * @dataProvider provideIdenticalSignaturePermutations
     */
    public function test_heap_orders_identical_signatures_deterministically(array $permutation): void
    {
        $candidates = $this->buildCandidates(self::identicalSignatureDefinitions());
        $expectedIndices = $this->expectedOrderIndices($candidates);

        $heap = new CandidateResultHeap(self::SCALE);

        foreach ($permutation as $index) {
            $heap->push($candidates[$index]['entry']);
        }

        $extractedOrders = [];
        foreach ($expectedIndices as $expectedIndex) {
            $entry = $heap->extract();
            $priority = $entry->priority();
            $extractedOrders[] = $priority->order();

            self::assertSame($candidates[$expectedIndex]['entry'], $entry);
        }

        self::assertSame(
            array_map(
                static fn (int $index): int => $candidates[$index]['order'],
                $expectedIndices,
            ),
            $extractedOrders,
        );
        self::assertTrue($heap->isEmpty());
    }

    /**
     * @return iterable<string, array{list<int>}>
     */
    public static function provideMixedCandidatePermutations(): iterable
    {
        $indexes = range(0, count(self::mixedDefinitions()) - 1);

        for ($seed = 0; $seed < 64; ++$seed) {
            yield 'mixed-seed-'.$seed => [self::permuteWithSeed($indexes, $seed)];
        }
    }

    /**
     * @return iterable<string, array{list<int>}>
     */
    public static function provideIdenticalSignaturePermutations(): iterable
    {
        $indexes = range(0, count(self::identicalSignatureDefinitions()) - 1);

        for ($seed = 0; $seed < 64; ++$seed) {
            yield 'identical-seed-'.$seed => [self::permuteWithSeed($indexes, $seed)];
        }
    }

    /**
     * @param list<array{cost: string, nodes: list<string>, order: int, product?: string, signature?: list<string>}> $definitions
     *
     * @return list<array{
     *     entry: CandidateHeapEntry,
     *     cost: string,
     *     hops: int,
     *     order: int,
     *     signature: string,
     * }>
     */
    private function buildCandidates(array $definitions): array
    {
        $candidates = [];
        foreach ($definitions as $index => $definition) {
            $nodes = $definition['nodes'];
            $hops = max(count($nodes) - 1, 0);
            $edges = $this->buildEdgeSequence($nodes, $index);

            $cost = DecimalFactory::decimal($definition['cost'], self::SCALE);
            $product = DecimalFactory::decimal($definition['product'] ?? '1', self::SCALE);

            $candidate = CandidatePath::from(
                $cost,
                $product,
                $hops,
                $edges,
            );
            $signatureNodes = $definition['signature'] ?? $nodes;
            $priority = new CandidatePriority(
                new PathCost($cost),
                $hops,
                RouteSignature::fromNodes($signatureNodes),
                $definition['order'],
            );

            $entry = new CandidateHeapEntry($candidate, $priority);

            $candidates[] = [
                'entry' => $entry,
                'cost' => $priority->cost()->value(),
                'hops' => $priority->hops(),
                'signature' => $priority->routeSignature()->value(),
                'order' => $priority->order(),
            ];
        }

        return $candidates;
    }

    /**
     * @param list<string> $nodes
     */
    private function buildEdgeSequence(array $nodes, int $seed): PathEdgeSequence
    {
        $count = count($nodes);
        if ($count < 2) {
            return PathEdgeSequence::empty();
        }

        $edges = [];
        for ($position = 0; $position < $count - 1; ++$position) {
            $from = $nodes[$position];
            $to = $nodes[$position + 1];
            $rate = ExchangeRate::fromString($from, $to, sprintf('1.%06d', ($seed + $position + 1) % 900000 + 100000), 6);
            $bounds = OrderBounds::from(
                Money::fromString($from, '1', 2),
                Money::fromString($from, '10', 2),
            );
            $order = new Order(
                OrderSide::BUY,
                AssetPair::fromString($from, $to),
                $bounds,
                $rate,
            );

            $edges[] = PathEdge::create(
                $from,
                $to,
                $order,
                $rate,
                OrderSide::BUY,
                BigDecimal::of('1'),
            );
        }

        return PathEdgeSequence::fromList($edges);
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
            $comparison = DecimalMath::comp($right['cost'], $left['cost'], self::SCALE);
            if (0 !== $comparison) {
                return $comparison;
            }

            $comparison = $right['hops'] <=> $left['hops'];
            if (0 !== $comparison) {
                return $comparison;
            }

            $comparison = $right['signature'] <=> $left['signature'];
            if (0 !== $comparison) {
                return $comparison;
            }

            return $right['order'] <=> $left['order'];
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
        return ($state * 1103515245 + 12345) & 0x7FFFFFFF;
    }

    /**
     * @return list<array{cost: string, nodes: list<string>, order: int, product?: string, signature?: list<string>}>
     */
    private static function mixedDefinitions(): array
    {
        return [
            ['cost' => '2.500', 'product' => '0.900', 'nodes' => ['SRC', 'ALPHA', 'OMEGA'], 'order' => 0],
            ['cost' => '2.500', 'product' => '0.875', 'nodes' => ['SRC', 'ALPHA', 'OMEGA'], 'order' => 4],
            ['cost' => '2.500', 'product' => '1.125', 'nodes' => ['SRC', 'ALPHA', 'MID', 'OMEGA'], 'order' => 5],
            ['cost' => '2.500', 'product' => '1.050', 'nodes' => ['SRC', 'BETA', 'OMEGA'], 'order' => 2],
            ['cost' => '1.750', 'product' => '0.600', 'nodes' => ['SRC', 'GAMMA'], 'order' => 1],
            ['cost' => '1.750', 'product' => '0.620', 'nodes' => ['SRC', 'GAMMA'], 'order' => 3],
            ['cost' => '1.750', 'product' => '0.650', 'nodes' => ['SRC', 'GAMMA', 'OMEGA'], 'order' => 6],
            ['cost' => '1.750', 'product' => '0.660', 'nodes' => ['SRC', 'GAMMA', 'OMEGA'], 'order' => 7],
        ];
    }

    /**
     * @return list<array{cost: string, nodes: list<string>, order: int, product?: string, signature?: list<string>}>
     */
    private static function identicalSignatureDefinitions(): array
    {
        return [
            ['cost' => '3.000', 'product' => '0.500', 'nodes' => ['SRC', 'OMEGA'], 'order' => 0, 'signature' => ['SRC', 'OMEGA']],
            ['cost' => '3.000', 'product' => '0.500', 'nodes' => ['SRC', 'OMEGA'], 'order' => 1, 'signature' => ['SRC', 'OMEGA']],
            ['cost' => '3.000', 'product' => '0.500', 'nodes' => ['SRC', 'OMEGA'], 'order' => 2, 'signature' => ['SRC', 'OMEGA']],
            ['cost' => '3.000', 'product' => '0.500', 'nodes' => ['SRC', 'OMEGA'], 'order' => 3, 'signature' => ['SRC', 'OMEGA']],
            ['cost' => '3.000', 'product' => '0.500', 'nodes' => ['SRC', 'OMEGA'], 'order' => 4, 'signature' => ['SRC', 'OMEGA']],
            ['cost' => '3.000', 'product' => '0.500', 'nodes' => ['SRC', 'OMEGA'], 'order' => 5, 'signature' => ['SRC', 'OMEGA']],
        ];
    }
}
