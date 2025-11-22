<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Filter;

use SomeWork\P2PPathFinder\Domain\Order\Order;

/**
 * Strategy interface for filtering orders before graph construction and path search.
 *
 * Implementations determine whether specific orders should participate in path finding
 * by examining order properties (asset pairs, amounts, fees, etc.) and returning true
 * if the order passes the filter criteria.
 *
 * ## Usage
 *
 * Filters are applied via OrderBook::filter() or by pre-filtering orders before
 * constructing an OrderBook. Multiple filters can be chained together.
 *
 * ## Performance Contract
 *
 * - Filter evaluation MUST be O(1) per order (constant time)
 * - Avoid expensive operations like database queries or network calls
 * - Keep filter logic simple and focused on a single concern
 * - Cache computed values in constructor if needed
 *
 * ## Immutability Contract
 *
 * - Filters MUST NOT modify the order being evaluated
 * - Filter state MUST be immutable after construction
 * - Filter evaluation MUST be side-effect free (pure function)
 * - Thread-safe by design (stateless evaluation)
 *
 * ## Best Practices
 *
 * 1. **Single Responsibility**: Each filter should check one criterion
 * 2. **Composition**: Combine multiple simple filters rather than one complex filter
 * 3. **Early Return**: Return false as soon as a condition fails
 * 4. **Scale Handling**: Normalize scales when comparing Money values
 * 5. **Currency Matching**: Always verify currency compatibility before comparisons
 *
 * ## Example Implementation
 *
 * ```php
 * final class MaxSpreadFilter implements OrderFilterInterface
 * {
 *     public function __construct(private readonly string $maxSpread) {}
 *
 *     public function accepts(Order $order): bool
 *     {
 *         $rate = $order->effectiveRate();
 *         $spread = $this->calculateSpread($rate);
 *         return bccomp($spread, $this->maxSpread, 8) <= 0;
 *     }
 * }
 * ```
 *
 * @see OrderBook::filter() for applying filters to order collections
 * @see CurrencyPairFilter for filtering by asset pair
 * @see MinimumAmountFilter for filtering by minimum order size
 * @see MaximumAmountFilter for filtering by maximum order size
 */
interface OrderFilterInterface
{
    /**
     * Determines whether the provided order satisfies the filter conditions.
     *
     * This method MUST be side-effect free and MUST NOT modify the order.
     * It should execute in constant time O(1) relative to the order book size.
     *
     * @param Order $order The order to evaluate (MUST NOT be modified)
     *
     * @return bool True if the order passes the filter and should be included,
     *              false if the order should be excluded from path finding
     */
    public function accepts(Order $order): bool;
}
