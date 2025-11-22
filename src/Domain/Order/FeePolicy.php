<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Domain\Order;

use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

/**
 * Defines a strategy for calculating fees applied to order fills.
 *
 * Implementations of this interface allow consumers to define custom fee calculation logic
 * that is attached to `Order` instances. Fees are computed during path finding as the
 * algorithm evaluates how much of an order can be filled given spend constraints.
 *
 * ## Core Responsibilities
 *
 * - **Fee Calculation**: Compute fee amounts based on order side and fill amounts
 * - **Currency Specification**: Return fees in the appropriate currency (base or quote)
 * - **Deterministic Identification**: Provide a unique fingerprint for the policy configuration
 *
 * ## When Fees Are Applied
 *
 * Fees are calculated during the path finding process when:
 * 1. The algorithm determines how much of an order can be filled
 * 2. A candidate path is being evaluated for feasibility
 * 3. The final path cost is being computed
 *
 * The `calculate()` method is called with:
 * - The order's side (BUY or SELL)
 * - The base asset amount being traded
 * - The quote asset amount being traded
 *
 * ## Currency Constraints
 *
 * Fees MUST be denominated in a currency that matches the order's trading pair:
 *
 * - **Base Fees**: Must be in the same currency as `$baseAmount` (the order's base asset)
 * - **Quote Fees**: Must be in the same currency as `$quoteAmount` (the order's quote asset)
 * - **Both**: You can return fees in both currencies if needed
 *
 * **CRITICAL**: The currency of returned fees MUST match the corresponding Money object's
 * currency, or path finding calculations will fail. The system does NOT perform automatic
 * currency conversion for fees.
 *
 * ### Currency Examples:
 *
 * For an order with pair USD/EUR:
 * - Base asset is USD → base fees must be in USD
 * - Quote asset is EUR → quote fees must be in EUR
 * - You can return fees in USD, EUR, or both, but NOT in any other currency
 *
 * ## Calculation Order
 *
 * The path finding algorithm calls `calculate()` in this sequence:
 *
 * 1. **Order Evaluation**: For each order being considered, calculate fees based on
 *    the proposed fill amounts
 * 2. **Cost Accumulation**: Fees are added to the path's total cost
 * 3. **Feasibility Check**: The system verifies the path (including fees) fits within
 *    spend constraints and tolerance bounds
 *
 * Fees affect:
 * - **Total Cost**: Higher fees increase path cost (used for ordering)
 * - **Output Amount**: Fees reduce the net amount received at each hop
 * - **Feasibility**: Large fees may make otherwise viable paths infeasible
 *
 * ## Implementation Guidelines
 *
 * - **Stateless**: Avoid mutable state; use constructor parameters for configuration
 * - **Fast Computation**: Keep `calculate()` performant; it's called frequently during search
 * - **Precise Arithmetic**: Use `Money` and BigDecimal for all calculations to avoid precision loss
 * - **Currency Safety**: Always verify currency matches before creating fee Money objects
 * - **Non-negative**: Fees should be zero or positive (negative fees are not supported)
 * - **Deterministic**: Same inputs must always produce same outputs
 *
 * ## Common Fee Models
 *
 * - **Percentage Fee**: Fee is a percentage of the traded amount (e.g., 0.5%)
 * - **Fixed Fee**: Flat fee per transaction (e.g., $2.50 per order)
 * - **Tiered Fee**: Fee rate depends on trade volume (e.g., 0.5% for <$1000, 0.3% for ≥$1000)
 * - **Maker/Taker**: Different fees based on order side (BUY vs SELL)
 * - **Combined**: Mix of percentage + fixed (e.g., 0.5% + $1.00)
 *
 * ## Fingerprint Requirements
 *
 * The `fingerprint()` method MUST return a globally unique identifier that deterministically
 * represents the policy configuration. The fingerprint is used for stable ordering and
 * comparison of fee policies across the system.
 *
 * 1. **Uniqueness:** Different policy configurations MUST produce different fingerprints
 * 2. **Determinism:** Same policy configuration MUST always produce the same fingerprint
 * 3. **Non-empty:** Fingerprint MUST be a non-empty string
 * 4. **Reasonable length:** Recommended ≤255 characters for practicality
 *
 * ### Recommended Fingerprint Format
 *
 * Use colon-separated components: `"PolicyType:param1:param2:..."`
 *
 * Examples:
 * - `"base-percentage:0.005:6"` (0.5% base fee at scale 6)
 * - `"quote-fixed:2.50:USD:2"` ($2.50 fixed quote fee in USD)
 * - `"tiered:0.005:1000:0.003:2"` (0.5% under $1000, 0.3% above)
 * - `"maker-taker:0.002:0.003:6"` (0.2% maker, 0.3% taker)
 *
 * The recommended format ensures uniqueness by including:
 * - Policy type identifier (distinguishes different implementations)
 * - All configuration parameters that affect fee calculation
 * - Scale or precision information when relevant
 * - Currency information for fixed fees
 *
 * ## Usage with Orders
 *
 * Attach a fee policy to an order at construction:
 *
 * ```php
 * $feePolicy = new PercentageFeePolicy(rate: '0.005', scale: 6);
 * $order = new Order($side, $pair, $bounds, $rate, $feePolicy);
 * ```
 *
 * Orders without a fee policy (null) are treated as fee-free.
 *
 * @api
 *
 * @see FeeBreakdown For the return type structure
 * @see FeePolicyHelper For validation utilities
 * @see Order For attaching policies to orders
 */
interface FeePolicy
{
    /**
     * Calculates the fee components to apply for the provided order side and amounts.
     *
     * This method is called during path finding to determine fees for a specific order fill.
     * It receives the order's side and the proposed fill amounts for both base and quote assets.
     *
     * **Currency Constraints**: Returned fees MUST match the currency of the corresponding
     * Money parameter:
     * - Base fees must be in `$baseAmount->currency()`
     * - Quote fees must be in `$quoteAmount->currency()`
     *
     * **Return Value**: Use `FeeBreakdown` factory methods:
     * - `FeeBreakdown::none()` - No fees
     * - `FeeBreakdown::forBase($baseFee)` - Fee in base currency only
     * - `FeeBreakdown::forQuote($quoteFee)` - Fee in quote currency only
     * - `FeeBreakdown::of($baseFee, $quoteFee)` - Fees in both currencies
     *
     * **Performance**: This method may be called thousands of times during a search.
     * Keep computation fast and avoid expensive operations.
     *
     * @param OrderSide $side       The order side (BUY or SELL)
     * @param Money     $baseAmount The amount being traded in the base asset
     * @param Money     $quoteAmount The amount being traded in the quote asset
     *
     * @return FeeBreakdown The calculated fee breakdown
     *
     * @example
     * ```php
     * // Example: Fixed percentage fee policy (0.5% on quote amount)
     * class PercentageFeePolicy implements FeePolicy
     * {
     *     public function __construct(private readonly string $rate) {}
     *
     *     public function calculate(OrderSide $side, Money $base, Money $quote): FeeBreakdown
     *     {
     *         // Calculate 0.5% fee on quote amount
     *         $fee = $quote->multiply($this->rate, $quote->scale());
     *         return FeeBreakdown::forQuote($fee);
     *     }
     *
     *     public function fingerprint(): string
     *     {
     *         return "quote-percentage:{$this->rate}";
     *     }
     * }
     *
     * // Example: Tiered fee policy
     * class TieredFeePolicy implements FeePolicy
     * {
     *     public function calculate(OrderSide $side, Money $base, Money $quote): FeeBreakdown
     *     {
     *         // 0.5% for < $1000, 0.25% for >= $1000
     *         $rate = $quote->compareTo(Money::fromString('USD', '1000', 2)) >= 0
     *             ? '0.0025'  // 0.25%
     *             : '0.005';  // 0.5%
     *
     *         $fee = $quote->multiply($rate, $quote->scale());
     *         return FeeBreakdown::forQuote($fee);
     *     }
     *
     *     public function fingerprint(): string
     *     {
     *         return 'tiered:0.005:1000:0.0025';
     *     }
     * }
     * ```
     *
     * @see examples/custom-fee-policy.php For complete implementations
     */
    public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown;

    /**
     * Provides a stable identifier describing the policy configuration for deterministic ordering.
     *
     * The fingerprint must be globally unique across all policy instances. Two policies with
     * different configurations (even of the same type) must return different fingerprints.
     *
     * @api
     *
     * @return non-empty-string A unique identifier for this policy configuration
     *
     * @see FeePolicyFactory for examples of proper fingerprint implementation
     *
     * @phpstan-return non-empty-string
     *
     * @psalm-return non-empty-string
     */
    public function fingerprint(): string;
}
