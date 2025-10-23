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
}
