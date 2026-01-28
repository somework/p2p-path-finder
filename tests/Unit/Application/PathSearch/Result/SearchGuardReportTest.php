<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Result;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\SearchGuardReport;

#[CoversClass(SearchGuardReport::class)]
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

    public function test_from_metrics_normalizes_counters_and_detects_breaches(): void
    {
        $report = SearchGuardReport::fromMetrics(
            expansions: 11,
            visitedStates: 6,
            elapsedMilliseconds: 15.5,
            expansionLimit: 10,
            visitedStateLimit: 5,
            timeBudgetLimit: 15,
        );

        self::assertTrue($report->expansionsReached());
        self::assertTrue($report->visitedStatesReached());
        self::assertTrue($report->timeBudgetReached());
        self::assertSame(11, $report->expansions());
        self::assertSame(6, $report->visitedStates());
        self::assertSame(10, $report->expansionLimit());
        self::assertSame(5, $report->visitedStateLimit());
        self::assertSame(15, $report->timeBudgetLimit());
        self::assertEqualsWithDelta(15.5, $report->elapsedMilliseconds(), 0.0001);
    }

    public function test_from_metrics_respects_external_guard_flags(): void
    {
        $report = SearchGuardReport::fromMetrics(
            expansions: 2,
            visitedStates: 3,
            elapsedMilliseconds: 1.0,
            expansionLimit: 10,
            visitedStateLimit: 5,
            timeBudgetLimit: null,
            expansionLimitReached: true,
            visitedStatesReached: true,
        );

        self::assertTrue($report->expansionsReached());
        self::assertTrue($report->visitedStatesReached());
        self::assertFalse($report->timeBudgetReached());
    }

    public function test_from_metrics_clamps_negative_expansions_to_zero(): void
    {
        $report = SearchGuardReport::fromMetrics(
            expansions: -5,
            visitedStates: 10,
            elapsedMilliseconds: 5.0,
            expansionLimit: 100,
            visitedStateLimit: 100,
            timeBudgetLimit: null,
        );

        self::assertSame(0, $report->expansions());
    }

    public function test_from_metrics_clamps_negative_visited_states_to_zero(): void
    {
        $report = SearchGuardReport::fromMetrics(
            expansions: 10,
            visitedStates: -3,
            elapsedMilliseconds: 5.0,
            expansionLimit: 100,
            visitedStateLimit: 100,
            timeBudgetLimit: null,
        );

        self::assertSame(0, $report->visitedStates());
    }

    public function test_from_metrics_clamps_negative_elapsed_time_to_zero(): void
    {
        $report = SearchGuardReport::fromMetrics(
            expansions: 10,
            visitedStates: 10,
            elapsedMilliseconds: -1.5,
            expansionLimit: 100,
            visitedStateLimit: 100,
            timeBudgetLimit: null,
        );

        self::assertSame(0.0, $report->elapsedMilliseconds());
    }

    public function test_from_metrics_clamps_negative_limits_to_zero(): void
    {
        $report = SearchGuardReport::fromMetrics(
            expansions: 10,
            visitedStates: 10,
            elapsedMilliseconds: 5.0,
            expansionLimit: -50,
            visitedStateLimit: -100,
            timeBudgetLimit: -20,
        );

        self::assertSame(0, $report->expansionLimit());
        self::assertSame(0, $report->visitedStateLimit());
        self::assertSame(0, $report->timeBudgetLimit());
    }

    public function test_from_metrics_detects_all_limits_reached_simultaneously(): void
    {
        $report = SearchGuardReport::fromMetrics(
            expansions: 100,
            visitedStates: 200,
            elapsedMilliseconds: 50.0,
            expansionLimit: 100,
            visitedStateLimit: 200,
            timeBudgetLimit: 50,
        );

        self::assertTrue($report->expansionsReached());
        self::assertTrue($report->visitedStatesReached());
        self::assertTrue($report->timeBudgetReached());
        self::assertTrue($report->anyLimitReached());
    }

    public function test_from_metrics_handles_very_high_limits(): void
    {
        $report = SearchGuardReport::fromMetrics(
            expansions: 100,
            visitedStates: 200,
            elapsedMilliseconds: 5.0,
            expansionLimit: 1000000,
            visitedStateLimit: 1000000,
            timeBudgetLimit: 60000,
        );

        self::assertFalse($report->expansionsReached());
        self::assertFalse($report->visitedStatesReached());
        self::assertFalse($report->timeBudgetReached());
        self::assertFalse($report->anyLimitReached());
    }

    // ========================================================================
    // aggregate() Tests - Top-K Support
    // ========================================================================

    #[TestDox('aggregate returns none report for empty array')]
    public function test_aggregate_returns_none_for_empty_array(): void
    {
        $result = SearchGuardReport::aggregate([]);

        self::assertSame(0, $result->expansions());
        self::assertSame(0, $result->visitedStates());
        self::assertSame(0.0, $result->elapsedMilliseconds());
        self::assertSame(0, $result->expansionLimit());
        self::assertSame(0, $result->visitedStateLimit());
        self::assertNull($result->timeBudgetLimit());
        self::assertFalse($result->anyLimitReached());
    }

    #[TestDox('aggregate returns same report for single-element array')]
    public function test_aggregate_returns_same_for_single_report(): void
    {
        $report = SearchGuardReport::fromMetrics(
            expansions: 10,
            visitedStates: 20,
            elapsedMilliseconds: 5.5,
            expansionLimit: 100,
            visitedStateLimit: 200,
            timeBudgetLimit: 50,
        );

        $result = SearchGuardReport::aggregate([$report]);

        self::assertSame($report, $result);
    }

    #[TestDox('aggregate sums expansions across multiple reports')]
    public function test_aggregate_sums_expansions(): void
    {
        $report1 = SearchGuardReport::fromMetrics(
            expansions: 10,
            visitedStates: 0,
            elapsedMilliseconds: 0.0,
            expansionLimit: 100,
            visitedStateLimit: 100,
            timeBudgetLimit: null,
        );
        $report2 = SearchGuardReport::fromMetrics(
            expansions: 25,
            visitedStates: 0,
            elapsedMilliseconds: 0.0,
            expansionLimit: 100,
            visitedStateLimit: 100,
            timeBudgetLimit: null,
        );
        $report3 = SearchGuardReport::fromMetrics(
            expansions: 15,
            visitedStates: 0,
            elapsedMilliseconds: 0.0,
            expansionLimit: 100,
            visitedStateLimit: 100,
            timeBudgetLimit: null,
        );

        $result = SearchGuardReport::aggregate([$report1, $report2, $report3]);

        self::assertSame(50, $result->expansions());
    }

    #[TestDox('aggregate sums visited states across multiple reports')]
    public function test_aggregate_sums_visited_states(): void
    {
        $report1 = SearchGuardReport::fromMetrics(
            expansions: 0,
            visitedStates: 100,
            elapsedMilliseconds: 0.0,
            expansionLimit: 100,
            visitedStateLimit: 1000,
            timeBudgetLimit: null,
        );
        $report2 = SearchGuardReport::fromMetrics(
            expansions: 0,
            visitedStates: 250,
            elapsedMilliseconds: 0.0,
            expansionLimit: 100,
            visitedStateLimit: 1000,
            timeBudgetLimit: null,
        );

        $result = SearchGuardReport::aggregate([$report1, $report2]);

        self::assertSame(350, $result->visitedStates());
    }

    #[TestDox('aggregate sums elapsed milliseconds across multiple reports')]
    public function test_aggregate_sums_elapsed_time(): void
    {
        $report1 = SearchGuardReport::fromMetrics(
            expansions: 0,
            visitedStates: 0,
            elapsedMilliseconds: 10.5,
            expansionLimit: 100,
            visitedStateLimit: 100,
            timeBudgetLimit: 1000,
        );
        $report2 = SearchGuardReport::fromMetrics(
            expansions: 0,
            visitedStates: 0,
            elapsedMilliseconds: 25.3,
            expansionLimit: 100,
            visitedStateLimit: 100,
            timeBudgetLimit: 1000,
        );
        $report3 = SearchGuardReport::fromMetrics(
            expansions: 0,
            visitedStates: 0,
            elapsedMilliseconds: 14.2,
            expansionLimit: 100,
            visitedStateLimit: 100,
            timeBudgetLimit: 1000,
        );

        $result = SearchGuardReport::aggregate([$report1, $report2, $report3]);

        self::assertEqualsWithDelta(50.0, $result->elapsedMilliseconds(), 0.0001);
    }

    #[TestDox('aggregate preserves limits from first report')]
    public function test_aggregate_preserves_limits_from_first(): void
    {
        $report1 = SearchGuardReport::fromMetrics(
            expansions: 10,
            visitedStates: 20,
            elapsedMilliseconds: 5.0,
            expansionLimit: 100,
            visitedStateLimit: 200,
            timeBudgetLimit: 50,
        );
        $report2 = SearchGuardReport::fromMetrics(
            expansions: 15,
            visitedStates: 25,
            elapsedMilliseconds: 3.0,
            expansionLimit: 500, // Different limit
            visitedStateLimit: 1000, // Different limit
            timeBudgetLimit: 100, // Different limit
        );

        $result = SearchGuardReport::aggregate([$report1, $report2]);

        // Should use limits from first report
        self::assertSame(100, $result->expansionLimit());
        self::assertSame(200, $result->visitedStateLimit());
        self::assertSame(50, $result->timeBudgetLimit());
    }

    #[TestDox('aggregate sets expansionsReached true if any report breached')]
    public function test_aggregate_expansions_reached_if_any_breached(): void
    {
        $report1 = SearchGuardReport::fromMetrics(
            expansions: 50,
            visitedStates: 0,
            elapsedMilliseconds: 0.0,
            expansionLimit: 100,
            visitedStateLimit: 100,
            timeBudgetLimit: null,
        );
        $report2 = new SearchGuardReport(
            expansionsReached: true,
            visitedStatesReached: false,
            timeBudgetReached: false,
            expansions: 100,
            visitedStates: 0,
            elapsedMilliseconds: 0.0,
            expansionLimit: 100,
            visitedStateLimit: 100,
            timeBudgetLimit: null,
        );

        $result = SearchGuardReport::aggregate([$report1, $report2]);

        self::assertTrue($result->expansionsReached());
        self::assertFalse($result->visitedStatesReached());
        self::assertFalse($result->timeBudgetReached());
    }

    #[TestDox('aggregate sets visitedStatesReached true if any report breached')]
    public function test_aggregate_visited_states_reached_if_any_breached(): void
    {
        $report1 = SearchGuardReport::fromMetrics(
            expansions: 0,
            visitedStates: 50,
            elapsedMilliseconds: 0.0,
            expansionLimit: 100,
            visitedStateLimit: 100,
            timeBudgetLimit: null,
        );
        $report2 = new SearchGuardReport(
            expansionsReached: false,
            visitedStatesReached: true,
            timeBudgetReached: false,
            expansions: 0,
            visitedStates: 100,
            elapsedMilliseconds: 0.0,
            expansionLimit: 100,
            visitedStateLimit: 100,
            timeBudgetLimit: null,
        );

        $result = SearchGuardReport::aggregate([$report1, $report2]);

        self::assertFalse($result->expansionsReached());
        self::assertTrue($result->visitedStatesReached());
        self::assertFalse($result->timeBudgetReached());
    }

    #[TestDox('aggregate sets timeBudgetReached true if any report breached')]
    public function test_aggregate_time_budget_reached_if_any_breached(): void
    {
        $report1 = SearchGuardReport::fromMetrics(
            expansions: 0,
            visitedStates: 0,
            elapsedMilliseconds: 10.0,
            expansionLimit: 100,
            visitedStateLimit: 100,
            timeBudgetLimit: 50,
        );
        $report2 = new SearchGuardReport(
            expansionsReached: false,
            visitedStatesReached: false,
            timeBudgetReached: true,
            expansions: 0,
            visitedStates: 0,
            elapsedMilliseconds: 50.0,
            expansionLimit: 100,
            visitedStateLimit: 100,
            timeBudgetLimit: 50,
        );

        $result = SearchGuardReport::aggregate([$report1, $report2]);

        self::assertFalse($result->expansionsReached());
        self::assertFalse($result->visitedStatesReached());
        self::assertTrue($result->timeBudgetReached());
    }

    #[TestDox('aggregate sets anyLimitReached true if any guard breached')]
    public function test_aggregate_any_limit_reached(): void
    {
        $report1 = SearchGuardReport::fromMetrics(
            expansions: 10,
            visitedStates: 20,
            elapsedMilliseconds: 5.0,
            expansionLimit: 100,
            visitedStateLimit: 100,
            timeBudgetLimit: 100,
        );
        $report2 = new SearchGuardReport(
            expansionsReached: false,
            visitedStatesReached: true,
            timeBudgetReached: false,
            expansions: 0,
            visitedStates: 100,
            elapsedMilliseconds: 0.0,
            expansionLimit: 100,
            visitedStateLimit: 100,
            timeBudgetLimit: 100,
        );

        $result = SearchGuardReport::aggregate([$report1, $report2]);

        self::assertTrue($result->anyLimitReached());
    }

    #[TestDox('aggregate handles all limits breached across different reports')]
    public function test_aggregate_all_limits_breached_across_reports(): void
    {
        $report1 = new SearchGuardReport(
            expansionsReached: true,
            visitedStatesReached: false,
            timeBudgetReached: false,
            expansions: 100,
            visitedStates: 0,
            elapsedMilliseconds: 0.0,
            expansionLimit: 100,
            visitedStateLimit: 100,
            timeBudgetLimit: 100,
        );
        $report2 = new SearchGuardReport(
            expansionsReached: false,
            visitedStatesReached: true,
            timeBudgetReached: false,
            expansions: 0,
            visitedStates: 100,
            elapsedMilliseconds: 0.0,
            expansionLimit: 100,
            visitedStateLimit: 100,
            timeBudgetLimit: 100,
        );
        $report3 = new SearchGuardReport(
            expansionsReached: false,
            visitedStatesReached: false,
            timeBudgetReached: true,
            expansions: 0,
            visitedStates: 0,
            elapsedMilliseconds: 100.0,
            expansionLimit: 100,
            visitedStateLimit: 100,
            timeBudgetLimit: 100,
        );

        $result = SearchGuardReport::aggregate([$report1, $report2, $report3]);

        self::assertTrue($result->expansionsReached());
        self::assertTrue($result->visitedStatesReached());
        self::assertTrue($result->timeBudgetReached());
        self::assertTrue($result->anyLimitReached());
    }

    #[TestDox('aggregate handles reports with no breaches')]
    public function test_aggregate_no_breaches(): void
    {
        $report1 = SearchGuardReport::fromMetrics(
            expansions: 10,
            visitedStates: 20,
            elapsedMilliseconds: 5.0,
            expansionLimit: 100,
            visitedStateLimit: 100,
            timeBudgetLimit: 100,
        );
        $report2 = SearchGuardReport::fromMetrics(
            expansions: 15,
            visitedStates: 25,
            elapsedMilliseconds: 3.0,
            expansionLimit: 100,
            visitedStateLimit: 100,
            timeBudgetLimit: 100,
        );

        $result = SearchGuardReport::aggregate([$report1, $report2]);

        self::assertFalse($result->expansionsReached());
        self::assertFalse($result->visitedStatesReached());
        self::assertFalse($result->timeBudgetReached());
        self::assertFalse($result->anyLimitReached());
    }

    #[TestDox('aggregate correctly sums all metrics together')]
    public function test_aggregate_sums_all_metrics(): void
    {
        $report1 = SearchGuardReport::fromMetrics(
            expansions: 10,
            visitedStates: 100,
            elapsedMilliseconds: 5.5,
            expansionLimit: 1000,
            visitedStateLimit: 10000,
            timeBudgetLimit: 60000,
        );
        $report2 = SearchGuardReport::fromMetrics(
            expansions: 20,
            visitedStates: 200,
            elapsedMilliseconds: 10.3,
            expansionLimit: 1000,
            visitedStateLimit: 10000,
            timeBudgetLimit: 60000,
        );
        $report3 = SearchGuardReport::fromMetrics(
            expansions: 30,
            visitedStates: 300,
            elapsedMilliseconds: 15.2,
            expansionLimit: 1000,
            visitedStateLimit: 10000,
            timeBudgetLimit: 60000,
        );

        $result = SearchGuardReport::aggregate([$report1, $report2, $report3]);

        self::assertSame(60, $result->expansions());
        self::assertSame(600, $result->visitedStates());
        self::assertEqualsWithDelta(31.0, $result->elapsedMilliseconds(), 0.0001);
    }

    #[TestDox('aggregate handles null time budget limit from first report')]
    public function test_aggregate_handles_null_time_budget(): void
    {
        $report1 = SearchGuardReport::fromMetrics(
            expansions: 10,
            visitedStates: 20,
            elapsedMilliseconds: 5.0,
            expansionLimit: 100,
            visitedStateLimit: 100,
            timeBudgetLimit: null,
        );
        $report2 = SearchGuardReport::fromMetrics(
            expansions: 15,
            visitedStates: 25,
            elapsedMilliseconds: 3.0,
            expansionLimit: 100,
            visitedStateLimit: 100,
            timeBudgetLimit: 50, // This one has a limit
        );

        $result = SearchGuardReport::aggregate([$report1, $report2]);

        // Should preserve null from first report
        self::assertNull($result->timeBudgetLimit());
    }

    #[TestDox('aggregate handles large number of reports')]
    public function test_aggregate_handles_many_reports(): void
    {
        $reports = [];
        for ($i = 0; $i < 100; ++$i) {
            $reports[] = SearchGuardReport::fromMetrics(
                expansions: 1,
                visitedStates: 10,
                elapsedMilliseconds: 0.1,
                expansionLimit: 10000,
                visitedStateLimit: 100000,
                timeBudgetLimit: 60000,
            );
        }

        $result = SearchGuardReport::aggregate($reports);

        self::assertSame(100, $result->expansions());
        self::assertSame(1000, $result->visitedStates());
        self::assertEqualsWithDelta(10.0, $result->elapsedMilliseconds(), 0.01);
    }

    #[TestDox('aggregate with two reports sums correctly')]
    public function test_aggregate_two_reports(): void
    {
        $report1 = SearchGuardReport::fromMetrics(
            expansions: 5,
            visitedStates: 10,
            elapsedMilliseconds: 2.5,
            expansionLimit: 100,
            visitedStateLimit: 200,
            timeBudgetLimit: 50,
        );
        $report2 = SearchGuardReport::fromMetrics(
            expansions: 7,
            visitedStates: 15,
            elapsedMilliseconds: 3.5,
            expansionLimit: 100,
            visitedStateLimit: 200,
            timeBudgetLimit: 50,
        );

        $result = SearchGuardReport::aggregate([$report1, $report2]);

        self::assertSame(12, $result->expansions());
        self::assertSame(25, $result->visitedStates());
        self::assertEqualsWithDelta(6.0, $result->elapsedMilliseconds(), 0.0001);
        self::assertSame(100, $result->expansionLimit());
        self::assertSame(200, $result->visitedStateLimit());
        self::assertSame(50, $result->timeBudgetLimit());
    }

    #[TestDox('aggregate correctly identifies breach in middle report')]
    public function test_aggregate_breach_in_middle_report(): void
    {
        $report1 = SearchGuardReport::fromMetrics(
            expansions: 10,
            visitedStates: 10,
            elapsedMilliseconds: 1.0,
            expansionLimit: 100,
            visitedStateLimit: 100,
            timeBudgetLimit: 100,
        );
        $report2 = new SearchGuardReport(
            expansionsReached: true,
            visitedStatesReached: false,
            timeBudgetReached: false,
            expansions: 100,
            visitedStates: 10,
            elapsedMilliseconds: 1.0,
            expansionLimit: 100,
            visitedStateLimit: 100,
            timeBudgetLimit: 100,
        );
        $report3 = SearchGuardReport::fromMetrics(
            expansions: 10,
            visitedStates: 10,
            elapsedMilliseconds: 1.0,
            expansionLimit: 100,
            visitedStateLimit: 100,
            timeBudgetLimit: 100,
        );

        $result = SearchGuardReport::aggregate([$report1, $report2, $report3]);

        self::assertTrue($result->expansionsReached());
        self::assertSame(120, $result->expansions());
    }

    #[TestDox('aggregate with zero metrics in some reports')]
    public function test_aggregate_with_zero_metrics(): void
    {
        $report1 = SearchGuardReport::none();
        $report2 = SearchGuardReport::fromMetrics(
            expansions: 50,
            visitedStates: 100,
            elapsedMilliseconds: 10.0,
            expansionLimit: 1000,
            visitedStateLimit: 1000,
            timeBudgetLimit: 1000,
        );

        $result = SearchGuardReport::aggregate([$report1, $report2]);

        self::assertSame(50, $result->expansions());
        self::assertSame(100, $result->visitedStates());
        self::assertEqualsWithDelta(10.0, $result->elapsedMilliseconds(), 0.0001);
    }

    // ========================================================================
    // Additional Edge Case Tests - Kill Escaped Mutants
    // ========================================================================

    #[TestDox('fromMetrics preserves exactly zero values without clamping')]
    public function test_from_metrics_preserves_zero_values(): void
    {
        $report = SearchGuardReport::fromMetrics(
            expansions: 0,
            visitedStates: 0,
            elapsedMilliseconds: 0.0,
            expansionLimit: 0,
            visitedStateLimit: 0,
            timeBudgetLimit: 0,
        );

        self::assertSame(0, $report->expansions());
        self::assertSame(0, $report->visitedStates());
        self::assertSame(0.0, $report->elapsedMilliseconds());
        self::assertSame(0, $report->expansionLimit());
        self::assertSame(0, $report->visitedStateLimit());
        self::assertSame(0, $report->timeBudgetLimit());
    }

    #[TestDox('fromMetrics clamps -1 to 0 for all metrics')]
    public function test_from_metrics_clamps_minus_one_to_zero(): void
    {
        $report = SearchGuardReport::fromMetrics(
            expansions: -1,
            visitedStates: -1,
            elapsedMilliseconds: -0.001,
            expansionLimit: -1,
            visitedStateLimit: -1,
            timeBudgetLimit: -1,
        );

        self::assertSame(0, $report->expansions());
        self::assertSame(0, $report->visitedStates());
        self::assertSame(0.0, $report->elapsedMilliseconds());
        self::assertSame(0, $report->expansionLimit());
        self::assertSame(0, $report->visitedStateLimit());
        self::assertSame(0, $report->timeBudgetLimit());
    }

    #[TestDox('idle factory returns zero for expansions not negative')]
    public function test_idle_returns_zero_expansions(): void
    {
        $report = SearchGuardReport::idle(100, 100, 100);

        self::assertSame(0, $report->expansions());
        self::assertSame(0, $report->visitedStates());
        self::assertSame(0.0, $report->elapsedMilliseconds());
    }

    #[TestDox('none factory returns zero for all metrics not negative')]
    public function test_none_returns_zero_metrics(): void
    {
        $report = SearchGuardReport::none();

        self::assertSame(0, $report->expansions());
        self::assertSame(0, $report->visitedStates());
        self::assertSame(0.0, $report->elapsedMilliseconds());
        self::assertSame(0, $report->expansionLimit());
        self::assertSame(0, $report->visitedStateLimit());
    }

    #[TestDox('fromMetrics detects time budget reached with integer comparison')]
    public function test_from_metrics_time_budget_integer_comparison(): void
    {
        // Test that integer timeBudgetLimit is correctly compared with float elapsed
        $report = SearchGuardReport::fromMetrics(
            expansions: 0,
            visitedStates: 0,
            elapsedMilliseconds: 100.0,
            expansionLimit: 1000,
            visitedStateLimit: 1000,
            timeBudgetLimit: 100, // Integer
        );

        self::assertTrue($report->timeBudgetReached());
    }

    #[TestDox('fromMetrics handles elapsed exactly at time budget limit')]
    public function test_from_metrics_elapsed_exactly_at_limit(): void
    {
        $report = SearchGuardReport::fromMetrics(
            expansions: 0,
            visitedStates: 0,
            elapsedMilliseconds: 50.0,
            expansionLimit: 1000,
            visitedStateLimit: 1000,
            timeBudgetLimit: 50,
        );

        self::assertTrue($report->timeBudgetReached());
    }

    #[TestDox('fromMetrics handles elapsed just below time budget limit')]
    public function test_from_metrics_elapsed_just_below_limit(): void
    {
        $report = SearchGuardReport::fromMetrics(
            expansions: 0,
            visitedStates: 0,
            elapsedMilliseconds: 49.999,
            expansionLimit: 1000,
            visitedStateLimit: 1000,
            timeBudgetLimit: 50,
        );

        self::assertFalse($report->timeBudgetReached());
    }

    // ========================================================================
    // Tests to Kill Escaped Mutants
    // ========================================================================

    #[TestDox('fromMetrics with positive values keeps them unchanged')]
    public function test_from_metrics_positive_values_unchanged(): void
    {
        $report = SearchGuardReport::fromMetrics(
            expansions: 5,
            visitedStates: 10,
            elapsedMilliseconds: 15.5,
            expansionLimit: 100,
            visitedStateLimit: 200,
            timeBudgetLimit: 300,
        );

        // Verify positive values are kept as-is (not clamped to 0)
        self::assertSame(5, $report->expansions());
        self::assertSame(10, $report->visitedStates());
        self::assertEqualsWithDelta(15.5, $report->elapsedMilliseconds(), 0.0001);
        self::assertSame(100, $report->expansionLimit());
        self::assertSame(200, $report->visitedStateLimit());
        self::assertSame(300, $report->timeBudgetLimit());
    }

    #[TestDox('fromMetrics with value 1 keeps it as 1 not 0')]
    public function test_from_metrics_value_one_unchanged(): void
    {
        $report = SearchGuardReport::fromMetrics(
            expansions: 1,
            visitedStates: 1,
            elapsedMilliseconds: 0.001,
            expansionLimit: 1,
            visitedStateLimit: 1,
            timeBudgetLimit: 1,
        );

        // Kill LessThan → LessThanOrEqual mutants
        self::assertSame(1, $report->expansions());
        self::assertSame(1, $report->visitedStates());
        self::assertEqualsWithDelta(0.001, $report->elapsedMilliseconds(), 0.00001);
        self::assertSame(1, $report->expansionLimit());
        self::assertSame(1, $report->visitedStateLimit());
        self::assertSame(1, $report->timeBudgetLimit());
    }

    #[TestDox('idle returns exactly zero for metrics')]
    public function test_idle_returns_exactly_zero(): void
    {
        $report = SearchGuardReport::idle(100, 200, 300);

        // Kill DecrementInteger mutants - verify exact zero, not -1
        self::assertSame(0, $report->expansions());
        self::assertSame(0, $report->visitedStates());
        self::assertSame(0.0, $report->elapsedMilliseconds());

        // Also verify the limits are correctly passed through
        self::assertSame(200, $report->expansionLimit());
        self::assertSame(100, $report->visitedStateLimit());
        self::assertSame(300, $report->timeBudgetLimit());
    }

    #[TestDox('none returns exactly zero for all values')]
    public function test_none_returns_exactly_zero(): void
    {
        $report = SearchGuardReport::none();

        // Kill DecrementInteger mutants - verify exact zero, not -1
        self::assertSame(0, $report->expansions());
        self::assertSame(0, $report->visitedStates());
        self::assertSame(0.0, $report->elapsedMilliseconds());
        self::assertSame(0, $report->expansionLimit());
        self::assertSame(0, $report->visitedStateLimit());
        self::assertNull($report->timeBudgetLimit());
    }

    #[TestDox('timeBudget reached uses float comparison correctly')]
    public function test_time_budget_float_comparison(): void
    {
        // Test that float cast is necessary - use fractional time budget
        $report = SearchGuardReport::fromMetrics(
            expansions: 0,
            visitedStates: 0,
            elapsedMilliseconds: 100.5,
            expansionLimit: 1000,
            visitedStateLimit: 1000,
            timeBudgetLimit: 100, // Integer, but elapsed is 100.5
        );

        // 100.5 >= 100.0 should be true
        self::assertTrue($report->timeBudgetReached());
    }

    #[TestDox('timeBudget not reached with fractional milliseconds below limit')]
    public function test_time_budget_fractional_below(): void
    {
        $report = SearchGuardReport::fromMetrics(
            expansions: 0,
            visitedStates: 0,
            elapsedMilliseconds: 99.9,
            expansionLimit: 1000,
            visitedStateLimit: 1000,
            timeBudgetLimit: 100,
        );

        // 99.9 < 100.0 should be false
        self::assertFalse($report->timeBudgetReached());
    }
}
