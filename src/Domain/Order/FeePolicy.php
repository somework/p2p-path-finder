<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Domain\Order;

use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

/**
 * Describes how fees are computed for an order fill.
 *
 * Implementations must ensure the fingerprint() method returns a globally unique identifier
 * that deterministically represents the policy configuration. The fingerprint is used for
 * stable ordering and comparison of fee policies across the system.
 *
 * ## Fingerprint Requirements
 *
 * 1. **Uniqueness:** Different policy configurations MUST produce different fingerprints
 * 2. **Determinism:** Same policy configuration MUST always produce the same fingerprint
 * 3. **Non-empty:** Fingerprint MUST be a non-empty string
 * 4. **Reasonable length:** Recommended ≤255 characters for practicality
 *
 * ## Recommended Format
 *
 * Use colon-separated components: `"PolicyType:param1:param2:..."`
 *
 * Examples:
 * - `"base-surcharge:0.001:6"` (base fee of 0.1% at scale 6)
 * - `"quote-percentage-fixed:0.005:2.50:2"` (0.5% + $2.50 fixed fee)
 * - `"base-quote-surcharge:0.002:0.003:8"` (0.2% base + 0.3% quote)
 *
 * The recommended format ensures uniqueness by including:
 * - Policy type identifier (distinguishes different implementations)
 * - All configuration parameters that affect fee calculation
 * - Scale or precision information when relevant
 */
interface FeePolicy
{
    /**
     * Calculates the fee components to apply for the provided order side and amounts.
     */
    public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown;

    /**
     * Provides a stable identifier describing the policy configuration for deterministic ordering.
     *
     * The fingerprint must be globally unique across all policy instances. Two policies with
     * different configurations (even of the same type) must return different fingerprints.
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
