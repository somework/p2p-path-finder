<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Service\PathFinder;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\Graph\GraphEdge;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathOrderKey;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathOrderStrategy;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\PathResultSet;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\PathResultSetEntry;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\SearchGuardReport;
use SomeWork\P2PPathFinder\Application\Result\PathLeg;
use SomeWork\P2PPathFinder\Application\Result\PathLegCollection;
use SomeWork\P2PPathFinder\Application\Result\PathResult;
use SomeWork\P2PPathFinder\Application\Service\PathFinderService;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\DecimalTolerance;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Tests\Application\Support\Generator\PathFinderScenarioGenerator;
use SomeWork\P2PPathFinder\Tests\Support\InfectionIterationLimiter;

use function array_map;
use function array_reverse;
use function array_unique;
use function count;
use function implode;
use function max;
use function serialize;
use function usort;

final class PathFinderServicePropertyTest extends TestCase
{
    use InfectionIterationLimiter;

    private PathFinderScenarioGenerator $generator;
    private GraphBuilder $graphBuilder;
    private PathFinderService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generator = new PathFinderScenarioGenerator();
        $this->graphBuilder = new GraphBuilder();
        $this->service = new PathFinderService($this->graphBuilder);
    }

    public function test_random_scenarios_produce_deterministic_unique_service_paths(): void
    {
        $limit = $this->iterationLimit(15, 5, 'P2P_PATH_FINDER_SERVICE_ITERATIONS');

        for ($iteration = 0; $iteration < $limit; ++$iteration) {
            $scenario = $this->generator->scenario();
            $orders = $scenario['orders'];
            $orderBook = new OrderBook($orders);
            $graph = $this->graphBuilder->build($orders);

            $source = $scenario['source'];
            $node = $graph->node($source);
            if (null === $node) {
                self::fail('Generated scenario must include the source node.');
            }

            $edges = $node->edges();
            if ([] === $edges) {
                self::fail('Generated scenario must expose at least one outgoing edge from the source node.');
            }

            $spendAmount = $this->deriveSpendAmount($edges[0]);
            $config = PathSearchConfig::builder()
                ->withSpendAmount($spendAmount)
                ->withToleranceBounds('0.0', $scenario['tolerance'])
                ->withHopLimits(1, $scenario['maxHops'])
                ->withResultLimit($scenario['topK'])
                ->build();

            $first = $this->service->findBestPaths($orderBook, $config, $scenario['target']);
            $second = $this->service->findBestPaths($orderBook, $config, $scenario['target']);

            $encoder = $this->encodeResult();
            $firstEncoded = array_map($encoder, $first->paths()->toArray());
            $secondEncoded = array_map($encoder, $second->paths()->toArray());

            self::assertSame($firstEncoded, $secondEncoded, 'PathFinderService results must be deterministic.');
            self::assertCount(
                count(array_unique($firstEncoded)),
                $firstEncoded,
                'Materialized paths should be unique across the result set.'
            );
            $this->assertGuardStatusEquals($first->guardLimits(), $second->guardLimits());

            $maximumTolerance = BcMath::normalize($scenario['tolerance'], 18);
            foreach ($first->paths() as $result) {
                $residual = $result->residualTolerance()->ratio();
                self::assertLessThanOrEqual(
                    0,
                    BcMath::comp($residual, $maximumTolerance, 18),
                    'Residual tolerance should never exceed configured maximum.'
                );
            }

            $keys = $this->buildSortKeys($first->paths()->toArray());
            $sortedKeys = $keys;
            usort($sortedKeys, [$this, 'compareSortKeys']);

            self::assertSame(
                $sortedKeys,
                $keys,
                'PathFinderService results must honour cost, hop and signature ordering.',
            );
        }
    }

    /**
     * Invariant: PathFinderService::findBestPaths must yield identical encoded paths and guard metadata
     *            regardless of the iteration order of source orders.
     */
    public function test_permuted_order_books_produce_identical_results(): void
    {
        $limit = $this->iterationLimit(15, 5, 'P2P_PATH_FINDER_SERVICE_ITERATIONS');

        for ($iteration = 0; $iteration < $limit; ++$iteration) {
            $scenario = $this->generator->scenario();
            $orders = $scenario['orders'];
            $orderBook = new OrderBook($orders);
            $permutedOrders = array_reverse($orders);
            $permutedOrderBook = new OrderBook($permutedOrders);

            $graph = $this->graphBuilder->build($orders);

            $source = $scenario['source'];
            $node = $graph->node($source);
            if (null === $node) {
                self::fail('Generated scenario must include the source node.');
            }

            $edges = $node->edges();
            if ([] === $edges) {
                self::fail('Generated scenario must expose at least one outgoing edge from the source node.');
            }

            $spendAmount = $this->deriveSpendAmount($edges[0]);
            $config = PathSearchConfig::builder()
                ->withSpendAmount($spendAmount)
                ->withToleranceBounds('0.0', $scenario['tolerance'])
                ->withHopLimits(1, $scenario['maxHops'])
                ->withResultLimit($scenario['topK'])
                ->build();

            $original = $this->service->findBestPaths($orderBook, $config, $scenario['target']);
            $permuted = $this->service->findBestPaths($permutedOrderBook, $config, $scenario['target']);

            $encoder = $this->encodeResult();
            $originalEncoded = array_map($encoder, $original->paths()->toArray());
            $permutedEncoded = array_map($encoder, $permuted->paths()->toArray());

            self::assertSame(
                $originalEncoded,
                $permutedEncoded,
                'Resulting path collections must be invariant to input order permutations.',
            );
            $this->assertGuardStatusEquals(
                $original->guardLimits(),
                $permuted->guardLimits(),
                'Permutation guard metadata mismatch.',
            );
        }
    }

    public function test_custom_ordering_strategy_applies_to_materialized_results(): void
    {
        $strategy = new class implements PathOrderStrategy {
            public function compare(PathOrderKey $left, PathOrderKey $right): int
            {
                $comparison = $right->routeSignature() <=> $left->routeSignature();
                if (0 !== $comparison) {
                    return $comparison;
                }

                return $left->insertionOrder() <=> $right->insertionOrder();
            }
        };

        $entries = [
            new PathResultSetEntry(
                new PathResult(
                    Money::fromString('SRC', '1.0', 1),
                    Money::fromString('DST', '1.0', 1),
                    DecimalTolerance::zero(),
                    $this->buildLegCollection(['SRC', 'ALP', 'DST']),
                ),
                new PathOrderKey('0.100000000000000000', 2, 'SRC->ALP->DST', 0),
            ),
            new PathResultSetEntry(
                new PathResult(
                    Money::fromString('SRC', '1.0', 1),
                    Money::fromString('DST', '1.0', 1),
                    DecimalTolerance::zero(),
                    $this->buildLegCollection(['SRC', 'BET', 'DST']),
                ),
                new PathOrderKey('0.100000000000000000', 2, 'SRC->BET->DST', 1),
            ),
            new PathResultSetEntry(
                new PathResult(
                    Money::fromString('SRC', '1.0', 1),
                    Money::fromString('DST', '1.0', 1),
                    DecimalTolerance::zero(),
                    $this->buildLegCollection(['SRC', 'CHI', 'DST']),
                ),
                new PathOrderKey('0.100000000000000000', 2, 'SRC->CHI->DST', 2),
            ),
        ];

        $resultSet = PathResultSet::fromEntries($strategy, $entries);

        self::assertSame(
            ['SRC->CHI->DST', 'SRC->BET->DST', 'SRC->ALP->DST'],
            array_map(
                fn (PathResult $result): string => $this->routeSignatureFromLegs($result->legs()),
                $resultSet->toArray(),
            ),
        );
    }

    public function test_dataset_scenarios_remain_deterministic(): void
    {
        foreach (PathFinderScenarioGenerator::dataset() as $scenario) {
            $orders = $scenario['orders'];
            $orderBook = new OrderBook($orders);
            $graph = $this->graphBuilder->build($orders);
            $node = $graph->node($scenario['source']);

            self::assertNotNull($node, 'Dataset scenario should include the source node.');

            $edges = $node->edges();

            self::assertNotSame([], $edges, 'Dataset scenario should expose source edges.');

            $config = PathSearchConfig::builder()
                ->withSpendAmount($this->deriveSpendAmount($edges[0]))
                ->withToleranceBounds('0.0', $scenario['tolerance'])
                ->withHopLimits(1, $scenario['maxHops'])
                ->withResultLimit($scenario['topK'])
                ->build();

            $first = $this->service->findBestPaths($orderBook, $config, $scenario['target']);
            $second = $this->service->findBestPaths($orderBook, $config, $scenario['target']);

            $encoder = $this->encodeResult();
            self::assertSame(array_map($encoder, $first->paths()->toArray()), array_map($encoder, $second->paths()->toArray()));
            $this->assertGuardStatusEquals(
                $first->guardLimits(),
                $second->guardLimits(),
                'Dataset guard metadata mismatch.',
            );
        }
    }

    private function deriveSpendAmount(GraphEdge $edge): Money
    {
        $capacity = OrderSide::BUY === $edge->orderSide()
            ? $edge->grossBaseCapacity()
            : $edge->quoteCapacity();

        $minimum = $capacity->min();
        $maximum = $capacity->max();
        $scale = max($minimum->scale(), $maximum->scale());

        $midpoint = $minimum->add($maximum, $scale)->divide('2', $scale);

        if ($midpoint->lessThan($minimum)) {
            $midpoint = $minimum->withScale($scale);
        } elseif ($midpoint->greaterThan($maximum)) {
            $midpoint = $maximum->withScale($scale);
        }

        return $midpoint->withScale(max($scale, 3));
    }

    /**
     * @return callable(PathResult): string
     */
    private function encodeResult(): callable
    {
        return static fn (PathResult $result): string => serialize($result->jsonSerialize());
    }

    /**
     * @param list<string> $nodes
     */
    private function buildLegCollection(array $nodes): PathLegCollection
    {
        $legs = [];
        $lastIndex = count($nodes) - 1;

        for ($index = 0; $index < $lastIndex; ++$index) {
            $from = $nodes[$index];
            $to = $nodes[$index + 1];

            $legs[] = new PathLeg(
                $from,
                $to,
                Money::fromString($from, '1.0', 1),
                Money::fromString($to, '1.0', 1),
            );
        }

        return PathLegCollection::fromList($legs);
    }

    /**
     * @param list<PathResult> $results
     *
     * @return list<array{cost: string, hops: int, signature: string, order: int}>
     */
    private function buildSortKeys(array $results): array
    {
        $keys = [];

        foreach ($results as $order => $result) {
            $keys[] = $this->sortKeyForResult($result, $order);
        }

        return $keys;
    }

    /**
     * @return array{cost: string, hops: int, signature: string, order: int}
     */
    private function sortKeyForResult(PathResult $result, int $order): array
    {
        $spent = $result->totalSpent()->withScale(18);
        $received = $result->totalReceived()->withScale(18);

        $receivedAmount = $received->amount();
        if (0 === BcMath::comp($receivedAmount, '0', 18)) {
            self::fail('Materialized path must produce a non-zero destination amount.');
        }

        $cost = BcMath::div($spent->amount(), $receivedAmount, 18);

        return [
            'cost' => $cost,
            'hops' => count($result->legs()),
            'signature' => $this->routeSignatureFromLegs($result->legs()),
            'order' => $order,
        ];
    }

    /**
     * @param iterable<PathLeg> $legs
     */
    private function routeSignatureFromLegs(iterable $legs): string
    {
        $nodes = [];
        $firstLeg = true;

        foreach ($legs as $leg) {
            if ($firstLeg) {
                $nodes[] = $leg->from();
                $firstLeg = false;
            }

            $nodes[] = $leg->to();
        }

        if ([] === $nodes) {
            return '';
        }

        return implode('->', $nodes);
    }

    /**
     * @param array{cost: string, hops: int, signature: string, order: int} $left
     * @param array{cost: string, hops: int, signature: string, order: int} $right
     */
    private function compareSortKeys(array $left, array $right): int
    {
        $comparison = BcMath::comp($left['cost'], $right['cost'], 18);
        if (0 !== $comparison) {
            return $comparison;
        }

        $hopComparison = $left['hops'] <=> $right['hops'];
        if (0 !== $hopComparison) {
            return $hopComparison;
        }

        $signatureComparison = $left['signature'] <=> $right['signature'];
        if (0 !== $signatureComparison) {
            return $signatureComparison;
        }

        return $left['order'] <=> $right['order'];
    }

    private function assertGuardStatusEquals(
        SearchGuardReport $expected,
        SearchGuardReport $actual,
        string $message = 'Guard metadata should be identical'
    ): void {
        self::assertSame(
            $expected->expansionsReached(),
            $actual->expansionsReached(),
            $message.' (expansions)',
        );
        self::assertSame(
            $expected->visitedStatesReached(),
            $actual->visitedStatesReached(),
            $message.' (visited states)',
        );
        self::assertSame(
            $expected->timeBudgetReached(),
            $actual->timeBudgetReached(),
            $message.' (time budget)',
        );
    }
}
