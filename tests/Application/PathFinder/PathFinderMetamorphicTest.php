<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Graph\EdgeSegmentCollection;
use SomeWork\P2PPathFinder\Application\Graph\Graph;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\Graph\GraphEdge;
use SomeWork\P2PPathFinder\Application\PathFinder\PathFinder;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SegmentPruner;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\SpendConstraints;
use SomeWork\P2PPathFinder\Tests\Fixture\FeePolicyFactory;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;
use SomeWork\P2PPathFinder\Tests\Fixture\PathFinderEdgeCaseFixtures;
use SomeWork\P2PPathFinder\Tests\Support\DecimalMath;

final class PathFinderMetamorphicTest extends TestCase
{
    public function test_expansion_budget_relaxation_monotonically_increases_results(): void
    {
        $orderBook = PathFinderEdgeCaseFixtures::longGuardLimitedChain(6);
        $orders = iterator_to_array($orderBook);
        $graph = (new GraphBuilder())->build($orders);

        $limits = [
            'tight' => 3,
            'baseline' => 8,
            'relaxed' => 64,
        ];

        $counts = [];
        $expansions = [];
        $breaches = [];

        foreach ($limits as $label => $limit) {
            $finder = new PathFinder(
                maxHops: 6,
                tolerance: '0.0',
                topK: 1,
                maxExpansions: $limit,
                maxVisitedStates: 100,
            );
            $outcome = $finder->findBestPaths($graph, 'SRC', 'DST');

            $counts[$label] = $outcome->paths()->count();
            $report = $outcome->guardLimits();
            $expansions[$label] = $report->expansions();
            $breaches[$label] = $report->expansionsReached();
        }

        self::assertLessThanOrEqual($counts['relaxed'], $counts['baseline']);
        self::assertLessThanOrEqual($counts['baseline'], $counts['tight']);
        self::assertSame(0, $counts['tight']);
        self::assertGreaterThanOrEqual(1, $counts['baseline']);
        self::assertGreaterThanOrEqual(1, $counts['relaxed']);

        self::assertSame($limits['tight'], $expansions['tight']);
        self::assertTrue($breaches['tight']);
        self::assertFalse($breaches['baseline']);
        self::assertFalse($breaches['relaxed']);

        self::assertGreaterThanOrEqual($expansions['baseline'], $expansions['relaxed']);
    }

    public function test_tolerance_relaxation_expands_residual_spend_envelope(): void
    {
        $orders = [
            OrderFactory::buy('SRC', 'MID', '1.000', '3.000', '1.000', 3, 3, FeePolicyFactory::baseAndQuoteSurcharge('0.10', '0.05', 3)),
            OrderFactory::buy('MID', 'DST', '1.000', '3.000', '1.000', 3, 3, FeePolicyFactory::baseAndQuoteSurcharge('0.10', '0.05', 3)),
            OrderFactory::buy('SRC', 'DST', '1.000', '3.000', '0.980', 3, 3, FeePolicyFactory::baseAndQuoteSurcharge('0.08', '0.02', 3)),
        ];

        $graph = (new GraphBuilder())->build($orders);
        $constraints = SpendConstraints::fromScalars('SRC', '1.000', '2.500');

        $tolerances = [
            'strict' => '0.0',
            'narrow' => '0.05',
            'relaxed' => '0.3',
        ];

        $counts = [];
        $lowerBounds = [];
        $upperBounds = [];

        foreach ($tolerances as $label => $tolerance) {
            $finder = new PathFinder(maxHops: 3, tolerance: $tolerance, topK: 3);
            $outcome = $finder->findBestPaths($graph, 'SRC', 'DST', $constraints);
            $counts[$label] = $outcome->paths()->count();

            $mins = [];
            $maxes = [];

            foreach ($outcome->paths() as $path) {
                $range = $path->range();
                self::assertInstanceOf(SpendConstraints::class, $range);
                $bounds = $range->bounds();

                $mins[] = $bounds['min']->withScale(18)->amount();
                $maxes[] = $bounds['max']->withScale(18)->amount();
            }

            self::assertNotSame([], $mins);
            self::assertNotSame([], $maxes);

            $lowerBounds[$label] = $this->reduceMinimum($mins);
            $upperBounds[$label] = $this->reduceMaximum($maxes);
        }

        self::assertLessThanOrEqual($counts['narrow'], $counts['strict']);
        self::assertLessThanOrEqual($counts['relaxed'], $counts['narrow']);

        self::assertGreaterThanOrEqual(0, DecimalMath::comp($lowerBounds['strict'], $lowerBounds['narrow'], 18));
        self::assertGreaterThanOrEqual(0, DecimalMath::comp($lowerBounds['narrow'], $lowerBounds['relaxed'], 18));

        self::assertLessThanOrEqual(0, DecimalMath::comp($upperBounds['strict'], $upperBounds['narrow'], 18));
        self::assertLessThanOrEqual(0, DecimalMath::comp($upperBounds['narrow'], $upperBounds['relaxed'], 18));

        $directEdge = $this->findDirectEdge($graph, 'SRC', 'DST');
        self::assertInstanceOf(GraphEdge::class, $directEdge);

        $segments = (new SegmentPruner(EdgeSegmentCollection::MEASURE_QUOTE))
            ->prune($directEdge->segmentCollection())
            ->toArray();

        self::assertNotSame([], $segments);
        $mandatory = $segments[0];
        $mandatoryQuoteMax = $mandatory->quote()->max()->withScale(18)->amount();

        self::assertGreaterThanOrEqual(0, DecimalMath::comp($mandatoryQuoteMax, $lowerBounds['relaxed'], 18));
    }

    /**
     * @param list<string> $values
     */
    private function reduceMinimum(array $values): string
    {
        if ([] === $values) {
            $this->fail('Expected at least one value to determine the minimum.');
        }

        $minimum = $values[0];

        foreach ($values as $value) {
            if (1 === DecimalMath::comp($minimum, $value, 18)) {
                $minimum = $value;
            }
        }

        return $minimum;
    }

    /**
     * @param list<string> $values
     */
    private function reduceMaximum(array $values): string
    {
        if ([] === $values) {
            $this->fail('Expected at least one value to determine the maximum.');
        }

        $maximum = $values[0];

        foreach ($values as $value) {
            if (-1 === DecimalMath::comp($maximum, $value, 18)) {
                $maximum = $value;
            }
        }

        return $maximum;
    }

    private function findDirectEdge(Graph $graph, string $from, string $to): ?GraphEdge
    {
        $node = $graph->node($from);

        if (null === $node) {
            return null;
        }

        foreach ($node->edges() as $edge) {
            if ($edge->to() === $to) {
                return $edge;
            }
        }

        return null;
    }
}
