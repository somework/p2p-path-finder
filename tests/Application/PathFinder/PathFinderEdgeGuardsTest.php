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

    /**
     * @dataProvider provideMissingEndpoints
     */
    public function test_it_returns_empty_outcome_when_source_or_target_missing(string $source, string $target): void
    {
        $order = OrderFactory::buy(
            base: 'USD',
            quote: 'EUR',
            minAmount: '1.000',
            maxAmount: '2.000',
            rate: '0.900',
            amountScale: 3,
            rateScale: 3,
        );

        $graph = (new GraphBuilder())->build([$order]);
        $finder = new PathFinder(2, '0.0');

        $outcome = $finder->findBestPaths($graph, $source, $target);

        self::assertSame([], $outcome->paths());
        self::assertFalse($outcome->guardLimits()->expansionsReached());
        self::assertFalse($outcome->guardLimits()->visitedStatesReached());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideMissingEndpoints(): iterable
    {
        yield 'missing_source' => ['GBP', 'EUR'];
        yield 'missing_target' => ['USD', 'JPY'];
    }

    public function test_missing_target_does_not_trigger_guard_limits(): void
    {
        $orders = [
            OrderFactory::buy(
                base: 'AAA',
                quote: 'BBB',
                minAmount: '1.000',
                maxAmount: '1.000',
                rate: '1.000',
                amountScale: 3,
                rateScale: 3,
            ),
        ];

        $graph = (new GraphBuilder())->build($orders);
        $finder = new PathFinder(maxHops: 2, tolerance: '0.0', topK: 1, maxExpansions: 5, maxVisitedStates: 1);

        $outcome = $finder->findBestPaths($graph, 'AAA', 'ZZZ');

        self::assertSame([], $outcome->paths());
        self::assertFalse($outcome->guardLimits()->expansionsReached());
        self::assertFalse($outcome->guardLimits()->visitedStatesReached());
    }

    public function test_it_marks_expansion_guard_when_limit_reached(): void
    {
        $order = OrderFactory::buy(
            base: 'AAA',
            quote: 'BBB',
            minAmount: '1.000',
            maxAmount: '1.000',
            rate: '1.000',
            amountScale: 3,
            rateScale: 3,
        );

        $graph = (new GraphBuilder())->build([$order]);
        $finder = new PathFinder(maxHops: 2, tolerance: '0.0', topK: 1, maxExpansions: 1, maxVisitedStates: 10);

        $outcome = $finder->findBestPaths($graph, 'AAA', 'BBB');

        self::assertSame([], $outcome->paths());
        self::assertTrue($outcome->guardLimits()->expansionsReached());
        self::assertFalse($outcome->guardLimits()->visitedStatesReached());
    }

    public function test_it_marks_visited_guard_when_limit_reached(): void
    {
        $order = OrderFactory::buy(
            base: 'AAA',
            quote: 'BBB',
            minAmount: '1.000',
            maxAmount: '1.000',
            rate: '1.000',
            amountScale: 3,
            rateScale: 3,
        );

        $graph = (new GraphBuilder())->build([$order]);
        $finder = new PathFinder(maxHops: 2, tolerance: '0.0', topK: 1, maxExpansions: 5, maxVisitedStates: 1);

        $outcome = $finder->findBestPaths($graph, 'AAA', 'BBB');

        self::assertSame([], $outcome->paths());
        self::assertFalse($outcome->guardLimits()->expansionsReached());
        self::assertTrue($outcome->guardLimits()->visitedStatesReached());
    }

    public function test_it_prunes_candidates_exceeding_best_cost_without_tolerance(): void
    {
        $orders = [
            OrderFactory::buy(
                base: 'AAA',
                quote: 'BBB',
                minAmount: '1.000',
                maxAmount: '1.000',
                rate: '2.000',
                amountScale: 3,
                rateScale: 3,
            ),
            OrderFactory::buy(
                base: 'AAA',
                quote: 'CCC',
                minAmount: '1.000',
                maxAmount: '1.000',
                rate: '0.500',
                amountScale: 3,
                rateScale: 3,
            ),
            OrderFactory::buy(
                base: 'CCC',
                quote: 'BBB',
                minAmount: '1.000',
                maxAmount: '1.000',
                rate: '0.500',
                amountScale: 3,
                rateScale: 3,
            ),
        ];

        $graph = (new GraphBuilder())->build($orders);
        $finder = new PathFinder(maxHops: 3, tolerance: '0.0', topK: 2);

        $paths = $finder->findBestPaths($graph, 'AAA', 'BBB')->paths();

        self::assertCount(1, $paths);
        $firstPath = $paths[0];
        self::assertCount(1, $firstPath['edges']);
        self::assertSame('AAA', $firstPath['edges'][0]['from']);
        self::assertSame('BBB', $firstPath['edges'][0]['to']);
    }

    public function test_it_does_not_relax_best_cost_after_worse_candidate(): void
    {
        $orders = [
            OrderFactory::buy(
                base: 'AAA',
                quote: 'CCC',
                minAmount: '1.000',
                maxAmount: '1.000',
                rate: '2.000',
                amountScale: 3,
                rateScale: 3,
            ),
            OrderFactory::buy(
                base: 'CCC',
                quote: 'BBB',
                minAmount: '1.000',
                maxAmount: '1.000',
                rate: '2.000',
                amountScale: 3,
                rateScale: 3,
            ),
            OrderFactory::buy(
                base: 'AAA',
                quote: 'BBB',
                minAmount: '1.000',
                maxAmount: '1.000',
                rate: '0.500',
                amountScale: 3,
                rateScale: 3,
            ),
            OrderFactory::buy(
                base: 'AAA',
                quote: 'DDD',
                minAmount: '1.000',
                maxAmount: '1.000',
                rate: '0.200',
                amountScale: 3,
                rateScale: 3,
            ),
            OrderFactory::buy(
                base: 'DDD',
                quote: 'BBB',
                minAmount: '1.000',
                maxAmount: '1.000',
                rate: '5.000',
                amountScale: 3,
                rateScale: 3,
            ),
        ];

        $graph = (new GraphBuilder())->build($orders);
        $finder = new PathFinder(maxHops: 3, tolerance: '0.0', topK: 3);

        $paths = $finder->findBestPaths($graph, 'AAA', 'BBB')->paths();

        self::assertCount(2, $paths);
        self::assertSame(
            ['0.250000000000000000', '2.000000000000000000'],
            array_column($paths, 'cost'),
        );
    }

    public function test_it_prunes_states_after_best_candidate_improves(): void
    {
        $orders = [
            OrderFactory::buy(
                base: 'AAA',
                quote: 'BBB',
                minAmount: '1.000',
                maxAmount: '1.000',
                rate: '2.000',
                amountScale: 3,
                rateScale: 3,
            ),
            OrderFactory::buy(
                base: 'BBB',
                quote: 'CCC',
                minAmount: '1.000',
                maxAmount: '1.000',
                rate: '1.000',
                amountScale: 3,
                rateScale: 3,
            ),
            OrderFactory::buy(
                base: 'CCC',
                quote: 'ZZZ',
                minAmount: '1.000',
                maxAmount: '1.000',
                rate: '1.000',
                amountScale: 3,
                rateScale: 3,
            ),
            OrderFactory::buy(
                base: 'AAA',
                quote: 'DDD',
                minAmount: '1.000',
                maxAmount: '1.000',
                rate: '1.100',
                amountScale: 3,
                rateScale: 3,
            ),
            OrderFactory::buy(
                base: 'DDD',
                quote: 'ZZZ',
                minAmount: '1.000',
                maxAmount: '1.000',
                rate: '1.000',
                amountScale: 3,
                rateScale: 3,
            ),
        ];

        $graph = (new GraphBuilder())->build($orders);
        $finder = new PathFinder(maxHops: 3, tolerance: '0.0', topK: 3);

        $paths = $finder->findBestPaths($graph, 'AAA', 'ZZZ')->paths();

        self::assertSame(
            ['0.500000000000000000'],
            array_column($paths, 'cost'),
        );
    }
}
