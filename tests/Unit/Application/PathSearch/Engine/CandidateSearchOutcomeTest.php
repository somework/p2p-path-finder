<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Engine;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\CandidateSearchOutcome;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\CostHopsSignatureOrderingStrategy;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathCost;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathOrderKey;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\RouteSignature;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\CandidatePath;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\PathEdgeSequence;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathResultSet;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\SearchGuardReport;
use SomeWork\P2PPathFinder\Tests\Helpers\DecimalFactory;

final class CandidateSearchOutcomeTest extends TestCase
{
    private const SCALE = 18;

    public function test_empty_factory_returns_result_without_paths(): void
    {
        $status = SearchGuardReport::fromMetrics(
            expansions: 0,
            visitedStates: 25,
            elapsedMilliseconds: 0.0,
            expansionLimit: 10,
            visitedStateLimit: 25,
            timeBudgetLimit: null,
        );
        $outcome = CandidateSearchOutcome::empty($status);

        self::assertTrue($outcome->paths()->isEmpty());
        self::assertFalse($outcome->hasPaths());
        self::assertNull($outcome->bestPath());
        self::assertSame($status, $outcome->guardLimits());
    }

    public function test_paths_accessor_returns_candidate_collection(): void
    {
        $orderKeys = [
            new PathOrderKey(new PathCost(DecimalFactory::decimal('2')), 1, RouteSignature::fromNodes(['B']), 1),
            new PathOrderKey(new PathCost(DecimalFactory::decimal('1')), 1, RouteSignature::fromNodes(['A']), 0),
        ];

        $firstPath = $this->simpleCandidatePath('0.9');
        $secondPath = $this->simpleCandidatePath('0.75');

        $paths = PathResultSet::fromPaths(
            new CostHopsSignatureOrderingStrategy(self::SCALE),
            [
                $firstPath,
                $secondPath,
            ],
            static fn (CandidatePath $_path, int $index): PathOrderKey => $orderKeys[$index],
        );
        $status = SearchGuardReport::idle(25, 10);
        $outcome = CandidateSearchOutcome::fromResultSet($paths, $status);

        self::assertTrue($outcome->hasPaths());
        self::assertSame($paths, $outcome->paths());
        self::assertSame($secondPath, $outcome->bestPath());
        self::assertSame($status, $outcome->guardLimits());
    }

    public function test_constructor_stores_provided_arguments(): void
    {
        $status = SearchGuardReport::idle(25, 10);
        $paths = PathResultSet::empty();
        $outcome = new CandidateSearchOutcome($paths, $status);

        self::assertSame($paths, $outcome->paths());
        self::assertSame($status, $outcome->guardLimits());
    }

    private function simpleCandidatePath(string $cost): CandidatePath
    {
        return CandidatePath::from(
            DecimalFactory::decimal($cost),
            DecimalFactory::decimal('1'), // product
            0, // hops (empty path)
            PathEdgeSequence::empty(),
            null, // amountRange
        );
    }
}
