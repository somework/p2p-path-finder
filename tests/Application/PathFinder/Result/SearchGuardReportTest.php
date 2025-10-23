<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder\Result;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\SearchGuardReport;

final class SearchGuardReportTest extends TestCase
{
    public function test_idle_factory_initializes_limits_and_counters(): void
    {
        $report = SearchGuardReport::idle(25, 10, 50);

        self::assertSame(25, $report->visitedStateLimit());
        self::assertSame(10, $report->expansionLimit());
        self::assertSame(50, $report->timeBudgetLimit());
        self::assertSame(0, $report->visitedStates());
        self::assertSame(0, $report->expansions());
        self::assertSame(0.0, $report->elapsedMilliseconds());
        self::assertFalse($report->anyLimitReached());
    }

    public function test_any_limit_reports_true_when_guard_triggers(): void
    {
        $expansions = new SearchGuardReport(true, false, false, 10, 0, 0.0, 10, 25, null);
        $visited = new SearchGuardReport(false, true, false, 0, 25, 0.0, 10, 25, null);
        $time = new SearchGuardReport(false, false, true, 0, 0, 25.0, 10, 25, 25);

        self::assertTrue($expansions->anyLimitReached());
        self::assertTrue($visited->anyLimitReached());
        self::assertTrue($time->anyLimitReached());

        self::assertTrue($expansions->expansionsReached());
        self::assertTrue($visited->visitedStatesReached());
        self::assertTrue($time->timeBudgetReached());
    }
}
