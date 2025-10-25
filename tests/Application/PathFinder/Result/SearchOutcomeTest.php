<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder\Result;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\CostHopsSignatureOrderingStrategy;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathCost;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathOrderKey;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\RouteSignature;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\PathResultSet;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\PathResultSetEntry;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\SearchGuardReport;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\SearchOutcome;

final class SearchOutcomeTest extends TestCase
{
    public function test_empty_factory_returns_result_without_paths(): void
    {
        $status = new SearchGuardReport(false, true, false, 0, 25, 0.0, 10, 25, null);
        $outcome = SearchOutcome::empty($status);

        self::assertTrue($outcome->paths()->isEmpty());
        self::assertFalse($outcome->hasPaths());
        self::assertSame($status, $outcome->guardLimits());
    }

    public function test_paths_accessor_returns_materialized_collection(): void
    {
        $paths = PathResultSet::fromEntries(
            new CostHopsSignatureOrderingStrategy(18),
            [
                new PathResultSetEntry(['id' => 2], new PathOrderKey(new PathCost('2'), 1, new RouteSignature(['B']), 1)),
                new PathResultSetEntry(['id' => 1], new PathOrderKey(new PathCost('1'), 1, new RouteSignature(['A']), 0)),
            ],
        );
        $status = SearchGuardReport::idle(25, 10);
        $outcome = new SearchOutcome($paths, $status);

        self::assertTrue($outcome->hasPaths());
        self::assertSame($paths, $outcome->paths());
        self::assertSame($status, $outcome->guardLimits());
    }
}
