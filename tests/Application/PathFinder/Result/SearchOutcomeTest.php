<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder\Result;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\GuardLimitStatus;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\SearchOutcome;

final class SearchOutcomeTest extends TestCase
{
    public function test_empty_factory_returns_result_without_paths(): void
    {
        $status = new GuardLimitStatus(false, true, false);
        $outcome = SearchOutcome::empty($status);

        self::assertSame([], $outcome->paths());
        self::assertFalse($outcome->hasPaths());
        self::assertSame($status, $outcome->guardLimits());
    }

    public function test_paths_accessor_returns_materialized_collection(): void
    {
        $paths = [['id' => 1], ['id' => 2]];
        $status = new GuardLimitStatus(false, false, false);
        $outcome = new SearchOutcome($paths, $status);

        self::assertTrue($outcome->hasPaths());
        self::assertSame($paths, $outcome->paths());
        self::assertSame($status, $outcome->guardLimits());
    }
}
