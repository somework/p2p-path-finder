<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\PathFinder\CandidateResultHeap;
use SomeWork\P2PPathFinder\Application\PathFinder\PathFinder;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\GuardLimitStatus;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\SearchOutcome;
use SomeWork\P2PPathFinder\Domain\Order\FeeBreakdown;
use SomeWork\P2PPathFinder\Domain\Order\FeePolicy;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Fixture\CurrencyScenarioFactory;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

use function array_key_last;
use function array_reverse;
use function array_unique;
use function count;
use function implode;
use function in_array;

final class PathFinderTest extends TestCase
{
    private const SCALE = 18;

    /**
     * @param SearchOutcome<array> $searchResult
     *
     * @return list<array>
     */
    private static function extractPaths(SearchOutcome $searchResult): array
    {
        return $searchResult->paths();
    }

    /**
     * @param SearchOutcome<array> $searchResult
     */
    private static function extractGuardLimits(SearchOutcome $searchResult): GuardLimitStatus
    {
        return $searchResult->guardLimits();
    }

    /**
     * @dataProvider provideInvalidMaxHops
     */
    public function test_it_requires_positive_max_hops(int $invalidMaxHops): void
    {
        $this->expectException(InvalidInput::class);

        new PathFinder($invalidMaxHops, '0.0');
    }

    public function test_it_requires_positive_result_limit(): void
    {
        $this->expectException(InvalidInput::class);

        new PathFinder(maxHops: 1, tolerance: '0.0', topK: 0);
    }

    public function test_it_requires_positive_expansion_guard(): void
    {
        $this->expectException(InvalidInput::class);

        new PathFinder(maxHops: 1, tolerance: '0.0', topK: 1, maxExpansions: 0);
    }

    public function test_it_requires_positive_visited_state_guard(): void
    {
        $this->expectException(InvalidInput::class);

        new PathFinder(maxHops: 1, tolerance: '0.0', topK: 1, maxExpansions: 1, maxVisitedStates: 0);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideInvalidMaxHops(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-3];
    }

    /**
     * @dataProvider provideInvalidTolerances
     */
    public function test_it_requires_tolerance_within_expected_range(string $invalidTolerance): void
    {
        $this->expectException(InvalidInput::class);

        new PathFinder(1, $invalidTolerance);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidTolerances(): iterable
    {
        yield 'negative' => ['-0.001'];
        yield 'greater_than_or_equal_to_one' => ['1.0'];
        yield 'string_out_of_range' => ['1.0000000000000001'];
    }

    /**
     * @dataProvider provideHighPrecisionTolerances
     */
    public function test_it_accepts_high_precision_string_tolerance(string $tolerance, string $expectedAmplifier): void
    {
        $finder = new PathFinder(maxHops: 1, tolerance: $tolerance);

        $property = new ReflectionProperty(PathFinder::class, 'toleranceAmplifier');
        $property->setAccessible(true);

        self::assertSame($expectedAmplifier, $property->getValue($finder));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideHighPrecisionTolerances(): iterable
    {
        $normalized = BcMath::normalize('0.9999999999999999', self::SCALE);
        $complement = BcMath::sub('1', $normalized, self::SCALE);
        yield 'sixteen_nines' => [
            '0.9999999999999999',
            BcMath::div('1', $complement, self::SCALE),
        ];

        $tiny = BcMath::normalize('0.0000000000000001', self::SCALE);
        $tinyComplement = BcMath::sub('1', $tiny, self::SCALE);
        yield 'tiny_fraction' => [
            '0.0000000000000001',
            BcMath::div('1', $tinyComplement, self::SCALE),
        ];
    }

    /**
     * @dataProvider provideRubToIdrConstraintScenarios
     *
     * @param list<array{from: string, to: string}> $expectedRoute
     */
    public function test_it_finds_best_rub_to_idr_path_under_various_filters(
        int $maxHops,
        string $tolerance,
        int $expectedHopCount,
        array $expectedRoute,
        string $expectedProduct
    ): void {
        $orders = self::buildComprehensiveOrderBook();
        $graph = (new GraphBuilder())->build($orders);

        $finder = new PathFinder($maxHops, $tolerance);
        $results = self::extractPaths($finder->findBestPaths($graph, 'RUB', 'IDR'));

        self::assertNotSame([], $results);
        $result = $results[0];

        self::assertSame($expectedHopCount, $result['hops']);
        self::assertCount($expectedHopCount, $result['edges']);

        foreach ($expectedRoute as $index => $edge) {
            self::assertSame($edge['from'], $result['edges'][$index]['from']);
            self::assertSame($edge['to'], $result['edges'][$index]['to']);
        }

        self::assertSame($expectedProduct, $result['product']);
        $expectedCost = BcMath::div('1', $expectedProduct, self::SCALE);
        self::assertSame($expectedCost, $result['cost']);
    }

    public function test_it_limits_results_to_requested_top_k(): void
    {
        $orders = self::buildComprehensiveOrderBook();
        $graph = (new GraphBuilder())->build($orders);

        $finder = new PathFinder(maxHops: 4, tolerance: '0.999', topK: 2);
        $searchResult = $finder->findBestPaths($graph, 'RUB', 'IDR');
        $results = $searchResult->paths();

        self::assertGreaterThanOrEqual(2, count($results));
        self::assertCount(2, $results);
        self::assertLessThanOrEqual(0, BcMath::comp($results[0]['cost'], $results[1]['cost'], self::SCALE));
        self::assertGreaterThanOrEqual(0, BcMath::comp($results[0]['product'], $results[1]['product'], self::SCALE));

        $finderWithBroaderLimit = new PathFinder(maxHops: 4, tolerance: '0.999', topK: 3);
        $extendedResults = $finderWithBroaderLimit->findBestPaths($graph, 'RUB', 'IDR');
        $extendedPaths = $extendedResults->paths();

        self::assertGreaterThan(2, count($extendedPaths));
    }

    public function test_it_preserves_discovery_order_for_equal_cost_candidates(): void
    {
        $orders = [
            OrderFactory::sell('USD', 'EUR', '1.000', '1.000', '2.000', 3, 3),
            OrderFactory::sell('GBP', 'EUR', '1.000', '1.000', '4.000', 3, 3),
            OrderFactory::buy('GBP', 'USD', '1.000', '1.000', '2.000', 3, 3),
        ];

        $graph = (new GraphBuilder())->build($orders);

        $finder = new PathFinder(maxHops: 3, tolerance: '0.0', topK: 2);
        $results = self::extractPaths($finder->findBestPaths($graph, 'EUR', 'USD'));

        self::assertCount(1, $results);
        self::assertSame(1, $results[0]['hops']);

        $direct = $results[0];
        self::assertSame('EUR', $direct['edges'][0]['from']);
        self::assertSame('USD', $direct['edges'][0]['to']);
    }

    public function test_finalize_results_orders_candidates_by_cost_hops_and_signature(): void
    {
        $finder = new PathFinder(maxHops: 3, tolerance: '0.0', topK: 3);

        $heapFactory = new ReflectionMethod(PathFinder::class, 'createResultHeap');
        $heapFactory->setAccessible(true);
        /** @var CandidateResultHeap $heap */
        $heap = $heapFactory->invoke($finder);

        $recordResult = new ReflectionMethod(PathFinder::class, 'recordResult');
        $recordResult->setAccessible(true);

        $candidates = [
            [
                'cost' => '0.100000000000000000',
                'product' => '10.000000000000000000',
                'hops' => 2,
                'edges' => [
                    ['from' => 'SRC', 'to' => 'BET'],
                    ['from' => 'BET', 'to' => 'TRG'],
                ],
                'amountRange' => null,
                'desiredAmount' => null,
            ],
            [
                'cost' => '0.100000000000000000',
                'product' => '10.000000000000000000',
                'hops' => 2,
                'edges' => [
                    ['from' => 'SRC', 'to' => 'ALP'],
                    ['from' => 'ALP', 'to' => 'TRG'],
                ],
                'amountRange' => null,
                'desiredAmount' => null,
            ],
            [
                'cost' => '0.100000000000000000',
                'product' => '10.000000000000000000',
                'hops' => 1,
                'edges' => [
                    ['from' => 'SRC', 'to' => 'TRG'],
                ],
                'amountRange' => null,
                'desiredAmount' => null,
            ],
        ];

        foreach ($candidates as $index => $candidate) {
            $recordResult->invoke($finder, $heap, $candidate, $index);
        }

        $finalize = new ReflectionMethod(PathFinder::class, 'finalizeResults');
        $finalize->setAccessible(true);
        /** @var list<array> $finalized */
        $finalized = $finalize->invoke($finder, $heap);

        self::assertCount(3, $finalized);

        $expectedHops = [1, 2, 2];
        foreach ($finalized as $index => $candidate) {
            self::assertSame($expectedHops[$index], $candidate['hops']);
            self::assertSame('0.100000000000000000', $candidate['cost']);
        }

        $signatures = array_map(
            static fn (array $candidate): string => self::routeSignatureFromEdges($candidate['edges']),
            $finalized,
        );

        self::assertSame(
            ['SRC->TRG', 'SRC->ALP->TRG', 'SRC->BET->TRG'],
            $signatures,
        );
    }

    /**
     * @param list<array{from: string, to: string}> $edges
     */
    private static function routeSignatureFromEdges(array $edges): string
    {
        if ([] === $edges) {
            return '';
        }

        $nodes = [$edges[0]['from']];

        foreach ($edges as $edge) {
            $nodes[] = $edge['to'];
        }

        return implode('->', $nodes);
    }

    public function test_it_skips_duplicate_states_with_identical_cost_and_hops(): void
    {
        $graph = [
            'SRC' => [
                'currency' => 'SRC',
                'edges' => [
                    self::manualEdge('SRC', 'MID', '1.500'),
                    self::manualEdge('SRC', 'MID', '1.500'),
                ],
            ],
            'MID' => [
                'currency' => 'MID',
                'edges' => [
                    self::manualEdge('MID', 'TRG', '1.750'),
                ],
            ],
            'TRG' => [
                'currency' => 'TRG',
                'edges' => [],
            ],
        ];

        $finder = new PathFinder(maxHops: 3, tolerance: '0.0', topK: 2);
        $results = self::extractPaths($finder->findBestPaths($graph, 'SRC', 'TRG'));

        self::assertCount(1, $results);
        self::assertSame(2, $results[0]['hops']);

        $path = $results[0]['edges'];
        self::assertSame('SRC', $path[0]['from']);
        self::assertSame('MID', $path[0]['to']);
        self::assertSame('MID', $path[1]['from']);
        self::assertSame('TRG', $path[1]['to']);
    }

    public function test_it_returns_same_paths_when_order_book_is_permuted(): void
    {
        $orders = [
            OrderFactory::sell('USD', 'EUR', '1.000', '1.000', '2.000', 3, 3),
            OrderFactory::sell('GBP', 'EUR', '1.000', '1.000', '4.000', 3, 3),
            OrderFactory::buy('GBP', 'USD', '1.000', '1.000', '2.000', 3, 3),
        ];

        $graph = (new GraphBuilder())->build($orders);
        $finder = new PathFinder(maxHops: 3, tolerance: '0.0', topK: 3);

        $originalResults = self::extractPaths($finder->findBestPaths($graph, 'EUR', 'USD'));

        $permutedGraph = (new GraphBuilder())->build(array_reverse($orders));
        $permutedFinder = new PathFinder(maxHops: 3, tolerance: '0.0', topK: 3);
        $permutedResults = self::extractPaths($permutedFinder->findBestPaths($permutedGraph, 'EUR', 'USD'));

        self::assertSame($originalResults, $permutedResults);
    }

    public function test_it_prunes_paths_that_exceed_updated_tolerance_threshold(): void
    {
        $graph = [
            'RUB' => [
                'currency' => 'RUB',
                'edges' => [
                    self::manualEdge('RUB', 'IDR', '20.000'),
                    self::manualEdge('RUB', 'USD', '30.000'),
                ],
            ],
            'USD' => [
                'currency' => 'USD',
                'edges' => [
                    self::manualEdge('USD', 'JPY', '0.010'),
                    self::manualEdge('USD', 'IDR', '8.000'),
                    self::manualEdge('USD', 'CAD', '2.500'),
                ],
            ],
            'CAD' => [
                'currency' => 'CAD',
                'edges' => [
                    self::manualEdge('CAD', 'IDR', '1.000'),
                ],
            ],
            'IDR' => ['currency' => 'IDR', 'edges' => []],
            'JPY' => ['currency' => 'JPY', 'edges' => []],
        ];

        $finder = new PathFinder(maxHops: 4, tolerance: '0.5', topK: 3);
        $outcome = $finder->findBestPaths($graph, 'RUB', 'IDR');
        $paths = $outcome->paths();

        self::assertCount(2, $paths);

        $first = $paths[0];
        self::assertSame(2, $first['hops']);
        $firstNodes = array_merge(
            [$first['edges'][0]['from']],
            array_map(static fn (array $edge): string => $edge['to'], $first['edges']),
        );
        self::assertSame(['RUB', 'USD', 'IDR'], $firstNodes);
        $expectedTwoHopCost = BcMath::div('1', BcMath::mul('30', '8', self::SCALE), self::SCALE);
        self::assertSame($expectedTwoHopCost, $first['cost']);

        $second = $paths[1];
        self::assertSame(1, $second['hops']);
        $secondNodes = array_merge(
            [$second['edges'][0]['from']],
            array_map(static fn (array $edge): string => $edge['to'], $second['edges']),
        );
        self::assertSame(['RUB', 'IDR'], $secondNodes);
        $expectedDirectCost = BcMath::div('1', '20', self::SCALE);
        self::assertSame($expectedDirectCost, $second['cost']);

        foreach ($paths as $path) {
            $visited = array_map(static fn (array $edge): string => $edge['to'], $path['edges']);
            self::assertNotContains('CAD', $visited);
        }
    }

    public function test_it_tightens_best_cost_and_blocks_looser_candidates(): void
    {
        $graph = [
            'SRC' => [
                'currency' => 'SRC',
                'edges' => [
                    self::manualEdge('SRC', 'TRG', '2.000'),
                    self::manualEdge('SRC', 'DEEP', '1.923'),
                    self::manualEdge('SRC', 'LOOSE', '1.887'),
                ],
            ],
            'DEEP' => [
                'currency' => 'DEEP',
                'edges' => [
                    self::manualEdge('DEEP', 'HIDDEN', '1.050'),
                ],
            ],
            'HIDDEN' => [
                'currency' => 'HIDDEN',
                'edges' => [
                    self::manualEdge('HIDDEN', 'TRG', '2.000'),
                ],
            ],
            'LOOSE' => [
                'currency' => 'LOOSE',
                'edges' => [
                    self::manualEdge('LOOSE', 'TRG', '1.500'),
                ],
            ],
            'TRG' => ['currency' => 'TRG', 'edges' => []],
        ];

        $finder = new PathFinder(maxHops: 4, tolerance: '0.1', topK: 3);
        $evaluatedCandidates = [];
        $outcome = $finder->findBestPaths(
            $graph,
            'SRC',
            'TRG',
            null,
            static function (array $candidate) use (&$evaluatedCandidates): bool {
                $nodes = array_merge(
                    [$candidate['edges'][0]['from']],
                    array_map(static fn (array $edge): string => $edge['to'], $candidate['edges']),
                );

                $evaluatedCandidates[] = ['nodes' => $nodes, 'cost' => $candidate['cost']];

                return true;
            }
        );

        self::assertCount(2, $evaluatedCandidates);

        $paths = $outcome->paths();

        self::assertCount(2, $paths);

        $directFound = false;
        $tightFound = false;
        $collectedNodes = [];

        foreach ($paths as $path) {
            $nodes = array_merge(
                [$path['edges'][0]['from']],
                array_map(static fn (array $edge): string => $edge['to'], $path['edges']),
            );

            $collectedNodes[] = $nodes;

            if (['SRC', 'TRG'] === $nodes) {
                $directFound = true;
                $expectedDirectCost = BcMath::div('1', '2', self::SCALE);
                self::assertSame($expectedDirectCost, $path['cost']);
            }

            if (['SRC', 'DEEP', 'HIDDEN', 'TRG'] === $nodes) {
                $tightFound = true;
                $firstLegCost = BcMath::div('1', '1.923', self::SCALE);
                $secondLegCost = BcMath::div($firstLegCost, '1.050', self::SCALE);
                $expectedHiddenCost = BcMath::div($secondLegCost, '2.000', self::SCALE);
                self::assertSame($expectedHiddenCost, $path['cost']);
            }
        }

        self::assertTrue($directFound);
        self::assertTrue($tightFound);

        foreach ($collectedNodes as $nodes) {
            self::assertNotContains('LOOSE', $nodes);
        }

        $candidateSignatures = array_map(
            static fn (array $candidate): array => $candidate['nodes'],
            $evaluatedCandidates,
        );

        self::assertSame([
            ['SRC', 'TRG'],
            ['SRC', 'DEEP', 'HIDDEN', 'TRG'],
        ], $candidateSignatures);

        self::assertSame([
            ['SRC', 'DEEP', 'HIDDEN', 'TRG'],
            ['SRC', 'TRG'],
        ], $collectedNodes);
    }

    public function test_it_continues_iterating_neighbors_after_tolerance_skip(): void
    {
        $graph = [
            'SRC' => [
                'currency' => 'SRC',
                'edges' => [
                    self::manualEdge('SRC', 'EAR', '2.000'),
                    self::manualEdge('SRC', 'MID', '1.500'),
                ],
            ],
            'EAR' => [
                'currency' => 'EAR',
                'edges' => [
                    self::manualEdge('EAR', 'LNK', '2.000'),
                ],
            ],
            'LNK' => [
                'currency' => 'LNK',
                'edges' => [
                    self::manualEdge('LNK', 'TRG', '2.000'),
                ],
            ],
            'MID' => [
                'currency' => 'MID',
                'edges' => [
                    self::manualEdge('MID', 'BAD', '0.200'),
                    self::manualEdge('MID', 'TRG', '1.200'),
                    self::manualEdge('MID', 'LATE', '0.300'),
                ],
            ],
            'BAD' => ['currency' => 'BAD', 'edges' => []],
            'LATE' => [
                'currency' => 'LATE',
                'edges' => [
                    self::manualEdge('LATE', 'TRG', '1.000'),
                ],
            ],
            'TRG' => ['currency' => 'TRG', 'edges' => []],
        ];

        $finder = new PathFinder(maxHops: 4, tolerance: '0.9', topK: 2);
        $paths = $finder->findBestPaths($graph, 'SRC', 'TRG')->paths();

        self::assertCount(2, $paths);

        $firstNodes = array_merge(
            [$paths[0]['edges'][0]['from']],
            array_map(static fn (array $edge): string => $edge['to'], $paths[0]['edges']),
        );
        self::assertSame(['SRC', 'EAR', 'LNK', 'TRG'], $firstNodes);

        $secondNodes = array_merge(
            [$paths[1]['edges'][0]['from']],
            array_map(static fn (array $edge): string => $edge['to'], $paths[1]['edges']),
        );
        self::assertSame(['SRC', 'MID', 'TRG'], $secondNodes);

        $expectedFirstCost = BcMath::div('1', BcMath::mul('2.000', BcMath::mul('2.000', '2.000', self::SCALE), self::SCALE), self::SCALE);
        self::assertSame($expectedFirstCost, $paths[0]['cost']);

        $expectedSecondCost = BcMath::div('1', BcMath::mul('1.500', '1.200', self::SCALE), self::SCALE);
        self::assertSame($expectedSecondCost, $paths[1]['cost']);

        foreach ($paths as $path) {
            $visitedNodes = array_merge(
                [$path['edges'][0]['from']],
                array_map(static fn (array $edge): string => $edge['to'], $path['edges']),
            );
            self::assertNotContains('LATE', $visitedNodes);
        }
    }

    public function test_it_preserves_best_cost_after_accepting_tolerated_candidate(): void
    {
        $graph = [
            'SRC' => [
                'currency' => 'SRC',
                'edges' => [
                    self::manualEdge('SRC', 'EAR', '2.000'),
                    self::manualEdge('SRC', 'MID', '1.500'),
                ],
            ],
            'EAR' => [
                'currency' => 'EAR',
                'edges' => [
                    self::manualEdge('EAR', 'LNK', '2.000'),
                ],
            ],
            'LNK' => [
                'currency' => 'LNK',
                'edges' => [
                    self::manualEdge('LNK', 'TRG', '2.000'),
                ],
            ],
            'MID' => [
                'currency' => 'MID',
                'edges' => [
                    self::manualEdge('MID', 'BAD', '0.200'),
                    self::manualEdge('MID', 'TRG', '1.200'),
                    self::manualEdge('MID', 'LATE', '0.300'),
                ],
            ],
            'BAD' => ['currency' => 'BAD', 'edges' => []],
            'LATE' => [
                'currency' => 'LATE',
                'edges' => [
                    self::manualEdge('LATE', 'TRG', '1.000'),
                ],
            ],
            'TRG' => ['currency' => 'TRG', 'edges' => []],
        ];

        $finder = new PathFinder(maxHops: 4, tolerance: '0.9', topK: 3);
        $paths = $finder->findBestPaths($graph, 'SRC', 'TRG')->paths();

        self::assertCount(2, $paths);

        $firstNodes = array_merge(
            [$paths[0]['edges'][0]['from']],
            array_map(static fn (array $edge): string => $edge['to'], $paths[0]['edges']),
        );
        self::assertSame(['SRC', 'EAR', 'LNK', 'TRG'], $firstNodes);

        $secondNodes = array_merge(
            [$paths[1]['edges'][0]['from']],
            array_map(static fn (array $edge): string => $edge['to'], $paths[1]['edges']),
        );
        self::assertSame(['SRC', 'MID', 'TRG'], $secondNodes);

        $expectedFirstCost = BcMath::div('1', BcMath::mul('2.000', BcMath::mul('2.000', '2.000', self::SCALE), self::SCALE), self::SCALE);
        self::assertSame($expectedFirstCost, $paths[0]['cost']);

        $expectedSecondCost = BcMath::div('1', BcMath::mul('1.500', '1.200', self::SCALE), self::SCALE);
        self::assertSame($expectedSecondCost, $paths[1]['cost']);

        foreach ($paths as $path) {
            $visitedNodes = array_merge(
                [$path['edges'][0]['from']],
                array_map(static fn (array $edge): string => $edge['to'], $path['edges']),
            );
            self::assertNotContains('LATE', $visitedNodes);
        }
    }

    public function test_it_updates_best_cost_after_discovering_a_better_candidate(): void
    {
        $graph = [
            'SRC' => [
                'currency' => 'SRC',
                'edges' => [
                    self::manualEdge('SRC', 'TRG', '2.000'),
                    self::manualEdge('SRC', 'MOD', '1.500'),
                    self::manualEdge('SRC', 'BST', '1.100'),
                    self::manualEdge('SRC', 'LATE', '1.050'),
                ],
            ],
            'MOD' => [
                'currency' => 'MOD',
                'edges' => [
                    self::manualEdge('MOD', 'TRG', '2.000'),
                ],
            ],
            'BST' => [
                'currency' => 'BST',
                'edges' => [
                    self::manualEdge('BST', 'TRG', '10.000'),
                ],
            ],
            'LATE' => [
                'currency' => 'LATE',
                'edges' => [
                    self::manualEdge('LATE', 'TRG', '2.381'),
                ],
            ],
            'TRG' => ['currency' => 'TRG', 'edges' => []],
        ];

        $finder = new PathFinder(maxHops: 2, tolerance: '0.0', topK: 3);
        $outcome = $finder->findBestPaths($graph, 'SRC', 'TRG');
        $paths = $outcome->paths();

        self::assertCount(3, $paths);

        $directPathFound = false;

        foreach ($paths as $path) {
            $nodes = array_merge(
                [$path['edges'][0]['from']],
                array_map(static fn (array $edge): string => $edge['to'], $path['edges']),
            );

            self::assertNotContains('LATE', $nodes);

            if (1 === $path['hops']) {
                $directPathFound = true;
                self::assertSame(['SRC', 'TRG'], $nodes);
            }
        }

        self::assertTrue($directPathFound);
    }

    public function test_it_continues_evaluating_edges_after_missing_neighbor(): void
    {
        $graph = [
            'SRC' => [
                'currency' => 'SRC',
                'edges' => [
                    self::manualEdge('SRC', 'MIA', '1.500'),
                    self::manualEdge('SRC', 'TRG', '2.000'),
                ],
            ],
            'TRG' => ['currency' => 'TRG', 'edges' => []],
        ];

        $finder = new PathFinder(maxHops: 1, tolerance: '0.0', topK: 1);
        $outcome = $finder->findBestPaths($graph, 'SRC', 'TRG');
        $paths = $outcome->paths();

        self::assertCount(1, $paths);

        $nodes = array_merge(
            [$paths[0]['edges'][0]['from']],
            array_map(static fn (array $edge): string => $edge['to'], $paths[0]['edges']),
        );

        self::assertSame(['SRC', 'TRG'], $nodes);
    }

    public function test_it_ignores_non_positive_conversion_edges_without_aborting_neighbor_iteration(): void
    {
        $invalidEdge = self::manualEdge('SRC', 'NEG', '1.500');
        $zeroSrc = Money::zero('SRC', 3);
        $zeroNeg = Money::zero('NEG', 3);

        $invalidEdge['grossBaseCapacity']['max'] = $zeroSrc;
        $invalidEdge['baseCapacity']['max'] = $zeroSrc;
        $invalidEdge['quoteCapacity']['max'] = $zeroNeg;
        $invalidEdge['segments'][0]['grossBase']['max'] = $zeroSrc;
        $invalidEdge['segments'][0]['base']['max'] = $zeroSrc;
        $invalidEdge['segments'][0]['quote']['max'] = $zeroNeg;

        $graph = [
            'SRC' => [
                'currency' => 'SRC',
                'edges' => [
                    $invalidEdge,
                    self::manualEdge('SRC', 'TRG', '2.000'),
                ],
            ],
            'NEG' => ['currency' => 'NEG', 'edges' => []],
            'TRG' => ['currency' => 'TRG', 'edges' => []],
        ];

        $finder = new PathFinder(maxHops: 1, tolerance: '0.0', topK: 1);
        $outcome = $finder->findBestPaths($graph, 'SRC', 'TRG');
        $paths = $outcome->paths();

        self::assertCount(1, $paths);

        $nodes = array_merge(
            [$paths[0]['edges'][0]['from']],
            array_map(static fn (array $edge): string => $edge['to'], $paths[0]['edges']),
        );

        self::assertSame(['SRC', 'TRG'], $nodes);
    }

    public function test_it_continues_processing_queue_after_skipping_hop_limited_state(): void
    {
        $graph = [
            'SRC' => [
                'currency' => 'SRC',
                'edges' => [
                    self::manualEdge('SRC', 'BLK', '5.000'),
                    self::manualEdge('SRC', 'HLP', '1.500'),
                ],
            ],
            'BLK' => [
                'currency' => 'BLK',
                'edges' => [
                    self::manualEdge('BLK', 'DED', '5.000'),
                ],
            ],
            'HLP' => [
                'currency' => 'HLP',
                'edges' => [
                    self::manualEdge('HLP', 'TRG', '1.100'),
                ],
            ],
            'DED' => ['currency' => 'DED', 'edges' => []],
            'TRG' => ['currency' => 'TRG', 'edges' => []],
        ];

        $finder = new PathFinder(maxHops: 2, tolerance: '0.0', topK: 1);
        $outcome = $finder->findBestPaths($graph, 'SRC', 'TRG');

        $paths = $outcome->paths();

        self::assertCount(1, $paths);
        $path = $paths[0];
        self::assertSame(2, $path['hops']);

        $nodes = array_merge(
            [$path['edges'][0]['from']],
            array_map(static fn (array $edge): string => $edge['to'], $path['edges']),
        );

        self::assertSame(['SRC', 'HLP', 'TRG'], $nodes);
    }

    public function test_it_respects_visited_state_guard_threshold(): void
    {
        $graph = [
            'SRC' => [
                'currency' => 'SRC',
                'edges' => [
                    self::manualEdge('SRC', 'TRG', '1.100'),
                    self::manualEdge('SRC', 'ALP', '1.200'),
                    self::manualEdge('SRC', 'BET', '1.300'),
                ],
            ],
            'ALP' => [
                'currency' => 'ALP',
                'edges' => [
                    self::manualEdge('ALP', 'LFK', '1.050'),
                ],
            ],
            'BET' => [
                'currency' => 'BET',
                'edges' => [
                    self::manualEdge('BET', 'LFK', '1.050'),
                ],
            ],
            'LFK' => ['currency' => 'LFK', 'edges' => []],
            'TRG' => ['currency' => 'TRG', 'edges' => []],
        ];

        $finder = new PathFinder(maxHops: 2, tolerance: '0.0', topK: 1, maxVisitedStates: 2);
        $outcome = $finder->findBestPaths($graph, 'SRC', 'TRG');

        $paths = $outcome->paths();
        self::assertNotSame([], $paths);
        self::assertTrue($outcome->guardLimits()->visitedStatesReached());
    }

    public function test_it_keeps_processing_guarded_states_with_matching_signatures(): void
    {
        $graph = [
            'SRC' => [
                'currency' => 'SRC',
                'edges' => [
                    self::manualEdge('SRC', 'ALT', '2.000'),
                    self::manualEdge('SRC', 'MID', '1.000'),
                ],
            ],
            'ALT' => [
                'currency' => 'ALT',
                'edges' => [
                    self::manualEdge('ALT', 'AUX', '1.000'),
                ],
            ],
            'AUX' => [
                'currency' => 'AUX',
                'edges' => [
                    self::manualEdge('AUX', 'TRG', '1.000'),
                ],
            ],
            'MID' => [
                'currency' => 'MID',
                'edges' => [
                    self::manualEdge('MID', 'SKP', '1.000'),
                    self::manualEdge('MID', 'TRG', '2.000'),
                ],
            ],
            'SKP' => ['currency' => 'SKP', 'edges' => []],
            'TRG' => ['currency' => 'TRG', 'edges' => []],
        ];

        $finder = new PathFinder(maxHops: 4, tolerance: '0.0', topK: 2, maxVisitedStates: 5);
        $outcome = $finder->findBestPaths($graph, 'SRC', 'TRG');

        $paths = $outcome->paths();

        self::assertCount(2, $paths);

        $firstNodes = array_merge(
            [$paths[0]['edges'][0]['from']],
            array_map(static fn (array $edge): string => $edge['to'], $paths[0]['edges']),
        );
        self::assertSame(['SRC', 'MID', 'TRG'], $firstNodes);

        $secondNodes = array_merge(
            [$paths[1]['edges'][0]['from']],
            array_map(static fn (array $edge): string => $edge['to'], $paths[1]['edges']),
        );
        self::assertSame(['SRC', 'ALT', 'AUX', 'TRG'], $secondNodes);

        self::assertTrue($outcome->guardLimits()->visitedStatesReached());
    }

    public function test_it_does_not_accept_cycles_that_improve_conversion_costs(): void
    {
        $graph = [
            'SRC' => [
                'currency' => 'SRC',
                'edges' => [
                    self::manualEdge('SRC', 'TRG', '2.000'),
                    self::manualEdge('SRC', 'LOP', '2.000'),
                ],
            ],
            'LOP' => [
                'currency' => 'LOP',
                'edges' => [
                    self::manualEdge('LOP', 'SRC', '2.000'),
                ],
            ],
            'TRG' => ['currency' => 'TRG', 'edges' => []],
        ];

        $finder = new PathFinder(maxHops: 4, tolerance: '0.0', topK: 1);
        $outcome = $finder->findBestPaths($graph, 'SRC', 'TRG');

        $paths = $outcome->paths();
        self::assertCount(1, $paths);

        $best = $paths[0];
        self::assertSame(1, $best['hops']);
        self::assertSame('TRG', $best['edges'][0]['to']);
    }

    public function test_it_normalizes_endpoint_case_inputs(): void
    {
        $orders = self::buildComprehensiveOrderBook();
        $graph = (new GraphBuilder())->build($orders);

        $finder = new PathFinder(maxHops: 3, tolerance: '0.0');
        $results = self::extractPaths($finder->findBestPaths($graph, 'rub', 'idr'));

        self::assertNotSame([], $results);
    }

    public function test_it_returns_empty_when_target_missing_from_graph(): void
    {
        $orders = self::buildComprehensiveOrderBook();
        $graph = (new GraphBuilder())->build($orders);

        $finder = new PathFinder(maxHops: 3, tolerance: '0.0');

        self::assertSame([], self::extractPaths($finder->findBestPaths($graph, 'RUB', 'ZZZ')));
        self::assertSame([], self::extractPaths($finder->findBestPaths($graph, 'zzz', 'IDR')));
    }

    public function test_it_avoids_cycles_that_return_to_the_source(): void
    {
        $orders = [
            OrderFactory::sell('EUR', 'USD', '1.000', '1.000', '1.000', 3, 3),
            OrderFactory::sell('USD', 'EUR', '1.000', '1.000', '1.000', 3, 3),
            OrderFactory::sell('JPY', 'EUR', '1.000', '1.000', '1.000', 3, 3),
            OrderFactory::sell('JPY', 'USD', '1.000', '1.000', '1.000', 3, 3),
        ];

        $graph = (new GraphBuilder())->build($orders);
        $finder = new PathFinder(maxHops: 4, tolerance: '0.0', topK: 4);

        $paths = $finder->findBestPaths($graph, 'USD', 'JPY')->paths();

        self::assertNotSame([], $paths);

        foreach ($paths as $candidate) {
            $visited = ['USD'];
            foreach ($candidate['edges'] as $edge) {
                self::assertFalse(
                    in_array($edge['to'], $visited, true),
                    'Path finder should not revisit previously seen nodes.'
                );
                $visited[] = $edge['to'];
            }
        }
    }

    public function test_it_continues_search_when_callback_rejects_initial_target_candidate(): void
    {
        $orders = [
            OrderFactory::sell('USD', 'EUR', '10.000', '500.000', '0.900', 3, 3),
            OrderFactory::sell('GBP', 'EUR', '10.000', '500.000', '0.850', 3, 3),
            OrderFactory::buy('GBP', 'USD', '10.000', '500.000', '0.900', 3, 3),
        ];

        $graph = (new GraphBuilder())->build($orders);
        $finder = new PathFinder(maxHops: 3, tolerance: '0.0', topK: 3);

        $visitedCandidates = [];
        $spend = Money::fromString('EUR', '100.00', 2);
        $searchResult = $finder->findBestPaths(
            $graph,
            'EUR',
            'USD',
            [
                'min' => $spend,
                'max' => $spend,
                'desired' => $spend,
            ],
            static function (array $candidate) use (&$visitedCandidates): bool {
                $visitedCandidates[] = $candidate;

                return $candidate['hops'] >= 2;
            },
        );

        $results = $searchResult->paths();

        self::assertNotSame([], $results);
        self::assertGreaterThanOrEqual(2, count($visitedCandidates));

        $firstCandidate = $visitedCandidates[0];
        self::assertSame(1, $firstCandidate['hops']);
        self::assertCount(1, $firstCandidate['edges']);
        self::assertSame('EUR', $firstCandidate['edges'][0]['from']);
        self::assertSame('USD', $firstCandidate['edges'][0]['to']);

        $acceptedCandidate = $visitedCandidates[array_key_last($visitedCandidates)];
        self::assertSame(2, $acceptedCandidate['hops']);

        $best = $results[0];
        self::assertSame(2, $best['hops']);
        self::assertCount(2, $best['edges']);
        self::assertSame('EUR', $best['edges'][0]['from']);
        self::assertSame('GBP', $best['edges'][0]['to']);
        self::assertSame('GBP', $best['edges'][1]['from']);
        self::assertSame('USD', $best['edges'][1]['to']);

        foreach ($results as $result) {
            self::assertGreaterThanOrEqual(2, $result['hops']);
        }
    }

    /**
     * @return iterable<string, array{int, string, int, list<array{from: string, to: string}>, string}>
     */
    public static function provideRubToIdrConstraintScenarios(): iterable
    {
        yield 'direct_route_only' => [
            1,
            '0.0',
            1,
            [
                ['from' => 'RUB', 'to' => 'IDR'],
            ],
            BcMath::normalize('165.000', self::SCALE),
        ];

        $rubToUsd = BcMath::div('1', '90.500', self::SCALE);
        $twoHopProduct = BcMath::mul(
            $rubToUsd,
            BcMath::normalize('15400.000', self::SCALE),
            self::SCALE,
        );
        yield 'two_hop_best_path_with_strict_tolerance' => [
            2,
            '0.0',
            2,
            [
                ['from' => 'RUB', 'to' => 'USD'],
                ['from' => 'USD', 'to' => 'IDR'],
            ],
            $twoHopProduct,
        ];

        yield 'two_hop_best_path_with_relaxed_tolerance' => [
            2,
            '0.12',
            2,
            [
                ['from' => 'RUB', 'to' => 'USD'],
                ['from' => 'USD', 'to' => 'IDR'],
            ],
            $twoHopProduct,
        ];

        $threeHopProduct = BcMath::mul(
            BcMath::mul(
                $rubToUsd,
                BcMath::normalize('149.500', self::SCALE),
                self::SCALE,
            ),
            BcMath::normalize('112.750', self::SCALE),
            self::SCALE,
        );
        yield 'three_hop_path_outperforms_direct_conversion' => [
            3,
            '0.995',
            3,
            [
                ['from' => 'RUB', 'to' => 'USD'],
                ['from' => 'USD', 'to' => 'JPY'],
                ['from' => 'JPY', 'to' => 'IDR'],
            ],
            $threeHopProduct,
        ];
    }

    /**
     * @dataProvider provideImpossibleScenarios
     */
    public function test_it_returns_null_when_no_viable_path(
        array $orders,
        int $maxHops,
        string $tolerance,
        string $source,
        string $target
    ): void {
        $graph = (new GraphBuilder())->build($orders);

        $finder = new PathFinder($maxHops, $tolerance);
        $result = $finder->findBestPaths($graph, $source, $target);

        self::assertSame([], $result->paths());
        self::assertFalse($result->guardLimits()->expansionsReached());
        self::assertFalse($result->guardLimits()->visitedStatesReached());
    }

    /**
     * @return iterable<string, array{list<Order>, int, string, string, string}>
     */
    public static function provideImpossibleScenarios(): iterable
    {
        yield 'missing_second_leg' => [
            self::createRubToUsdSellOrders(),
            3,
            '0.05',
            'RUB',
            'IDR',
        ];

        $withoutDirectEdge = array_merge(
            self::createRubToUsdSellOrders(),
            self::createUsdToIdrBuyOrders(),
            self::createMultiHopSupplement(),
        );
        yield 'hop_budget_too_strict' => [
            $withoutDirectEdge,
            1,
            '0.0',
            'RUB',
            'IDR',
        ];

        yield 'missing_source_currency' => [
            self::buildComprehensiveOrderBook(),
            3,
            '0.0',
            'GBP',
            'IDR',
        ];

        yield 'missing_target_currency' => [
            self::buildComprehensiveOrderBook(),
            3,
            '0.0',
            'RUB',
            'CHF',
        ];
    }

    /**
     * @dataProvider provideSingleLegMarkets
     */
    public function test_it_handles_single_leg_markets(
        array $orders,
        string $source,
        string $target,
        string $expectedProduct
    ): void {
        $graph = (new GraphBuilder())->build($orders);

        $finder = new PathFinder(maxHops: 2, tolerance: '0.05');
        $results = self::extractPaths($finder->findBestPaths($graph, $source, $target));

        self::assertNotSame([], $results);
        $result = $results[0];

        self::assertSame(1, $result['hops']);
        self::assertCount(1, $result['edges']);
        self::assertSame($source, $result['edges'][0]['from']);
        self::assertSame($target, $result['edges'][0]['to']);
        self::assertSame($expectedProduct, $result['product']);
    }

    /**
     * @return iterable<string, array{list<Order>, string, string, string}>
     */
    public static function provideSingleLegMarkets(): iterable
    {
        yield 'rub_to_usd_order_book' => [
            self::createRubToUsdSellOrders(),
            'RUB',
            'USD',
            BcMath::div('1', '90.500', self::SCALE),
        ];

        yield 'usd_to_idr_order_book' => [
            self::createUsdToIdrBuyOrders(),
            'USD',
            'IDR',
            BcMath::normalize('15400.000', self::SCALE),
        ];
    }

    /**
     * @dataProvider provideMalformedSpendConstraints
     */
    public function test_it_requires_spend_constraints_to_include_bounds(array $constraints): void
    {
        $orders = self::createRubToUsdSellOrders();
        $graph = (new GraphBuilder())->build($orders);

        $finder = new PathFinder(maxHops: 1, tolerance: '0.0');

        $this->expectException(InvalidInput::class);
        $finder->findBestPaths($graph, 'RUB', 'USD', $constraints);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideMalformedSpendConstraints(): iterable
    {
        $minimum = CurrencyScenarioFactory::money('RUB', '1.000', 3);
        $maximum = CurrencyScenarioFactory::money('RUB', '5.000', 3);

        yield 'missing_minimum' => [
            [
                'max' => $maximum,
            ],
        ];

        yield 'missing_maximum' => [
            [
                'min' => $minimum,
            ],
        ];

        yield 'desired_without_bounds' => [
            [
                'desired' => $minimum,
            ],
        ];
    }

    /**
     * @dataProvider provideSpendConstraintsMissingBounds
     */
    public function test_it_throws_when_one_of_the_spend_bounds_is_missing(array $constraints): void
    {
        $order = OrderFactory::buy('EUR', 'USD', '1.000', '1.000', '1.100', 3, 3);
        $graph = (new GraphBuilder())->build([$order]);

        $finder = new PathFinder(maxHops: 1, tolerance: '0.0');

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Spend constraints must include both minimum and maximum bounds.');

        $finder->findBestPaths($graph, 'EUR', 'USD', $constraints);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideSpendConstraintsMissingBounds(): iterable
    {
        $minimum = CurrencyScenarioFactory::money('EUR', '1.000', 3);
        $maximum = CurrencyScenarioFactory::money('EUR', '1.000', 3);

        yield 'missing_minimum' => [
            [
                'max' => $maximum,
            ],
        ];

        yield 'missing_maximum' => [
            [
                'min' => $minimum,
            ],
        ];
    }

    public function test_it_accepts_spend_constraints_with_both_bounds(): void
    {
        $order = OrderFactory::buy('EUR', 'USD', '1.000', '1.000', '1.100', 3, 3);
        $graph = (new GraphBuilder())->build([$order]);

        $finder = new PathFinder(maxHops: 1, tolerance: '0.0');

        $minimum = CurrencyScenarioFactory::money('EUR', '1.000', 3);
        $maximum = CurrencyScenarioFactory::money('EUR', '1.000', 3);

        $results = self::extractPaths($finder->findBestPaths(
            $graph,
            'EUR',
            'USD',
            [
                'min' => $minimum,
                'max' => $maximum,
            ],
        ));

        self::assertNotSame([], $results);
        $result = $results[0];

        self::assertSame(1, $result['hops']);
        self::assertCount(1, $result['edges']);
        self::assertSame('EUR', $result['edges'][0]['from']);
        self::assertSame('USD', $result['edges'][0]['to']);

        self::assertNotNull($result['amountRange']);
        self::assertSame('USD', $result['amountRange']['min']->currency());
        self::assertSame($result['amountRange']['min']->currency(), $result['amountRange']['max']->currency());
        self::assertSame($result['amountRange']['min']->amount(), $result['amountRange']['max']->amount());
    }

    public function test_it_supports_spend_constraints_without_desired_amount(): void
    {
        $order = OrderFactory::buy('EUR', 'USD', '1.000', '1.000', '1.100', 3, 3);
        $graph = (new GraphBuilder())->build([$order]);

        $finder = new PathFinder(maxHops: 1, tolerance: '0.0');

        $minimum = CurrencyScenarioFactory::money('EUR', '1.000', 3);
        $maximum = CurrencyScenarioFactory::money('EUR', '1.000', 3);

        $results = self::extractPaths($finder->findBestPaths(
            $graph,
            'EUR',
            'USD',
            [
                'min' => $minimum,
                'max' => $maximum,
            ],
        ));

        self::assertNotSame([], $results);
        $result = $results[0];

        self::assertSame(1, $result['hops']);
        self::assertCount(1, $result['edges']);
        self::assertSame('EUR', $result['edges'][0]['from']);
        self::assertSame('USD', $result['edges'][0]['to']);

        self::assertNotNull($result['amountRange']);
        self::assertSame('USD', $result['amountRange']['min']->currency());
        self::assertSame($result['amountRange']['min']->currency(), $result['amountRange']['max']->currency());
        self::assertSame($result['amountRange']['min']->amount(), $result['amountRange']['max']->amount());
        self::assertNull($result['desiredAmount']);
    }

    public function test_it_supports_spend_constraints_with_desired_amount(): void
    {
        $order = OrderFactory::buy('EUR', 'USD', '1.000', '1.000', '1.100', 3, 3);
        $graph = (new GraphBuilder())->build([$order]);

        $finder = new PathFinder(maxHops: 1, tolerance: '0.0');

        $minimum = CurrencyScenarioFactory::money('EUR', '1.000', 3);
        $maximum = CurrencyScenarioFactory::money('EUR', '1.000', 3);
        $desired = CurrencyScenarioFactory::money('EUR', '1.000', 3);

        $results = self::extractPaths($finder->findBestPaths(
            $graph,
            'EUR',
            'USD',
            [
                'min' => $minimum,
                'max' => $maximum,
                'desired' => $desired,
            ],
        ));

        self::assertNotSame([], $results);
        $result = $results[0];

        self::assertSame(1, $result['hops']);
        self::assertCount(1, $result['edges']);
        self::assertSame('EUR', $result['edges'][0]['from']);
        self::assertSame('USD', $result['edges'][0]['to']);

        self::assertNotNull($result['amountRange']);
        self::assertSame('USD', $result['amountRange']['min']->currency());
        self::assertSame($result['amountRange']['min']->currency(), $result['amountRange']['max']->currency());
        self::assertSame($result['amountRange']['min']->amount(), $result['amountRange']['max']->amount());
        self::assertNotNull($result['desiredAmount']);
        self::assertSame($result['amountRange']['min']->currency(), $result['desiredAmount']->currency());
        self::assertSame($result['amountRange']['min']->amount(), $result['desiredAmount']->amount());
    }

    public function test_it_clamps_desired_spend_into_feasible_range_before_conversion(): void
    {
        $order = OrderFactory::buy('EUR', 'USD', '1.000', '5.000', '1.200', 3, 3);
        $graph = (new GraphBuilder())->build([$order]);

        $finder = new PathFinder(maxHops: 1, tolerance: '0.0');

        $minimum = CurrencyScenarioFactory::money('EUR', '1.000', 3);
        $maximum = CurrencyScenarioFactory::money('EUR', '3.000', 3);
        $desired = CurrencyScenarioFactory::money('EUR', '10.000', 3);

        $results = self::extractPaths($finder->findBestPaths(
            $graph,
            'EUR',
            'USD',
            [
                'min' => $minimum,
                'max' => $maximum,
                'desired' => $desired,
            ],
        ));

        self::assertNotSame([], $results);
        $result = $results[0];

        self::assertNotNull($result['desiredAmount']);

        $edge = $graph['EUR']['edges'][0];
        $convertMethod = new ReflectionMethod(PathFinder::class, 'convertEdgeAmount');
        $convertMethod->setAccessible(true);
        $expectedDesired = $convertMethod->invoke($finder, $edge, $maximum);

        self::assertSame($expectedDesired->currency(), $result['desiredAmount']->currency());
        self::assertSame($expectedDesired->amount(), $result['desiredAmount']->amount());
    }

    public function test_it_preserves_parallel_states_with_distinct_ranges(): void
    {
        $limitedCapacity = OrderFactory::sell('MID', 'SRC', '10.000', '15.000', '1.000', 3, 3);
        $sufficientCapacity = OrderFactory::sell('MID', 'SRC', '10.000', '20.000', '1.000', 3, 3);
        $finalLeg = OrderFactory::sell('DST', 'MID', '18.000', '25.000', '1.000', 3, 3);

        $graph = (new GraphBuilder())->build([
            $limitedCapacity,
            $sufficientCapacity,
            $finalLeg,
        ]);

        $finder = new PathFinder(maxHops: 2, tolerance: '0.0');

        $minSpend = CurrencyScenarioFactory::money('SRC', '10.000', 3);
        $maxSpend = CurrencyScenarioFactory::money('SRC', '20.000', 3);

        $results = self::extractPaths($finder->findBestPaths(
            $graph,
            'SRC',
            'DST',
            [
                'min' => $minSpend,
                'max' => $maxSpend,
            ],
        ));

        self::assertNotSame([], $results);
        $result = $results[0];

        self::assertSame(2, $result['hops']);
        self::assertCount(2, $result['edges']);

        self::assertSame('SRC', $result['edges'][0]['from']);
        self::assertSame('MID', $result['edges'][0]['to']);
        self::assertSame($sufficientCapacity, $result['edges'][0]['order']);

        self::assertSame('MID', $result['edges'][1]['from']);
        self::assertSame('DST', $result['edges'][1]['to']);
        self::assertSame($finalLeg, $result['edges'][1]['order']);

        self::assertNotNull($result['amountRange']);
        self::assertSame('DST', $result['amountRange']['min']->currency());
        self::assertSame('DST', $result['amountRange']['max']->currency());
        self::assertSame('18.000000000000000000', $result['amountRange']['min']->amount());
        self::assertSame('20.000000000000000000', $result['amountRange']['max']->amount());
    }

    /**
     * @dataProvider provideSpendBelowMandatoryMinimum
     */
    public function test_it_prunes_paths_below_mandatory_minimum(
        Order $order,
        string $source,
        string $target,
        Money $desiredSpend
    ): void {
        $graph = (new GraphBuilder())->build([$order]);

        $finder = new PathFinder(maxHops: 1, tolerance: '0.0');
        $accepted = false;

        $searchResult = $finder->findBestPaths(
            $graph,
            $source,
            $target,
            [
                'min' => $desiredSpend,
                'max' => $desiredSpend,
                'desired' => $desiredSpend,
            ],
            static function () use (&$accepted): bool {
                $accepted = true;

                return true;
            },
        );

        self::assertSame([], $searchResult->paths());
        self::assertFalse($searchResult->guardLimits()->expansionsReached());
        self::assertFalse($searchResult->guardLimits()->visitedStatesReached());
        self::assertFalse($accepted);
    }

    /**
     * @return iterable<string, array{Order, string, string, Money}>
     */
    public static function provideSpendBelowMandatoryMinimum(): iterable
    {
        yield 'buy_edge_below_base_minimum' => [
            OrderFactory::buy('EUR', 'USD', '10.000', '100.000', '1.050', 3, 3),
            'EUR',
            'USD',
            CurrencyScenarioFactory::money('EUR', '9.999', 3),
        ];

        yield 'buy_edge_below_gross_base_requirement' => [
            OrderFactory::buy(
                'EUR',
                'USD',
                '10.000',
                '100.000',
                '1.050',
                3,
                3,
                self::basePercentageFeePolicy('0.02'),
            ),
            'EUR',
            'USD',
            CurrencyScenarioFactory::money('EUR', '10.150', 3),
        ];

        yield 'sell_edge_below_quote_minimum' => [
            OrderFactory::sell('EUR', 'USD', '10.000', '100.000', '1.050', 3, 3),
            'USD',
            'EUR',
            CurrencyScenarioFactory::money('USD', '10.499', 3),
        ];
    }

    public function test_it_prefers_profitable_multi_leg_route_with_mixed_order_sides(): void
    {
        $orders = array_merge(
            self::createUsdToEurDirectOrders(),
            self::createUsdToEthSellOrders(),
            self::createEthToEurBuyOrders(),
        );

        $graph = (new GraphBuilder())->build($orders);

        $finder = new PathFinder(maxHops: 3, tolerance: '0.0');
        $results = self::extractPaths($finder->findBestPaths($graph, 'USD', 'EUR'));

        self::assertNotSame([], $results);
        $result = $results[0];

        self::assertSame(2, $result['hops']);
        self::assertCount(2, $result['edges']);

        $expectedProduct = BcMath::mul(
            BcMath::div('1', '1800.00', self::SCALE),
            BcMath::normalize('1700.00', self::SCALE),
            self::SCALE,
        );
        self::assertSame($expectedProduct, $result['product']);

        self::assertSame('USD', $result['edges'][0]['from']);
        self::assertSame('ETH', $result['edges'][0]['to']);
        self::assertSame(OrderSide::SELL, $result['edges'][0]['orderSide']);

        self::assertSame('ETH', $result['edges'][1]['from']);
        self::assertSame('EUR', $result['edges'][1]['to']);
        self::assertSame(OrderSide::BUY, $result['edges'][1]['orderSide']);

        self::assertSame(1, BcMath::comp($result['product'], BcMath::normalize('0.92', self::SCALE), self::SCALE));
    }

    public function test_it_accounts_for_gross_base_when_scoring_buy_edges(): void
    {
        $order = OrderFactory::buy(
            'EUR',
            'USD',
            '1.000',
            '1.000',
            '1.100',
            3,
            3,
            self::basePercentageFeePolicy('0.05'),
        );

        $graph = (new GraphBuilder())->build([$order]);

        $finder = new PathFinder(maxHops: 1, tolerance: '0.0');
        $grossSpend = CurrencyScenarioFactory::money('EUR', '1.050', 3);

        $results = self::extractPaths($finder->findBestPaths(
            $graph,
            'EUR',
            'USD',
            [
                'min' => $grossSpend,
                'max' => $grossSpend,
                'desired' => $grossSpend,
            ],
        ));

        self::assertNotSame([], $results);
        $result = $results[0];

        self::assertSame(1, $result['hops']);
        self::assertCount(1, $result['edges']);

        $expectedProduct = BcMath::div('1.100', '1.050', self::SCALE);
        self::assertSame($expectedProduct, $result['product']);
        self::assertSame(BcMath::div('1', $expectedProduct, self::SCALE), $result['cost']);
        self::assertSame($expectedProduct, $result['edges'][0]['conversionRate']);
    }

    /**
     * Demonstrates how a single-leg buy path with simultaneous base surcharges and quote deductions alters the conversion rate used for tolerance math.
     */
    public function test_it_accounts_for_combined_base_and_quote_fees_when_scoring_buy_edges(): void
    {
        $order = OrderFactory::createOrder(
            OrderSide::BUY,
            'EUR',
            'USD',
            '10.000',
            '10.000',
            '1.200',
            amountScale: 3,
            rateScale: 3,
            feePolicy: self::mixedPercentageFeePolicy('0.02', '0.05'),
        );

        $graph = (new GraphBuilder())->build([$order]);

        $finder = new PathFinder(maxHops: 1, tolerance: '0.10');
        $results = self::extractPaths($finder->findBestPaths($graph, 'EUR', 'USD'));

        self::assertNotSame([], $results);
        $result = $results[0];

        self::assertSame(1, $result['hops']);
        self::assertCount(1, $result['edges']);
        self::assertSame('EUR', $result['edges'][0]['from']);
        self::assertSame('USD', $result['edges'][0]['to']);

        $expectedProduct = BcMath::div('11.400', '10.200', self::SCALE);
        $expectedCost = BcMath::div('1', $expectedProduct, self::SCALE);

        self::assertSame($expectedProduct, $result['product']);
        self::assertSame($expectedCost, $result['cost']);
        self::assertSame($expectedProduct, $result['edges'][0]['conversionRate']);
    }

    public function test_it_returns_zero_hop_path_when_source_equals_target(): void
    {
        $orders = self::buildComprehensiveOrderBook();
        $graph = (new GraphBuilder())->build($orders);

        $finder = new PathFinder(maxHops: 3, tolerance: '0.15');
        $results = self::extractPaths($finder->findBestPaths($graph, 'USD', 'USD'));

        self::assertNotSame([], $results);
        $result = $results[0];

        self::assertSame(0, $result['hops']);
        self::assertSame([], $result['edges']);
        self::assertSame(BcMath::normalize('1', self::SCALE), $result['product']);
        self::assertSame(BcMath::normalize('1', self::SCALE), $result['cost']);
    }

    /**
     * @param list<Order> $orders
     *
     * @dataProvider provideExtremeRateScenarios
     */
    public function test_it_remains_deterministic_with_extreme_rate_scales(
        array $orders,
        string $source,
        string $target,
        string $expectedProduct,
        int $expectedHops
    ): void {
        $graph = (new GraphBuilder())->build($orders);

        $finder = new PathFinder(maxHops: 3, tolerance: '0.0');
        $first = $finder->findBestPaths($graph, $source, $target);
        $second = $finder->findBestPaths($graph, $source, $target);

        self::assertTrue($first->hasPaths());
        self::assertTrue($second->hasPaths());

        self::assertSame($first->paths(), $second->paths(), 'Extreme rate scenarios should produce deterministic outcomes.');
        self::assertSame(
            $first->guardLimits()->expansionsReached(),
            $second->guardLimits()->expansionsReached(),
            'Extreme rate scenarios should produce deterministic guard exhaustion state.',
        );
        self::assertSame(
            $first->guardLimits()->visitedStatesReached(),
            $second->guardLimits()->visitedStatesReached(),
            'Extreme rate scenarios should produce deterministic guard exhaustion state.',
        );

        $best = $first->paths()[0];
        self::assertSame($expectedHops, $best['hops']);
        self::assertSame($expectedProduct, $best['product']);
        $expectedCost = BcMath::div('1', $expectedProduct, self::SCALE);
        self::assertSame($expectedCost, $best['cost']);
    }

    /**
     * @return iterable<string, array{list<Order>, string, string, string, int}>
     */
    public static function provideExtremeRateScenarios(): iterable
    {
        $highGrowthFirstLeg = BcMath::div('1', '0.000000000123456789', self::SCALE);
        $astronomicalProduct = BcMath::mul(
            $highGrowthFirstLeg,
            BcMath::normalize('987654321.987654321', self::SCALE),
            self::SCALE,
        );

        yield 'astronomical_precision_path' => [
            [
                OrderFactory::createOrder(
                    OrderSide::SELL,
                    'MIC',
                    'SRC',
                    '1.000000000000000000',
                    '1.000000000000000000',
                    '0.000000000123456789',
                    amountScale: 18,
                    rateScale: 18,
                ),
                OrderFactory::createOrder(
                    OrderSide::BUY,
                    'MIC',
                    'MEG',
                    '1.000000000000000000',
                    '1.000000000000000000',
                    '987654321.987654321',
                    amountScale: 18,
                    rateScale: 9,
                ),
                OrderFactory::createOrder(
                    OrderSide::BUY,
                    'SRC',
                    'MEG',
                    '1.000000000000000000',
                    '1.000000000000000000',
                    '100.000000000000000000',
                    amountScale: 18,
                    rateScale: 18,
                ),
            ],
            'SRC',
            'MEG',
            $astronomicalProduct,
            2,
        ];

        yield 'microscopic_precision_path' => [
            [
                OrderFactory::createOrder(
                    OrderSide::SELL,
                    'MIC',
                    'SRC',
                    '1.000000000000000000',
                    '1.000000000000000000',
                    '987654321.987654321',
                    amountScale: 18,
                    rateScale: 9,
                ),
                OrderFactory::createOrder(
                    OrderSide::BUY,
                    'MIC',
                    'MEG',
                    '1.000000000000000000',
                    '1.000000000000000000',
                    '0.000000000123456789',
                    amountScale: 18,
                    rateScale: 18,
                ),
                OrderFactory::createOrder(
                    OrderSide::BUY,
                    'SRC',
                    'MEG',
                    '1.000000000000000000',
                    '1.000000000000000000',
                    '0.000000000200000000',
                    amountScale: 18,
                    rateScale: 18,
                ),
            ],
            'SRC',
            'MEG',
            BcMath::normalize('0.000000000200000000', self::SCALE),
            1,
        ];
    }

    public function test_it_prevents_cycles_by_tracking_assets(): void
    {
        $orders = [
            OrderFactory::sell('MID', 'SRC', '1.000', '1.000', '1.000', 3, 3),
            OrderFactory::sell('SRC', 'MID', '1.000', '1.000', '1.000', 3, 3),
            OrderFactory::sell('DST', 'MID', '1.000', '1.000', '1.000', 3, 3),
        ];

        $graph = (new GraphBuilder())->build($orders);

        $finder = new PathFinder(maxHops: 4, tolerance: '0.0');
        $results = self::extractPaths($finder->findBestPaths($graph, 'SRC', 'DST'));

        self::assertNotSame([], $results);
        $best = $results[0];

        $assetTrail = ['SRC'];
        foreach ($best['edges'] as $edge) {
            $assetTrail[] = $edge['to'];
        }

        self::assertSame($assetTrail, array_unique($assetTrail), 'Paths should not revisit identical assets.');
    }

    public function test_it_enforces_expansion_guard_on_dense_graph(): void
    {
        $orders = self::createDenseLayeredOrders(3, 3);
        $graph = (new GraphBuilder())->build($orders);

        $guardedFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.0',
            topK: 1,
            maxExpansions: 1,
            maxVisitedStates: 100,
        );

        $guardedResult = $guardedFinder->findBestPaths($graph, 'SRC', 'DST');

        self::assertSame([], $guardedResult->paths());
        self::assertTrue($guardedResult->guardLimits()->expansionsReached());
        self::assertFalse($guardedResult->guardLimits()->visitedStatesReached());

        $relaxedFinder = new PathFinder(
            maxHops: 5,
            tolerance: '0.0',
            topK: 1,
            maxExpansions: 20000,
            maxVisitedStates: 20000,
        );

        $results = self::extractPaths($relaxedFinder->findBestPaths($graph, 'SRC', 'DST'));

        self::assertNotSame([], $results);
        self::assertGreaterThan(0, $results[0]['hops']);
    }

    public function test_it_enforces_visited_guard_on_dense_graph(): void
    {
        $orders = self::createDenseLayeredOrders(2, 4);
        $graph = (new GraphBuilder())->build($orders);

        $guardedFinder = new PathFinder(
            maxHops: 4,
            tolerance: '0.0',
            topK: 1,
            maxExpansions: 100,
            maxVisitedStates: 1,
        );

        $guardedResult = $guardedFinder->findBestPaths($graph, 'SRC', 'DST');

        self::assertSame([], $guardedResult->paths());
        self::assertFalse($guardedResult->guardLimits()->expansionsReached());
        self::assertTrue($guardedResult->guardLimits()->visitedStatesReached());

        $relaxedFinder = new PathFinder(
            maxHops: 4,
            tolerance: '0.0',
            topK: 1,
            maxExpansions: 10000,
            maxVisitedStates: 10000,
        );

        $results = self::extractPaths($relaxedFinder->findBestPaths($graph, 'SRC', 'DST'));

        self::assertNotSame([], $results);
    }

    /**
     * @return list<Order>
     */
    private static function createDenseLayeredOrders(int $depth, int $fanout): array
    {
        $orders = [];
        $currentLayer = ['SRC'];
        $counter = 0;

        for ($layer = 1; $layer <= $depth; ++$layer) {
            $nextLayer = [];

            foreach ($currentLayer as $index => $asset) {
                for ($i = 0; $i < $fanout; ++$i) {
                    $nextAsset = self::syntheticCurrency($counter++);
                    $orders[] = OrderFactory::sell($nextAsset, $asset, '1.000', '1.000', '1.000', 3, 3);
                    $nextLayer[] = $nextAsset;
                }
            }

            $currentLayer = $nextLayer;
        }

        foreach ($currentLayer as $asset) {
            $orders[] = OrderFactory::sell('DST', $asset, '1.000', '1.000', '1.000', 3, 3);
        }

        return $orders;
    }

    /**
     * @return list<Order>
     */
    private static function buildComprehensiveOrderBook(): array
    {
        return array_merge(
            self::createRubToUsdSellOrders(),
            self::createUsdToIdrBuyOrders(),
            self::createDirectRubToIdrOrders(),
            self::createMultiHopSupplement(),
        );
    }

    /**
     * @return list<Order>
     */
    private static function createRubToUsdSellOrders(): array
    {
        $rates = [
            '96.500', '97.250', '94.400', '95.100', '98.600',
            '93.300', '92.750', '94.900', '96.000', '95.500',
            '97.800', '94.050', '92.350', '93.750', '96.800',
            '91.900', '90.500', '94.200', '95.900', '93.100',
        ];

        $orders = [];
        foreach ($rates as $index => $rate) {
            $maxBase = 100 + ($index * 5);
            $minBase = 0 === $index % 2 ? $maxBase : $maxBase / 2;

            $orders[] = OrderFactory::createOrder(
                OrderSide::SELL,
                'USD',
                'RUB',
                self::formatAmount($minBase),
                self::formatAmount($maxBase),
                $rate,
                rateScale: 3,
            );
        }

        return $orders;
    }

    /**
     * @return list<Order>
     */
    private static function createUsdToIdrBuyOrders(): array
    {
        $rates = [
            '15050.000', '15120.000', '14980.000', '15240.000', '15090.000',
            '15310.000', '15020.000', '15170.000', '15360.000', '15280.000',
            '15110.000', '15060.000', '15210.000', '15030.000', '15320.000',
            '15190.000', '15400.000', '15010.000', '15260.000', '15140.000',
        ];

        $orders = [];
        foreach ($rates as $index => $rate) {
            $maxBase = 50 + ($index * 3);
            $minBase = 0 === $index % 2 ? $maxBase : $maxBase / 2;

            $orders[] = OrderFactory::createOrder(
                OrderSide::BUY,
                'USD',
                'IDR',
                self::formatAmount($minBase),
                self::formatAmount($maxBase),
                $rate,
                rateScale: 3,
            );
        }

        return $orders;
    }

    /**
     * @return list<Order>
     */
    private static function createDirectRubToIdrOrders(): array
    {
        return [
            OrderFactory::createOrder(
                OrderSide::BUY,
                'RUB',
                'IDR',
                '200.000',
                '200.000',
                '165.000',
                rateScale: 3,
            ),
        ];
    }

    /**
     * @return list<Order>
     */
    private static function createMultiHopSupplement(): array
    {
        return [
            OrderFactory::createOrder(
                OrderSide::BUY,
                'USD',
                'JPY',
                '25.000',
                '50.000',
                '149.500',
                rateScale: 3,
            ),
            OrderFactory::createOrder(
                OrderSide::BUY,
                'JPY',
                'IDR',
                '2500.000',
                '5000.000',
                '112.750',
                rateScale: 3,
            ),
            OrderFactory::createOrder(
                OrderSide::BUY,
                'USD',
                'SGD',
                '15.000',
                '30.000',
                '1.350',
                rateScale: 3,
            ),
            OrderFactory::createOrder(
                OrderSide::BUY,
                'SGD',
                'IDR',
                '20.000',
                '40.000',
                '11250.000',
                rateScale: 3,
            ),
        ];
    }

    /**
     * @return list<Order>
     */
    private static function createUsdToEurDirectOrders(): array
    {
        return [
            OrderFactory::createOrder(
                OrderSide::BUY,
                'USD',
                'EUR',
                '10.000',
                '10.000',
                '0.9200',
                amountScale: 3,
                rateScale: 4,
            ),
        ];
    }

    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     orderSide: OrderSide,
     *     order: Order,
     *     rate: ExchangeRate,
     *     baseCapacity: array{min: Money, max: Money},
     *     quoteCapacity: array{min: Money, max: Money},
     *     grossBaseCapacity: array{min: Money, max: Money},
     *     segments: list<array{
     *         isMandatory: bool,
     *         base: array{min: Money, max: Money},
     *         quote: array{min: Money, max: Money},
     *         grossBase: array{min: Money, max: Money},
     *     }>,
     * }
     */
    private static function manualEdge(string $from, string $to, string $rate, int $scale = 3): array
    {
        $order = OrderFactory::createOrder(
            OrderSide::BUY,
            $from,
            $to,
            '1.000',
            '1.000',
            $rate,
            amountScale: $scale,
            rateScale: $scale,
        );

        $baseMin = Money::zero($from, $scale);
        $baseMax = Money::fromString($from, '1.000', $scale);
        $quoteMin = Money::zero($to, $scale);
        $quoteMax = Money::fromString($to, $rate, $scale);

        return [
            'from' => $from,
            'to' => $to,
            'orderSide' => OrderSide::BUY,
            'order' => $order,
            'rate' => ExchangeRate::fromString($from, $to, $rate, $scale),
            'baseCapacity' => ['min' => $baseMin, 'max' => $baseMax],
            'quoteCapacity' => ['min' => $quoteMin, 'max' => $quoteMax],
            'grossBaseCapacity' => ['min' => $baseMin, 'max' => $baseMax],
            'segments' => [[
                'isMandatory' => false,
                'base' => ['min' => $baseMin, 'max' => $baseMax],
                'quote' => ['min' => $quoteMin, 'max' => $quoteMax],
                'grossBase' => ['min' => $baseMin, 'max' => $baseMax],
            ]],
        ];
    }

    /**
     * @return list<Order>
     */
    private static function createUsdToEthSellOrders(): array
    {
        return [
            OrderFactory::createOrder(
                OrderSide::SELL,
                'ETH',
                'USD',
                '5.000',
                '5.000',
                '1800.00',
                amountScale: 3,
                rateScale: 2,
            ),
        ];
    }

    /**
     * @return list<Order>
     */
    private static function createEthToEurBuyOrders(): array
    {
        return [
            OrderFactory::createOrder(
                OrderSide::BUY,
                'ETH',
                'EUR',
                '5.000',
                '5.000',
                '1700.00',
                amountScale: 3,
                rateScale: 2,
            ),
        ];
    }

    private static function basePercentageFeePolicy(string $percentage): FeePolicy
    {
        return new class($percentage) implements FeePolicy {
            public function __construct(private readonly string $percentage)
            {
            }

            public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
            {
                $fee = $baseAmount->multiply($this->percentage, $baseAmount->scale());

                return FeeBreakdown::forBase($fee);
            }
        };
    }

    private static function mixedPercentageFeePolicy(string $basePercentage, string $quotePercentage): FeePolicy
    {
        return new class($basePercentage, $quotePercentage) implements FeePolicy {
            public function __construct(
                private readonly string $basePercentage,
                private readonly string $quotePercentage,
            ) {
            }

            public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
            {
                $baseFee = $baseAmount->multiply($this->basePercentage, $baseAmount->scale());
                $quoteFee = $quoteAmount->multiply($this->quotePercentage, $quoteAmount->scale());

                return FeeBreakdown::of($baseFee, $quoteFee);
            }
        };
    }

    private static function formatAmount(float $amount): string
    {
        return number_format($amount, 3, '.', '');
    }

    private static function syntheticCurrency(int $index): string
    {
        $alphabet = range('A', 'Z');

        $first = intdiv($index, 26 * 26) % 26;
        $second = intdiv($index, 26) % 26;
        $third = $index % 26;

        return $alphabet[$first].$alphabet[$second].$alphabet[$third];
    }
}
