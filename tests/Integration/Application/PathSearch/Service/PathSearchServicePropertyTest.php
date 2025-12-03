<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Integration\Application\PathSearch\Service;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Api\Request\PathSearchRequest;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathCost;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathOrderKey;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathOrderStrategy;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\RouteSignature;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\GraphEdge;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\Path;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathHop;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathHopCollection;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathResultSet;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\SearchGuardReport;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\PathSearchService;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\OrderBook;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\Tolerance\DecimalTolerance;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;
use SomeWork\P2PPathFinder\Tests\Helpers\Generator\PathFinderScenarioGenerator;
use SomeWork\P2PPathFinder\Tests\Helpers\InfectionIterationLimiter;

use function array_map;
use function array_reverse;
use function array_unique;
use function count;
use function implode;
use function max;
use function serialize;
use function usort;

final class PathSearchServicePropertyTest extends TestCase
{
    use InfectionIterationLimiter;

    private PathFinderScenarioGenerator $generator;
    private GraphBuilder $graphBuilder;
    private PathSearchService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generator = new PathFinderScenarioGenerator();
        $this->graphBuilder = new GraphBuilder();
        $this->service = new PathSearchService($this->graphBuilder);
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
            if ($edges->isEmpty()) {
                self::fail('Generated scenario must expose at least one outgoing edge from the source node.');
            }

            $spendAmount = $this->deriveSpendAmount($edges->at(0));
            $config = PathSearchConfig::builder()
                ->withSpendAmount($spendAmount)
                ->withToleranceBounds('0.0', $scenario['tolerance'])
                ->withHopLimits(1, $scenario['maxHops'])
                ->withResultLimit($scenario['topK'])
                ->build();

            $request = $this->request($orderBook, $config, $scenario['target']);
            $first = $this->service->findBestPaths($request);
            $second = $this->service->findBestPaths($request);

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

            $maximumTolerance = BigDecimal::of($scenario['tolerance'])->toScale(18, RoundingMode::HALF_UP);
            foreach ($first->paths() as $result) {
                $residual = BigDecimal::of($result->residualTolerance()->ratio())
                    ->toScale(18, RoundingMode::HALF_UP);
                self::assertLessThanOrEqual(
                    0,
                    $residual->compareTo($maximumTolerance),
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
            if ($edges->isEmpty()) {
                self::fail('Generated scenario must expose at least one outgoing edge from the source node.');
            }

            $spendAmount = $this->deriveSpendAmount($edges->at(0));
            $config = PathSearchConfig::builder()
                ->withSpendAmount($spendAmount)
                ->withToleranceBounds('0.0', $scenario['tolerance'])
                ->withHopLimits(1, $scenario['maxHops'])
                ->withResultLimit($scenario['topK'])
                ->build();

            $originalRequest = $this->request($orderBook, $config, $scenario['target']);
            $permutedRequest = $this->request($permutedOrderBook, $config, $scenario['target']);
            $original = $this->service->findBestPaths($originalRequest);
            $permuted = $this->service->findBestPaths($permutedRequest);

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
                $comparison = $right->routeSignature()->compare($left->routeSignature());
                if (0 !== $comparison) {
                    return $comparison;
                }

                return $left->insertionOrder() <=> $right->insertionOrder();
            }
        };

        $paths = [
            new Path($this->buildHopCollection(['SRC', 'ALP', 'DST']), DecimalTolerance::zero()),
            new Path($this->buildHopCollection(['SRC', 'BET', 'DST']), DecimalTolerance::zero()),
            new Path($this->buildHopCollection(['SRC', 'CHI', 'DST']), DecimalTolerance::zero()),
        ];
        $orderKeys = [
            new PathOrderKey(new PathCost('0.100000000000000000'), 2, RouteSignature::fromNodes(['SRC', 'ALP', 'DST']), 0),
            new PathOrderKey(new PathCost('0.100000000000000000'), 2, RouteSignature::fromNodes(['SRC', 'BET', 'DST']), 1),
            new PathOrderKey(new PathCost('0.100000000000000000'), 2, RouteSignature::fromNodes(['SRC', 'CHI', 'DST']), 2),
        ];

        $resultSet = PathResultSet::fromPaths(
            $strategy,
            $paths,
            static fn (Path $result, int $index): PathOrderKey => $orderKeys[$index],
        );

        self::assertSame(
            ['SRC->CHI->DST', 'SRC->BET->DST', 'SRC->ALP->DST'],
            array_map(
                fn (Path $result): string => $this->routeSignatureFromHops($result->hops()),
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

            self::assertFalse($edges->isEmpty(), 'Dataset scenario should expose source edges.');

            $config = PathSearchConfig::builder()
                ->withSpendAmount($this->deriveSpendAmount($edges->at(0)))
                ->withToleranceBounds('0.0', $scenario['tolerance'])
                ->withHopLimits(1, $scenario['maxHops'])
                ->withResultLimit($scenario['topK'])
                ->build();

            $request = $this->request($orderBook, $config, $scenario['target']);
            $first = $this->service->findBestPaths($request);
            $second = $this->service->findBestPaths($request);

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

    private function request(OrderBook $orderBook, PathSearchConfig $config, string $target): PathSearchRequest
    {
        return new PathSearchRequest($orderBook, $config, $target);
    }

    /**
     * @return callable(Path): string
     */
    private function encodeResult(): callable
    {
        return static fn (Path $result): string => serialize($result->toArray());
    }

    /**
     * @param list<string> $nodes
     */
    private function buildHopCollection(array $nodes): PathHopCollection
    {
        $hops = [];
        $lastIndex = count($nodes) - 1;

        for ($index = 0; $index < $lastIndex; ++$index) {
            $from = $nodes[$index];
            $to = $nodes[$index + 1];

            $hops[] = new PathHop(
                $from,
                $to,
                Money::fromString($from, '1.0', 1),
                Money::fromString($to, '1.0', 1),
                OrderFactory::sell($from, $to, '1.0', '1.0', '1.0', 1, 1),
            );
        }

        return PathHopCollection::fromList($hops);
    }

    /**
     * @param list<Path> $results
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
    private function sortKeyForResult(Path $result, int $order): array
    {
        $spent = $result->totalSpent()->withScale(18);
        $received = $result->totalReceived()->withScale(18);

        $receivedDecimal = BigDecimal::of($received->amount())->toScale(18, RoundingMode::HALF_UP);
        if ($receivedDecimal->isZero()) {
            self::fail('Materialized path must produce a non-zero destination amount.');
        }

        $spentDecimal = BigDecimal::of($spent->amount())->toScale(18, RoundingMode::HALF_UP);
        $cost = $spentDecimal
            ->dividedBy($receivedDecimal, 18, RoundingMode::HALF_UP)
            ->__toString();

        return [
            'cost' => $cost,
            'hops' => count($result->hops()),
            'signature' => $this->routeSignatureFromHops($result->hops()),
            'order' => $order,
        ];
    }

    /**
     * @param iterable<PathHop> $hops
     */
    private function routeSignatureFromHops(iterable $hops): string
    {
        $nodes = [];
        $firstHop = true;

        foreach ($hops as $hop) {
            if ($firstHop) {
                $nodes[] = $hop->from();
                $firstHop = false;
            }

            $nodes[] = $hop->to();
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
        $comparison = BigDecimal::of($left['cost'])
            ->toScale(18, RoundingMode::HALF_UP)
            ->compareTo(BigDecimal::of($right['cost'])->toScale(18, RoundingMode::HALF_UP));
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
