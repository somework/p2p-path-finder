<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering;

/**
 * Defines a strategy for ordering and prioritizing candidate paths during search.
 *
 * Implementations of this interface determine how paths are ranked relative to each other,
 * which directly influences which paths appear first in search results. This is a key
 * extension point for customizing search behavior based on business requirements.
 *
 * ## Core Responsibilities
 *
 * - **Prioritization**: Define the relative importance of path attributes (cost, hops, route).
 * - **Stable Sorting**: Ensure that paths with equal rank maintain consistent ordering.
 * - **Determinism**: Produce the same ordering for the same inputs across multiple executions.
 *
 * ## Contract Requirements
 *
 * Implementations MUST adhere to the following contract:
 *
 * 1. **Comparison Semantics**: The `compare()` method must return:
 *    - **Negative integer** if `$left` should rank BEFORE `$right` (lower rank = higher priority)
 *    - **Zero** if `$left` and `$right` have equal rank
 *    - **Positive integer** if `$left` should rank AFTER `$right`
 *
 * 2. **Transitivity**: If A < B and B < C, then A < C must hold.
 *
 * 3. **Stability**: To ensure stable sorting, always fall back to `insertionOrder()` as the
 *    final tie-breaker when all other attributes are equal:
 *
 *    ```php
 *    if ($result === 0) {
 *        return $left->insertionOrder() <=> $right->insertionOrder();
 *    }
 *    ```
 *
 * 4. **Determinism**: Given the same `PathOrderKey` objects, `compare()` must always return
 *    the same result. Avoid non-deterministic operations like:
 *    - Random number generation
 *    - System time or timestamps (unless part of the key data)
 *    - Unordered data structures (like raw hash sets)
 *
 * 5. **Consistency**: The ordering must be consistent with the mathematical properties of
 *    comparison. Specifically:
 *    - `compare(A, B)` must equal `-compare(B, A)` (antisymmetry)
 *    - If `compare(A, B) == 0`, then `compare(A, C)` must equal `compare(B, C)` for any C
 *
 * ## Implementation Guidelines
 *
 * - **Performance**: Keep `compare()` fast; it may be called thousands of times during a search.
 * - **Stateless**: Avoid mutable state within the strategy; use constructor parameters for configuration.
 * - **Clear Priority**: Document the ordering criteria clearly in your implementation's PHPDoc.
 * - **Decimal Precision**: When comparing costs, consider using `PathCost::compare()` with
 *   an appropriate scale to avoid floating-point issues.
 *
 * ## Common Ordering Strategies
 *
 * - **Cost-first** (default): Minimize total path cost, then hops, then route signature.
 * - **Hops-first**: Minimize number of hops (route complexity), then cost.
 * - **Hybrid**: Balance cost and hops using weighted scoring.
 * - **Route-aware**: Prefer certain currencies or exchanges in the path.
 *
 * ## Usage with PathFinderService
 *
 * Pass your custom strategy to the `PathFinderService` constructor:
 *
 * ```php
 * $customStrategy = new MinimizeHopsStrategy(costScale: 6);
 * $service = new PathFinderService($graphBuilder, $customStrategy);
 * ```
 *
 * @api
 *
 * @see PathOrderKey For the data available for comparison
 * @see CostHopsSignatureOrderingStrategy For the default implementation
 */
interface PathOrderStrategy
{
    /**
     * Compares two path order keys to determine their relative priority.
     *
     * Implementations must return:
     * - A negative integer if `$left` should be prioritized over `$right`
     * - Zero if both paths have equal priority
     * - A positive integer if `$right` should be prioritized over `$left`
     *
     * **IMPORTANT**: Always use `insertionOrder()` as the final tie-breaker to ensure
     * stable sorting behavior:
     *
     * ```php
     * // After all your comparison logic...
     * if ($result === 0) {
     *     return $left->insertionOrder() <=> $right->insertionOrder();
     * }
     * ```
     *
     * @api
     *
     * @param PathOrderKey $left  The first path to compare
     * @param PathOrderKey $right The second path to compare
     *
     * @return int Negative if $left < $right, zero if equal, positive if $left > $right
     */
    public function compare(PathOrderKey $left, PathOrderKey $right): int;
}
