<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\PathFinder\PathFinder;
use SomeWork\P2PPathFinder\Tests\Application\Support\Generator\PathFinderScenarioGenerator;

use function array_reverse;
use function array_unique;
use function count;
use function spl_object_id;

final class PathFinderPropertyTest extends TestCase
{
    private PathFinderScenarioGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generator = new PathFinderScenarioGenerator();
    }

    public function test_generated_graphs_produce_deterministic_unique_paths(): void
    {
        $graphBuilder = new GraphBuilder();

        for ($iteration = 0; $iteration < 25; ++$iteration) {
            $scenario = $this->generator->scenario();

            $graph = $graphBuilder->build($scenario['orders']);
            $finder = new PathFinder(
                maxHops: $scenario['maxHops'],
                tolerance: $scenario['tolerance'],
                topK: $scenario['topK'],
            );

            $firstResult = $finder->findBestPaths($graph, $scenario['source'], $scenario['target']);
            $secondResult = $finder->findBestPaths($graph, $scenario['source'], $scenario['target']);

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

            $permutedPaths = $permutedFinder->findBestPaths(
                $permutedGraph,
                $scenario['source'],
                $scenario['target'],
            )->paths();

            self::assertSame(
                $firstPaths,
                $permutedPaths,
                'Order book permutations must not change search results.',
            );
        }
    }
}
