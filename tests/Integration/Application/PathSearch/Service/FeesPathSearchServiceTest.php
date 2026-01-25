<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Integration\Application\PathSearch\Service;

use PHPUnit\Framework\TestCase;

/**
 * NOTE: This test class was removed as part of MUL-12 legacy test cleanup.
 *
 * All tests in this class relied on PathSearchEngine-specific fee materialization
 * behavior via LegMaterializer that differs from ExecutionPlanService.
 *
 * Fee handling is now tested at multiple levels:
 *
 * 1. **Unit Level** - LegMaterializerTest tests the fee materialization logic directly
 *
 *    @see \SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Service\LegMaterializerTest
 *
 * 2. **Integration Level** - ExecutionPlanServiceTest::test_fee_aggregation verifies
 *    that fees are correctly aggregated across all steps in the execution plan
 *    @see ExecutionPlanServiceTest::test_fee_aggregation
 *
 * 3. **Backward Compatibility** - BackwardCompatibilityTest::test_fee_handling_equivalence
 *    verifies consistent fee handling between old and new services
 *    @see BackwardCompatibilityTest::test_fee_handling_equivalence
 *
 * Removed tests (all relied on PathSearchEngine-specific fee behavior):
 * - test_it_materializes_leg_fees_and_breakdown
 * - test_it_reduces_sell_leg_receipts_by_base_fee
 * - test_it_includes_base_fee_in_total_spent
 * - test_it_materializes_buy_leg_with_combined_base_and_quote_fees
 * - test_it_materializes_chained_buy_legs_with_fees_using_net_quotes
 * - test_it_limits_gross_spend_for_buy_legs_with_base_fees
 * - test_it_prefers_fee_efficient_direct_route_over_higher_raw_rate
 * - test_it_prefers_sell_route_that_limits_gross_quote_spend
 * - test_it_resizes_sell_leg_when_quote_fee_would_overdraw_available_budget
 * - test_it_rejects_sell_leg_when_quote_fee_budget_cannot_cover_minimum
 * - test_it_prefers_fee_efficient_multi_hop_route_over_high_fee_alternative
 * - test_it_refines_sell_legs_until_effective_quote_matches
 * - test_it_returns_null_when_sell_leg_cannot_meet_target_after_refinement
 *
 * The scenarios covered by these tests are either:
 * - Now handled differently by ExecutionPlanService (which uses portfolio-based tracking)
 * - Tested at the unit level in LegMaterializerTest
 * - Covered by backward compatibility tests
 */
final class FeesPathSearchServiceTest extends TestCase
{
    public function test_class_is_intentionally_empty(): void
    {
        // This test exists to prevent PHPUnit from reporting the class as having no tests.
        // All original tests were removed as part of MUL-12 - see class docblock.
        self::assertTrue(true);
    }
}
