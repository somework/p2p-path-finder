<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder\Result;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\GuardLimitStatus;

final class GuardLimitStatusTest extends TestCase
{
    public function test_none_factory_creates_clear_status(): void
    {
        $status = GuardLimitStatus::none();

        self::assertFalse($status->expansionsReached());
        self::assertFalse($status->visitedStatesReached());
        self::assertFalse($status->anyLimitReached());
    }

    public function test_any_limit_reports_true_when_either_guard_triggers(): void
    {
        $expansionsReached = new GuardLimitStatus(true, false);
        $visitedReached = new GuardLimitStatus(false, true);
        $bothReached = new GuardLimitStatus(true, true);

        self::assertTrue($expansionsReached->anyLimitReached());
        self::assertTrue($visitedReached->anyLimitReached());
        self::assertTrue($bothReached->anyLimitReached());

        self::assertTrue($expansionsReached->expansionsReached());
        self::assertFalse($expansionsReached->visitedStatesReached());

        self::assertFalse($visitedReached->expansionsReached());
        self::assertTrue($visitedReached->visitedStatesReached());
    }
}
