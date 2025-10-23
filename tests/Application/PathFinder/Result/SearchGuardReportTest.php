<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder\Result;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\SearchGuardReport;

final class SearchGuardReportTest extends TestCase
{
    public function test_idle_factory_initializes_limits_and_counters(): void
    {
        $report = SearchGuardReport::idle(maxVisitedStates: 25, maxExpansions: 10, timeBudgetMs: 50);

        self::assertSame(25, $report->visitedStateLimit());
        self::assertSame(10, $report->expansionLimit());
        self::assertSame(50, $report->timeBudgetLimit());
        self::assertSame(0, $report->visitedStates());
        self::assertSame(0, $report->expansions());
        self::assertSame(0.0, $report->elapsedMilliseconds());
        self::assertFalse($report->expansionsReached());
        self::assertFalse($report->visitedStatesReached());
        self::assertFalse($report->timeBudgetReached());
        self::assertFalse($report->anyLimitReached());
    }

    public function test_idle_factory_defaults_time_budget_limit_to_null_when_not_provided(): void
    {
        $report = SearchGuardReport::idle(maxVisitedStates: 25, maxExpansions: 10);

        self::assertSame(25, $report->visitedStateLimit());
        self::assertSame(10, $report->expansionLimit());
        self::assertNull($report->timeBudgetLimit());
        self::assertSame(0, $report->visitedStates());
        self::assertSame(0, $report->expansions());
        self::assertSame(0.0, $report->elapsedMilliseconds());
        self::assertFalse($report->expansionsReached());
        self::assertFalse($report->visitedStatesReached());
        self::assertFalse($report->timeBudgetReached());
        self::assertFalse($report->anyLimitReached());
    }

    public function test_none_factory_returns_neutral_report(): void
    {
        $report = SearchGuardReport::none();

        self::assertSame(0, $report->visitedStateLimit());
        self::assertSame(0, $report->expansionLimit());
        self::assertNull($report->timeBudgetLimit());
        self::assertSame(0, $report->visitedStates());
        self::assertSame(0, $report->expansions());
        self::assertSame(0.0, $report->elapsedMilliseconds());
        self::assertFalse($report->expansionsReached());
        self::assertFalse($report->visitedStatesReached());
        self::assertFalse($report->timeBudgetReached());
        self::assertFalse($report->anyLimitReached());
    }

    public function test_any_limit_reports_true_when_guard_triggers(): void
    {
        $expansions = new SearchGuardReport(
            expansionsReached: true,
            visitedStatesReached: false,
            timeBudgetReached: false,
            expansions: 10,
            visitedStates: 0,
            elapsedMilliseconds: 0.0,
            expansionLimit: 10,
            visitedStateLimit: 25,
            timeBudgetLimit: null,
        );
        $visited = new SearchGuardReport(
            expansionsReached: false,
            visitedStatesReached: true,
            timeBudgetReached: false,
            expansions: 0,
            visitedStates: 25,
            elapsedMilliseconds: 0.0,
            expansionLimit: 10,
            visitedStateLimit: 25,
            timeBudgetLimit: null,
        );
        $time = new SearchGuardReport(
            expansionsReached: false,
            visitedStatesReached: false,
            timeBudgetReached: true,
            expansions: 0,
            visitedStates: 0,
            elapsedMilliseconds: 25.0,
            expansionLimit: 10,
            visitedStateLimit: 25,
            timeBudgetLimit: 25,
        );

        self::assertTrue($expansions->anyLimitReached());
        self::assertTrue($visited->anyLimitReached());
        self::assertTrue($time->anyLimitReached());

        self::assertTrue($expansions->expansionsReached());
        self::assertFalse($expansions->visitedStatesReached());
        self::assertFalse($expansions->timeBudgetReached());
        self::assertTrue($visited->visitedStatesReached());
        self::assertFalse($visited->expansionsReached());
        self::assertFalse($visited->timeBudgetReached());
        self::assertTrue($time->timeBudgetReached());
        self::assertFalse($time->expansionsReached());
        self::assertFalse($time->visitedStatesReached());
    }
}
