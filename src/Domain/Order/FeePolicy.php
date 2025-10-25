<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Domain\Order;

use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

/**
 * Describes how fees are computed for an order fill.
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
     * @phpstan-return string
     *
     * @psalm-return non-empty-string
     */
    public function fingerprint(): string;
}
