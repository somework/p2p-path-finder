<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Service\PathFinder;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Application\Result\PathResult;
use SomeWork\P2PPathFinder\Application\Service\PathFinderService;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Tests\Application\Support\Generator\PathFinderScenarioGenerator;
use SomeWork\P2PPathFinder\Tests\Support\InfectionIterationLimiter;

use function array_map;
use function array_unique;
use function count;
use function max;
use function serialize;

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
        }
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
}
