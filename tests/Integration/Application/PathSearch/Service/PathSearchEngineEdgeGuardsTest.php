<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Integration\Application\PathSearch\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\CandidateSearchOutcome;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\PathSearchEngine;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\CandidatePath;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;
use SomeWork\P2PPathFinder\Domain\Money\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

#[CoversClass(PathSearchEngine::class)]
final class PathSearchEngineEdgeGuardsTest extends TestCase
{
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
        $finder = new PathSearchEngine(maxHops: 2, tolerance: '0.0', topK: 1, maxExpansions: 1, maxVisitedStates: 10);

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
        $finder = new PathSearchEngine(maxHops: 2, tolerance: '0.0', topK: 1, maxExpansions: 5, maxVisitedStates: 1);

        $outcome = $finder->findBestPaths($graph, 'AAA', 'BBB');

        self::assertSame([], self::extractPaths($outcome));
        $report = $outcome->guardLimits();
        self::assertFalse($report->expansionsReached());
        self::assertTrue($report->visitedStatesReached());
        self::assertSame(1, $report->visitedStates());
        self::assertSame(1, $report->visitedStateLimit());
    }

    /**
     * @return list<array{cost: numeric-string, product: numeric-string, hops: int, edges: list<array{from: string, to: string, order: Order, rate: ExchangeRate, orderSide: OrderSide, conversionRate: numeric-string}>, amountRange: array{min: Money, max: Money}|null, desiredAmount: Money|null}>
     */
    private static function extractPaths(CandidateSearchOutcome $outcome): array
    {
        return array_map(
            static fn (CandidatePath $path): array => $path->toArray(),
            $outcome->paths()->toArray(),
        );
    }
}
