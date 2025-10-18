<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\PathFinder\PathFinder;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

final class PathFinderEdgeGuardsTest extends TestCase
{
    public function test_it_ignores_edges_with_non_positive_conversion_rate(): void
    {
        $order = OrderFactory::sell(
            base: 'USD',
            quote: 'EUR',
            minAmount: '10.000',
            maxAmount: '25.000',
            rate: '0.850',
            amountScale: 3,
            rateScale: 3,
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $graph['EUR']['edges'][0];

        $edge['quoteCapacity']['min'] = Money::zero('USD', 3);
        $edge['quoteCapacity']['max'] = Money::zero('USD', 3);
        $edge['grossBaseCapacity']['min'] = Money::zero('EUR', 3);
        $edge['grossBaseCapacity']['max'] = Money::zero('EUR', 3);
        $graph['EUR']['edges'][0] = $edge;

        $finder = new PathFinder(2, '0.0', 3, 8, 8);

        $outcome = $finder->findBestPaths(
            $graph,
            'EUR',
            'USD',
            [
                'min' => Money::fromString('EUR', '10.000', 3),
                'max' => Money::fromString('EUR', '20.000', 3),
                'desired' => Money::fromString('EUR', '12.000', 3),
            ],
        );

        self::assertSame([], $outcome->paths());
        self::assertFalse($outcome->guardLimits()->expansionsReached());
        self::assertFalse($outcome->guardLimits()->visitedStatesReached());
    }
}
