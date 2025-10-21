<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\PathFinder\PathFinder;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\GuardLimitStatus;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Tests\Application\Support\Generator\PathFinderScenarioGenerator;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;
use SomeWork\P2PPathFinder\Tests\Support\InfectionIterationLimiter;

use function array_map;
use function array_reverse;
use function array_unique;
use function count;
use function implode;
use function spl_object_id;

final class PathFinderPropertyTest extends TestCase
{
    use InfectionIterationLimiter;

    private PathFinderScenarioGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generator = new PathFinderScenarioGenerator();
    }

    public function test_generated_graphs_produce_deterministic_unique_paths(): void
    {
        $graphBuilder = new GraphBuilder();

        $limit = $this->iterationLimit(18, 5, 'P2P_PATH_FINDER_PROPERTY_ITERATIONS');

        for ($iteration = 0; $iteration < $limit; ++$iteration) {
            $scenario = $this->generator->scenario();

            $graph = $graphBuilder->build($scenario['orders']);
            $finder = new PathFinder(
                maxHops: $scenario['maxHops'],
                tolerance: $scenario['tolerance'],
                topK: $scenario['topK'],
            );

            $firstResult = $finder->findBestPaths($graph, $scenario['source'], $scenario['target']);
            $secondResult = $finder->findBestPaths($graph, $scenario['source'], $scenario['target']);

            $this->assertGuardStatusEquals(
                $firstResult->guardLimits(),
                $secondResult->guardLimits(),
                'Repeated search invocations must share guard metadata.',
            );

            $firstPaths = $firstResult->paths();
            $secondPaths = $secondResult->paths();

            self::assertSame($firstPaths, $secondPaths, 'PathFinder search should be deterministic.');

            $signatures = [];
            foreach ($firstPaths as $path) {
                $signature = $path['cost'].'|'.$path['product'].'|'.$path['hops'].'|';
                foreach ($path['edges'] as $edge) {
                    $signature .= $edge['from'].'>'.$edge['to'].'#'.spl_object_id($edge['order']).';';
                }

                $signatures[] = $signature;
            }

            self::assertCount(
                count(array_unique($signatures)),
                $signatures,
                'PathFinder returned duplicate path entries.',
            );

            $permutedOrders = array_reverse($scenario['orders']);
            $permutedGraph = $graphBuilder->build($permutedOrders);
            $permutedFinder = new PathFinder(
                maxHops: $scenario['maxHops'],
                tolerance: $scenario['tolerance'],
                topK: $scenario['topK'],
            );

            $permutedOutcome = $permutedFinder->findBestPaths(
                $permutedGraph,
                $scenario['source'],
                $scenario['target'],
            );
            $permutedPaths = $permutedOutcome->paths();

            self::assertSame(
                $firstPaths,
                $permutedPaths,
                'Order book permutations must not change search results.',
            );

            $this->assertGuardStatusEquals(
                $firstResult->guardLimits(),
                $permutedOutcome->guardLimits(),
                'Permuted order books must preserve guard metadata.',
            );

            $constraints = $this->deriveSpendConstraints($graph, $scenario['source']);
            $baselineConstrained = $finder->findBestPaths(
                $graph,
                $scenario['source'],
                $scenario['target'],
                $constraints,
            );

            $scaleFactor = $scenario['scaleBy'];
            $scaledScenario = $this->scaleScenario($scenario, $scaleFactor);
            $scaledGraph = $graphBuilder->build($scaledScenario['orders']);
            $scaledConstraints = $this->scaleSpendConstraints($constraints, $scaleFactor);
            $scaledFinder = new PathFinder(
                maxHops: $scaledScenario['maxHops'],
                tolerance: $scaledScenario['tolerance'],
                topK: $scaledScenario['topK'],
            );

            $scaledResult = $scaledFinder->findBestPaths(
                $scaledGraph,
                $scaledScenario['source'],
                $scaledScenario['target'],
                $scaledConstraints,
            );

            $baselineSignatures = array_map([$this, 'routeSignature'], $baselineConstrained->paths());
            $scaledSignatures = array_map([$this, 'routeSignature'], $scaledResult->paths());

            self::assertSame(
                $baselineSignatures,
                $scaledSignatures,
                'Scaling should preserve path ordering and structure.',
            );

            foreach ($scaledResult->paths() as $path) {
                $range = $path['amountRange'];
                $desired = $path['desiredAmount'];

                if (null === $range || null === $desired) {
                    continue;
                }

                $scale = max($range['min']->scale(), $range['max']->scale(), $desired->scale());
                $minimum = $range['min']->withScale($scale);
                $maximum = $range['max']->withScale($scale);
                $normalizedDesired = $desired->withScale($scale);

                self::assertGreaterThanOrEqual(
                    0,
                    $normalizedDesired->compare($minimum),
                    'Residual tolerance should not undershoot scaled bounds.',
                );
                self::assertLessThanOrEqual(
                    0,
                    $normalizedDesired->compare($maximum),
                    'Residual tolerance should not exceed scaled bounds.',
                );
            }
        }
    }

    public function test_dataset_scenarios_expose_deterministic_guards(): void
    {
        $graphBuilder = new GraphBuilder();

        foreach (PathFinderScenarioGenerator::dataset() as $scenario) {
            $graph = $graphBuilder->build($scenario['orders']);
            $finder = new PathFinder(
                maxHops: $scenario['maxHops'],
                tolerance: $scenario['tolerance'],
                topK: $scenario['topK'],
            );

            $first = $finder->findBestPaths($graph, $scenario['source'], $scenario['target']);
            $second = $finder->findBestPaths($graph, $scenario['source'], $scenario['target']);

            self::assertSame($first->paths(), $second->paths());
            $this->assertGuardStatusEquals(
                $first->guardLimits(),
                $second->guardLimits(),
                'Dataset scenarios should preserve guard metadata across runs.',
            );
        }
    }

    /**
     * @param array{orders: list<Order>, source: string, target: string, maxHops: int, topK: int, tolerance: numeric-string, scaleBy: numeric-string} $scenario
     *
     * @return array{orders: list<Order>, source: string, target: string, maxHops: int, topK: int, tolerance: numeric-string, scaleBy: numeric-string}
     */
    private function scaleScenario(array $scenario, string $scaleFactor): array
    {
        $scaledOrders = array_map(
            fn (Order $order): Order => $this->scaleOrder($order, $scaleFactor),
            $scenario['orders'],
        );

        return [
            'orders' => $scaledOrders,
            'source' => $scenario['source'],
            'target' => $scenario['target'],
            'maxHops' => $scenario['maxHops'],
            'topK' => $scenario['topK'],
            'tolerance' => $scenario['tolerance'],
            'scaleBy' => $scaleFactor,
        ];
    }

    /**
     * @param array{currency: string, edges: list<array{orderSide: OrderSide, grossBaseCapacity: array{min: Money, max: Money}, quoteCapacity: array{min: Money, max: Money}>}>} $graph
     *
     * @return array{min: Money, max: Money, desired: Money}
     */
    private function deriveSpendConstraints(array $graph, string $source): array
    {
        $edges = $graph[$source]['edges'] ?? [];
        self::assertNotSame([], $edges, 'Generated scenario must include spendable edges.');

        $edge = $edges[0];
        $capacity = OrderSide::BUY === $edge['orderSide']
            ? $edge['grossBaseCapacity']
            : $edge['quoteCapacity'];

        $minimum = $capacity['min'];
        $maximum = $capacity['max'];
        $scale = max($minimum->scale(), $maximum->scale());
        $desired = $minimum->add($maximum, $scale)->divide('2', $scale);

        if ($minimum->equals($maximum)) {
            $desired = $maximum;
        }

        return ['min' => $minimum, 'max' => $maximum, 'desired' => $desired];
    }

    /**
     * @param array{min: Money, max: Money, desired: Money} $constraints
     *
     * @return array{min: Money, max: Money, desired: Money}
     */
    private function scaleSpendConstraints(array $constraints, string $scaleFactor): array
    {
        return [
            'min' => $constraints['min']->multiply($scaleFactor),
            'max' => $constraints['max']->multiply($scaleFactor),
            'desired' => $constraints['desired']->multiply($scaleFactor),
        ];
    }

    private function scaleOrder(Order $order, string $scaleFactor): Order
    {
        $bounds = $order->bounds();
        $minimum = $bounds->min()->multiply($scaleFactor);
        $maximum = $bounds->max()->multiply($scaleFactor);

        $normalizedRate = BcMath::normalize(
            $order->effectiveRate()->rate(),
            $order->effectiveRate()->scale(),
        );

        return OrderFactory::createOrder(
            $order->side(),
            $order->assetPair()->base(),
            $order->assetPair()->quote(),
            $minimum->amount(),
            $maximum->amount(),
            $normalizedRate,
            $minimum->scale(),
            $order->effectiveRate()->scale(),
            $order->feePolicy(),
        );
    }

    /**
     * @param array{cost: numeric-string, hops: int, edges: list<array{from: string, to: string, orderSide: OrderSide, order: Order}>} $path
     */
    private function routeSignature(array $path): string
    {
        $segments = array_map(
            static function (array $edge): string {
                /** @var Order $order */
                $order = $edge['order'];

                return $edge['from'].'>'.$edge['to'].'#'.implode(
                    ':',
                    [
                        $edge['orderSide']->value,
                        $order->assetPair()->base(),
                        $order->assetPair()->quote(),
                    ],
                );
            },
            $path['edges'],
        );

        return $path['hops'].'|'.implode(';', $segments);
    }

    private function assertGuardStatusEquals(GuardLimitStatus $expected, GuardLimitStatus $actual, string $message): void
    {
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
