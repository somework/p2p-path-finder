<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Api\Response;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Api\Response\SearchOutcome;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\CostHopsSignatureOrderingStrategy;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathCost;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathOrderKey;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\RouteSignature;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathResultSet;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\SearchGuardReport;
use SomeWork\P2PPathFinder\Tests\Helpers\DecimalFactory;

final class SearchOutcomeTest extends TestCase
{
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
        $outcome = SearchOutcome::empty($status);

        self::assertTrue($outcome->paths()->isEmpty());
        self::assertFalse($outcome->hasPaths());
        self::assertSame($status, $outcome->guardLimits());
    }

    public function test_paths_accessor_returns_materialized_collection(): void
    {
        $orderKeys = [
            new PathOrderKey(new PathCost(DecimalFactory::decimal('2')), 1, RouteSignature::fromNodes(['B']), 1),
            new PathOrderKey(new PathCost(DecimalFactory::decimal('1')), 1, RouteSignature::fromNodes(['A']), 0),
        ];

        $paths = PathResultSet::fromPaths(
            new CostHopsSignatureOrderingStrategy(18),
            [
                ['id' => 2],
                ['id' => 1],
            ],
            static fn (array $path, int $index): PathOrderKey => $orderKeys[$index],
        );
        $status = SearchGuardReport::idle(25, 10);
        $outcome = SearchOutcome::fromResultSet($paths, $status);

        self::assertTrue($outcome->hasPaths());
        self::assertSame($paths, $outcome->paths());
        self::assertSame($status, $outcome->guardLimits());
    }

    public function test_json_serialize_returns_paths_and_guard_report_payload(): void
    {
        $orderKeys = [
            new PathOrderKey(new PathCost(DecimalFactory::decimal('2')), 1, RouteSignature::fromNodes(['C']), 1),
            new PathOrderKey(new PathCost(DecimalFactory::decimal('1')), 1, RouteSignature::fromNodes(['B']), 0),
        ];

        $paths = PathResultSet::fromPaths(
            new CostHopsSignatureOrderingStrategy(18),
            [
                ['id' => 3],
                ['id' => 2],
            ],
            static fn (array $path, int $index): PathOrderKey => $orderKeys[$index],
        );
        $status = SearchGuardReport::fromMetrics(
            expansions: 5,
            visitedStates: 7,
            elapsedMilliseconds: 12.5,
            expansionLimit: 10,
            visitedStateLimit: 12,
            timeBudgetLimit: 50,
            expansionLimitReached: true,
        );

        $outcome = SearchOutcome::fromResultSet($paths, $status);

        $payload = $outcome->jsonSerialize();

        self::assertSame($paths->jsonSerialize(), $payload['paths']);
        self::assertSame($status->jsonSerialize(), $payload['guards']);
    }
}
