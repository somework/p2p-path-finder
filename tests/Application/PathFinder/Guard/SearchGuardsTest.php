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

        $status = $guards->finalize(false);

        self::assertTrue($status->expansionsReached());
        self::assertFalse($status->timeBudgetReached());
        self::assertFalse($status->visitedStatesReached());
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

        $status = $guards->finalize(true);

        self::assertFalse($status->expansionsReached());
        self::assertTrue($status->timeBudgetReached());
        self::assertTrue($status->visitedStatesReached());
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
        self::assertTrue($guards->finalize(false)->timeBudgetReached());
    }
}
