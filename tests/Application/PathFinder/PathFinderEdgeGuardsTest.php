<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Config\SearchGuardConfig;
use SomeWork\P2PPathFinder\Application\Graph\EdgeCapacity;
use SomeWork\P2PPathFinder\Application\Graph\Graph;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\Graph\GraphEdge;
use SomeWork\P2PPathFinder\Application\Graph\GraphNode;
use SomeWork\P2PPathFinder\Application\PathFinder\PathFinder;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\SearchOutcome;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\CandidatePath;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\SpendConstraints;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

use function sprintf;

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
        $edge = $this->edge($graph, 'EUR', 0);

        $mutatedEdge = new GraphEdge(
            $edge->from(),
            $edge->to(),
            $edge->orderSide(),
            $edge->order(),
            $edge->rate(),
            $edge->baseCapacity(),
            new EdgeCapacity(Money::zero('EUR', 3), Money::zero('EUR', 3)),
            new EdgeCapacity(Money::zero('USD', 3), Money::zero('USD', 3)),
        );

        $graph = new Graph([
            'EUR' => new GraphNode('EUR', [$mutatedEdge]),
            'USD' => new GraphNode('USD', []),
        ]);

        $finder = new PathFinder(2, '0.0', 3, 8, 8);

        $constraints = SpendConstraints::from(
            Money::fromString('EUR', '10.000', 3),
            Money::fromString('EUR', '20.000', 3),
            Money::fromString('EUR', '12.000', 3),
        );

        $outcome = $finder->findBestPaths(
            $graph,
            'EUR',
            'USD',
            $constraints,
        );

        self::assertSame([], self::extractPaths($outcome));
        $report = $outcome->guardLimits();
        self::assertFalse($report->expansionsReached());
        self::assertFalse($report->visitedStatesReached());
        self::assertSame(8, $report->expansionLimit());
        self::assertSame(8, $report->visitedStateLimit());
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

        self::assertSame([], self::extractPaths($outcome));
        $report = $outcome->guardLimits();
        self::assertFalse($report->expansionsReached());
        self::assertFalse($report->visitedStatesReached());
        self::assertSame(SearchGuardConfig::DEFAULT_MAX_EXPANSIONS, $report->expansionLimit());
        self::assertSame(SearchGuardConfig::DEFAULT_MAX_VISITED_STATES, $report->visitedStateLimit());
        self::assertSame(0, $report->expansions());
        self::assertSame(0, $report->visitedStates());
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

        self::assertSame([], self::extractPaths($outcome));
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

        self::assertSame([], self::extractPaths($outcome));
        $report = $outcome->guardLimits();
        self::assertTrue($report->expansionsReached());
        self::assertFalse($report->visitedStatesReached());
        self::assertSame(1, $report->expansions());
        self::assertSame(1, $report->expansionLimit());
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

        self::assertSame([], self::extractPaths($outcome));
        $report = $outcome->guardLimits();
        self::assertFalse($report->expansionsReached());
        self::assertTrue($report->visitedStatesReached());
        self::assertSame(1, $report->visitedStates());
        self::assertSame(1, $report->visitedStateLimit());
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

        $paths = $finder->findBestPaths($graph, 'AAA', 'BBB')->paths()->toArray();

        self::assertCount(1, $paths);
        $firstPath = $paths[0];
        $edges = $firstPath->edges()->toArray();
        self::assertCount(1, $edges);
        self::assertSame('AAA', $edges[0]['from']);
        self::assertSame('BBB', $edges[0]['to']);
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

        $paths = $finder->findBestPaths($graph, 'AAA', 'BBB')->paths()->toArray();

        self::assertCount(2, $paths);
        $costs = array_map(static fn (CandidatePath $path): string => $path->cost(), $paths);

        self::assertSame(
            ['0.250000000000000000', '2.000000000000000000'],
            $costs,
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

        $paths = $finder->findBestPaths($graph, 'AAA', 'ZZZ')->paths()->toArray();

        $costs = array_map(static fn (CandidatePath $path): string => $path->cost(), $paths);

        self::assertSame(
            ['0.500000000000000000'],
            $costs,
        );
    }

    private function edge(Graph $graph, string $currency, int $index): GraphEdge
    {
        $node = $graph->node($currency);
        self::assertNotNull($node, sprintf('Graph is missing node for currency "%s".', $currency));

        return $node->edges()->at($index);
    }

    /**
     * @return list<array{cost: numeric-string, product: numeric-string, hops: int, edges: list<array{from: string, to: string, order: \SomeWork\P2PPathFinder\Domain\Order\Order, rate: \SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate, orderSide: \SomeWork\P2PPathFinder\Domain\Order\OrderSide, conversionRate: numeric-string}>, amountRange: array{min: Money, max: Money}|null, desiredAmount: Money|null}>
     */
    private static function extractPaths(SearchOutcome $outcome): array
    {
        return array_map(
            static fn (CandidatePath $path): array => $path->toArray(),
            $outcome->paths()->toArray(),
        );
    }
}
