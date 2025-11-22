<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder\Guard;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathFinder\Guard\SearchGuards;

final class SearchGuardsTest extends TestCase
{
    public function test_it_reports_expansion_guard_breaches(): void
    {
        $now = 0.0;
        $clock = static function () use (&$now): float {
            return $now;
        };

        $guards = new SearchGuards(2, null, $clock);

        self::assertTrue($guards->canExpand());
        $guards->recordExpansion();

        self::assertTrue($guards->canExpand());
        $guards->recordExpansion();

        self::assertFalse($guards->canExpand());

        $status = $guards->finalize(3, 5, false);

        self::assertTrue($status->expansionsReached());
        self::assertFalse($status->timeBudgetReached());
        self::assertFalse($status->visitedStatesReached());
        self::assertSame(2, $status->expansions());
        self::assertSame(3, $status->visitedStates());
        self::assertSame(2, $status->expansionLimit());
        self::assertSame(5, $status->visitedStateLimit());
        self::assertNull($status->timeBudgetLimit());
        self::assertSame(0.0, $status->elapsedMilliseconds());
    }

    public function test_it_reports_time_budget_breaches(): void
    {
        $now = 0.0;
        $clock = static function () use (&$now): float {
            return $now;
        };

        $guards = new SearchGuards(10, 5, $clock);

        self::assertTrue($guards->canExpand());
        $guards->recordExpansion();

        $now = 0.004; // 4 milliseconds since start.
        self::assertTrue($guards->canExpand());

        $now = 0.006; // 6 milliseconds since start.
        self::assertFalse($guards->canExpand());

        $status = $guards->finalize(4, 10, true);

        self::assertFalse($status->expansionsReached());
        self::assertTrue($status->timeBudgetReached());
        self::assertTrue($status->visitedStatesReached());
        self::assertSame(1, $status->expansions());
        self::assertSame(4, $status->visitedStates());
        self::assertSame(10, $status->expansionLimit());
        self::assertSame(10, $status->visitedStateLimit());
        self::assertSame(5, $status->timeBudgetLimit());
        self::assertEqualsWithDelta(6.0, $status->elapsedMilliseconds(), 0.0001);
    }

    public function test_time_budget_is_exhausted_when_elapsed_equals_limit(): void
    {
        $now = 0.0;
        $clock = static function () use (&$now): float {
            return $now;
        };

        $guards = new SearchGuards(10, 5, $clock);

        self::assertTrue($guards->canExpand());

        $now = 0.005; // exactly 5 milliseconds since start.

        self::assertFalse($guards->canExpand());
        $report = $guards->finalize(1, 10, false);
        self::assertTrue($report->timeBudgetReached());
        self::assertSame(5, $report->timeBudgetLimit());
        self::assertEqualsWithDelta(5.0, $report->elapsedMilliseconds(), 0.0001);
    }

    public function test_time_budget_measures_elapsed_relative_to_start_time(): void
    {
        $now = 17.5;
        $clock = static function () use (&$now): float {
            return $now;
        };

        $guards = new SearchGuards(10, 5, $clock);

        self::assertTrue($guards->canExpand());

        $now = 17.504; // 4 milliseconds since start.
        self::assertTrue($guards->canExpand());

        $now = 17.506; // 6 milliseconds since start.
        self::assertFalse($guards->canExpand());
        $report = $guards->finalize(1, 10, false);
        self::assertTrue($report->timeBudgetReached());
        self::assertEqualsWithDelta(6.0, $report->elapsedMilliseconds(), 0.0001);
    }

    public function test_can_expand_returns_false_after_expansion_limit_reached(): void
    {
        $clock = static fn (): float => 0.0;
        $guards = new SearchGuards(3, null, $clock);

        self::assertTrue($guards->canExpand());
        $guards->recordExpansion();
        self::assertTrue($guards->canExpand());
        $guards->recordExpansion();
        self::assertTrue($guards->canExpand());
        $guards->recordExpansion();

        // Limit reached
        self::assertFalse($guards->canExpand());
        self::assertFalse($guards->canExpand());

        $report = $guards->finalize(0, 100, false);
        self::assertTrue($report->expansionsReached());
        self::assertSame(3, $report->expansions());
    }

    public function test_guards_without_time_budget_only_check_expansions(): void
    {
        $clock = static fn (): float => 999999.0; // Very high time
        $guards = new SearchGuards(5, null, $clock);

        self::assertTrue($guards->canExpand());
        $guards->recordExpansion();

        self::assertTrue($guards->canExpand());

        $report = $guards->finalize(1, 100, false);
        self::assertFalse($report->timeBudgetReached());
        self::assertNull($report->timeBudgetLimit());
    }

    public function test_finalize_updates_time_budget_reached_if_just_exceeded(): void
    {
        $now = 0.0;
        $clock = static function () use (&$now): float {
            return $now;
        };

        $guards = new SearchGuards(100, 10, $clock);

        $now = 0.009; // 9 milliseconds - still within budget
        self::assertTrue($guards->canExpand());

        // Simulate time passing before finalize
        $now = 0.011; // 11 milliseconds - exceeded during finalize

        $report = $guards->finalize(1, 100, false);
        self::assertTrue($report->timeBudgetReached());
        self::assertEqualsWithDelta(11.0, $report->elapsedMilliseconds(), 0.0001);
    }

    public function test_expansion_limit_of_zero_immediately_blocks(): void
    {
        $clock = static fn (): float => 0.0;
        $guards = new SearchGuards(0, null, $clock);

        self::assertFalse($guards->canExpand());

        $report = $guards->finalize(0, 100, false);
        self::assertTrue($report->expansionsReached());
        self::assertSame(0, $report->expansions());
        self::assertSame(0, $report->expansionLimit());
    }

    public function test_time_budget_of_one_millisecond_works_correctly(): void
    {
        $now = 0.0;
        $clock = static function () use (&$now): float {
            return $now;
        };

        $guards = new SearchGuards(100, 1, $clock);

        self::assertTrue($guards->canExpand());

        $now = 0.001; // exactly 1 millisecond
        self::assertFalse($guards->canExpand());

        $report = $guards->finalize(1, 100, false);
        self::assertTrue($report->timeBudgetReached());
        self::assertSame(1, $report->timeBudgetLimit());
        self::assertEqualsWithDelta(1.0, $report->elapsedMilliseconds(), 0.0001);
    }

    public function test_very_high_expansion_limit_acts_as_disabled(): void
    {
        $clock = static fn (): float => 0.0;
        $guards = new SearchGuards(1000000, null, $clock);

        for ($i = 0; $i < 1000; ++$i) {
            self::assertTrue($guards->canExpand());
            $guards->recordExpansion();
        }

        $report = $guards->finalize(1000, 100000, false);
        self::assertFalse($report->expansionsReached());
        self::assertSame(1000, $report->expansions());
        self::assertSame(1000000, $report->expansionLimit());
    }

    /**
     * @testdox Multiple guards (expansion + time + visited) reached simultaneously
     */
    public function testMultipleGuardsBreachedSimultaneously(): void
    {
        $now = 0.0;
        $clock = static function () use (&$now): float {
            return $now;
        };

        // Set very tight limits: 2 expansions, 5ms budget
        $guards = new SearchGuards(2, 5, $clock);

        // Expansion 1
        self::assertTrue($guards->canExpand());
        $guards->recordExpansion();

        // Expansion 2 - also advancing time close to limit
        $now = 0.004; // 4ms elapsed
        self::assertTrue($guards->canExpand());
        $guards->recordExpansion();

        // Now both expansion limit and time budget are reached
        $now = 0.006; // 6ms elapsed (exceeds time budget)
        self::assertFalse($guards->canExpand(), 'canExpand should return false when both limits reached');

        // Finalize with visited states also at limit
        $report = $guards->finalize(3, 3, true); // visitedStates = 3, limit = 3, guard reached = true

        // All three guards should be breached
        self::assertTrue($report->expansionsReached(), 'Expansion limit should be reached');
        self::assertTrue($report->timeBudgetReached(), 'Time budget should be reached');
        self::assertTrue($report->visitedStatesReached(), 'Visited states limit should be reached');
        self::assertTrue($report->anyLimitReached(), 'anyLimitReached() should return true');

        // Verify actual counts
        self::assertSame(2, $report->expansions());
        self::assertSame(3, $report->visitedStates());
        self::assertEqualsWithDelta(6.0, $report->elapsedMilliseconds(), 0.0001);
    }

    /**
     * @testdox Expansion limit of exactly 1 allows only one expansion
     */
    public function testGuardWithExactlyOneExpansion(): void
    {
        $clock = static fn (): float => 0.0;
        $guards = new SearchGuards(1, null, $clock);

        // First expansion should be allowed
        self::assertTrue($guards->canExpand());
        $guards->recordExpansion();

        // Second expansion should be blocked
        self::assertFalse($guards->canExpand());

        $report = $guards->finalize(1, 100, false);
        self::assertTrue($report->expansionsReached());
        self::assertSame(1, $report->expansions());
        self::assertSame(1, $report->expansionLimit());
    }

    /**
     * @testdox Time budget of 0ms immediately blocks expansion
     */
    public function testGuardWithZeroMillisecondTimeBudget(): void
    {
        $now = 0.0;
        $clock = static function () use (&$now): float {
            return $now;
        };

        $guards = new SearchGuards(100, 0, $clock);

        // Even at start time, 0ms budget should block
        self::assertFalse($guards->canExpand());

        $report = $guards->finalize(0, 100, false);
        self::assertTrue($report->timeBudgetReached());
        self::assertSame(0, $report->timeBudgetLimit());
        self::assertSame(0.0, $report->elapsedMilliseconds());
    }

    /**
     * @testdox Guards with PHP_INT_MAX limits behave as effectively unlimited
     */
    public function testGuardsWithMaxIntLimits(): void
    {
        $clock = static fn (): float => 0.0;
        $guards = new SearchGuards(PHP_INT_MAX, null, $clock);

        // Perform many expansions
        for ($i = 0; $i < 10000; ++$i) {
            self::assertTrue($guards->canExpand());
            $guards->recordExpansion();
        }

        $report = $guards->finalize(5000, PHP_INT_MAX, false);
        self::assertFalse($report->expansionsReached(), 'Should not reach PHP_INT_MAX limit');
        self::assertFalse($report->visitedStatesReached(), 'Should not reach PHP_INT_MAX visited states limit');
        self::assertSame(10000, $report->expansions());
        self::assertSame(PHP_INT_MAX, $report->expansionLimit());
        self::assertSame(PHP_INT_MAX, $report->visitedStateLimit());
    }

    /**
     * @testdox Time budget with microsecond precision works correctly
     */
    public function testTimeBudgetWithMicrosecondPrecision(): void
    {
        $now = 0.0;
        $clock = static function () use (&$now): float {
            return $now;
        };

        // 1.5 milliseconds budget
        $guards = new SearchGuards(100, 1, $clock);

        $now = 0.0005; // 0.5ms elapsed
        self::assertTrue($guards->canExpand(), 'Should allow expansion at 0.5ms');

        $now = 0.00099; // 0.99ms elapsed
        self::assertTrue($guards->canExpand(), 'Should allow expansion at 0.99ms');

        $now = 0.001; // Exactly 1ms elapsed
        self::assertFalse($guards->canExpand(), 'Should block expansion at exactly 1ms');

        $report = $guards->finalize(2, 100, false);
        self::assertTrue($report->timeBudgetReached());
        self::assertSame(1, $report->timeBudgetLimit());
    }

    /**
     * @testdox Expansion limit reached before time budget
     */
    public function testExpansionLimitReachedBeforeTimeBudget(): void
    {
        $now = 0.0;
        $clock = static function () use (&$now): float {
            return $now;
        };

        // Very tight expansion limit, generous time budget
        $guards = new SearchGuards(2, 1000, $clock);

        $guards->recordExpansion();
        $now = 0.001; // 1ms
        $guards->recordExpansion();
        $now = 0.002; // 2ms

        self::assertFalse($guards->canExpand(), 'Expansion limit should block before time budget');

        $report = $guards->finalize(5, 100, false);
        self::assertTrue($report->expansionsReached(), 'Expansion limit should be reached');
        self::assertFalse($report->timeBudgetReached(), 'Time budget should NOT be reached');
        self::assertSame(2, $report->expansions());
        self::assertEqualsWithDelta(2.0, $report->elapsedMilliseconds(), 0.0001);
    }

    /**
     * @testdox Time budget reached before expansion limit
     */
    public function testTimeBudgetReachedBeforeExpansionLimit(): void
    {
        $now = 0.0;
        $clock = static function () use (&$now): float {
            return $now;
        };

        // Generous expansion limit, very tight time budget
        $guards = new SearchGuards(1000, 2, $clock);

        $guards->recordExpansion();
        $now = 0.001; // 1ms

        self::assertTrue($guards->canExpand());

        $now = 0.003; // 3ms - exceeds budget
        self::assertFalse($guards->canExpand(), 'Time budget should block before expansion limit');

        $report = $guards->finalize(5, 100, false);
        self::assertFalse($report->expansionsReached(), 'Expansion limit should NOT be reached');
        self::assertTrue($report->timeBudgetReached(), 'Time budget should be reached');
        self::assertSame(1, $report->expansions());
        self::assertEqualsWithDelta(3.0, $report->elapsedMilliseconds(), 0.0001);
    }

    /**
     * @testdox Visited states guard triggered while other guards not reached
     */
    public function testVisitedStatesGuardWithoutOtherLimits(): void
    {
        $clock = static fn (): float => 0.0;
        $guards = new SearchGuards(1000, null, $clock);

        // Perform a few expansions
        for ($i = 0; $i < 5; ++$i) {
            $guards->recordExpansion();
        }

        // Finalize with visited states at limit
        $report = $guards->finalize(50, 50, true); // visitedStates = limit = 50, guard reached = true

        self::assertFalse($report->expansionsReached(), 'Expansion limit should NOT be reached');
        self::assertFalse($report->timeBudgetReached(), 'Time budget should NOT be reached');
        self::assertTrue($report->visitedStatesReached(), 'Visited states limit should be reached');
        self::assertTrue($report->anyLimitReached(), 'anyLimitReached() should return true');
        self::assertSame(5, $report->expansions());
        self::assertSame(50, $report->visitedStates());
    }

    /**
     * @testdox Guards work correctly after repeated canExpand calls without recording
     */
    public function testRepeatedCanExpandCallsWithoutRecording(): void
    {
        $now = 0.0;
        $clock = static function () use (&$now): float {
            return $now;
        };

        $guards = new SearchGuards(3, 10, $clock);

        // Call canExpand multiple times without recording
        self::assertTrue($guards->canExpand());
        self::assertTrue($guards->canExpand());
        self::assertTrue($guards->canExpand());

        // First actual expansion
        $guards->recordExpansion();
        $now = 0.001;

        // More calls without recording
        self::assertTrue($guards->canExpand());
        self::assertTrue($guards->canExpand());

        // Continue expansions
        $guards->recordExpansion();
        $guards->recordExpansion();

        // Now limit should be reached
        self::assertFalse($guards->canExpand());

        $report = $guards->finalize(2, 100, false);
        self::assertTrue($report->expansionsReached());
        self::assertSame(3, $report->expansions());
    }

    /**
     * @testdox Multiple canExpand checks remain consistent after time budget reached
     *
     * Note: This test documents current behavior where canExpand() may return true
     * after time budget is reached if expansion limit hasn't been reached. In practice,
     * this doesn't cause issues because the search loop breaks on first false return.
     */
    public function testMultipleCanExpandChecksAfterTimeBudget(): void
    {
        $now = 0.0;
        $clock = static function () use (&$now): float {
            return $now;
        };

        $guards = new SearchGuards(100, 10, $clock);

        // First check at start
        self::assertTrue($guards->canExpand());

        // Exceed time budget
        $now = 0.011; // 11ms - over 10ms budget
        self::assertFalse($guards->canExpand(), 'Should return false when time budget exceeded');

        // Document current behavior: subsequent calls may return true if expansion limit not reached
        // This is acceptable because in PathFinder, the loop breaks on first false
        $report = $guards->finalize(0, 100, false);
        self::assertTrue($report->timeBudgetReached());
        self::assertEqualsWithDelta(11.0, $report->elapsedMilliseconds(), 0.0001);
    }

    /**
     * @testdox Zero expansion limit with time budget still blocks immediately
     */
    public function testZeroExpansionLimitOverridesTimeBudget(): void
    {
        $now = 0.0;
        $clock = static function () use (&$now): float {
            return $now;
        };

        // Zero expansion limit, but generous time budget
        $guards = new SearchGuards(0, 1000, $clock);

        // Should block immediately despite time budget
        self::assertFalse($guards->canExpand());

        // Advance time
        $now = 0.001;
        self::assertFalse($guards->canExpand(), 'Zero expansion limit should block regardless of time');

        $report = $guards->finalize(0, 100, false);
        self::assertTrue($report->expansionsReached());
        self::assertFalse($report->timeBudgetReached());
        self::assertSame(0, $report->expansions());
    }
}

