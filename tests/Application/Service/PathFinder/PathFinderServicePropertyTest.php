<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Service\PathFinder;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathOrderKey;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathOrderStrategy;
use SomeWork\P2PPathFinder\Application\Result\PathLeg;
use SomeWork\P2PPathFinder\Application\Result\PathResult;
use SomeWork\P2PPathFinder\Application\Service\PathFinderService;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\DecimalTolerance;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Tests\Application\Support\Generator\PathFinderScenarioGenerator;
use SomeWork\P2PPathFinder\Tests\Support\InfectionIterationLimiter;

use function array_map;
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
        $limit = $this->iterationLimit(20, 5, 'P2P_PATH_FINDER_SERVICE_ITERATIONS');

        for ($iteration = 0; $iteration < $limit; ++$iteration) {
            $scenario = $this->generator->scenario();
            $orders = $scenario['orders'];
            $orderBook = new OrderBook($orders);
            $graph = $this->graphBuilder->build($orders);

            $source = $scenario['source'];
            $edges = $graph[$source]['edges'] ?? [];
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
            $firstEncoded = array_map($encoder, $first->paths());
            $secondEncoded = array_map($encoder, $second->paths());

            self::assertSame($firstEncoded, $secondEncoded, 'PathFinderService results must be deterministic.');
            self::assertCount(
                count(array_unique($firstEncoded)),
                $firstEncoded,
                'Materialized paths should be unique across the result set.'
            );
            self::assertSame(
                $first->guardLimits()->expansionsReached(),
                $second->guardLimits()->expansionsReached()
            );
            self::assertSame(
                $first->guardLimits()->visitedStatesReached(),
                $second->guardLimits()->visitedStatesReached()
            );

            $maximumTolerance = BcMath::normalize($scenario['tolerance'], 18);
            foreach ($first->paths() as $result) {
                $residual = $result->residualTolerance()->ratio();
                self::assertLessThanOrEqual(
                    0,
                    BcMath::comp($residual, $maximumTolerance, 18),
                    'Residual tolerance should never exceed configured maximum.'
                );
            }

            $keys = $this->buildSortKeys($first->paths());
            $sortedKeys = $keys;
            usort($sortedKeys, [$this, 'compareSortKeys']);

            self::assertSame(
                $sortedKeys,
                $keys,
                'PathFinderService results must honour cost, hop and signature ordering.',
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

        $service = new PathFinderService($this->graphBuilder, orderingStrategy: $strategy);

        $sorter = new ReflectionMethod(PathFinderService::class, 'sortMaterializedResults');
        $sorter->setAccessible(true);

        $firstResult = new PathResult(
            Money::fromString('SRC', '1.0', 1),
            Money::fromString('DST', '1.0', 1),
            DecimalTolerance::zero(),
        );
        $secondResult = new PathResult(
            Money::fromString('SRC', '1.0', 1),
            Money::fromString('DST', '1.0', 1),
            DecimalTolerance::zero(),
        );
        $thirdResult = new PathResult(
            Money::fromString('SRC', '1.0', 1),
            Money::fromString('DST', '1.0', 1),
            DecimalTolerance::zero(),
        );

        $entries = [
            [
                'cost' => '0.100000000000000000',
                'hops' => 2,
                'routeSignature' => 'SRC->ALP->DST',
                'order' => 0,
                'result' => $firstResult,
                'orderKey' => new PathOrderKey(
                    '0.100000000000000000',
                    2,
                    'SRC->ALP->DST',
                    0,
                    ['pathResult' => $firstResult],
                ),
            ],
            [
                'cost' => '0.100000000000000000',
                'hops' => 2,
                'routeSignature' => 'SRC->BET->DST',
                'order' => 1,
                'result' => $secondResult,
                'orderKey' => new PathOrderKey(
                    '0.100000000000000000',
                    2,
                    'SRC->BET->DST',
                    1,
                    ['pathResult' => $secondResult],
                ),
            ],
            [
                'cost' => '0.100000000000000000',
                'hops' => 2,
                'routeSignature' => 'SRC->CHI->DST',
                'order' => 2,
                'result' => $thirdResult,
                'orderKey' => new PathOrderKey(
                    '0.100000000000000000',
                    2,
                    'SRC->CHI->DST',
                    2,
                    ['pathResult' => $thirdResult],
                ),
            ],
        ];

        $sorter->invokeArgs($service, [&$entries]);

        self::assertSame(
            ['SRC->CHI->DST', 'SRC->BET->DST', 'SRC->ALP->DST'],
            array_map(static fn (array $entry): string => $entry['routeSignature'], $entries),
        );
    }

    /**
     * @param array{orderSide: OrderSide, grossBaseCapacity: array{min: Money, max: Money}, quoteCapacity: array{min: Money, max: Money}} $edge
     */
    private function deriveSpendAmount(array $edge): Money
    {
        $capacity = OrderSide::BUY === $edge['orderSide'] ? $edge['grossBaseCapacity'] : $edge['quoteCapacity'];
        $minimum = $capacity['min'];
        $maximum = $capacity['max'];
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
     * @param list<PathLeg> $legs
     */
    private function routeSignatureFromLegs(array $legs): string
    {
        if ([] === $legs) {
            return '';
        }

        $nodes = [$legs[0]->from()];

        foreach ($legs as $leg) {
            $nodes[] = $leg->to();
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
}
