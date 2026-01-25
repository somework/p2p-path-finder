<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Integration\Application\PathSearch\Service;

use PHPUnit\Framework\TestCase;

/**
 * NOTE: This test class was removed as part of MUL-12 legacy test cleanup.
 *
 * All tests in this class relied on PathSearchEngine-specific guard limit handling
 * that differs from ExecutionPlanService behavior.
 *
 * Equivalent coverage is provided by:
 * - ExecutionPlanServiceTest::test_guard_limits (guard limit reporting)
 * - ExecutionPlanServiceTest::test_guard_limits_throw_when_configured (exception behavior)
 * - ExecutionPlanServiceTest::test_no_path_exists (empty result handling)
 * - ExecutionPlanServiceTest::test_empty_order_book (empty order book handling)
 *
 * The PathFinderEdgeCaseFixtures class remains available for future use.
 *
 * Removed tests:
 * - test_it_returns_empty_paths_without_triggering_guards
 * - test_it_reports_guard_metadata_when_limits_triggered
 * - test_it_throws_guard_limit_exception_when_configured
 * - test_guard_limited_chain_resolves_when_limits_relaxed
 *
 * @see ExecutionPlanServiceTest
 */
final class PathSearchServiceEdgeCasesTest extends TestCase
{
    public function test_class_is_intentionally_empty(): void
    {
        // This test exists to prevent PHPUnit from reporting the class as having no tests.
        // All original tests were removed as part of MUL-12 - see class docblock.
        self::assertTrue(true);
    }
}
