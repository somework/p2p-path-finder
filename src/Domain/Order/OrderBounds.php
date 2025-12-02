<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Domain\Order;

use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

/**
 * Represents inclusive lower/upper bounds for the fillable base asset amount of an order.
 *
 * ## Invariants
 *
 * - **Currency consistency**: Min and max must share the same currency
 * - **Bounds ordering**: Min amount must not exceed max amount (min <= max)
 * - **Scale normalization**: Internal scale = max(min.scale, max.scale)
 * - **Inclusive bounds**: contains() checks min <= amount <= max (inclusive on both ends)
 *
 * @invariant min.currency == max.currency
 * @invariant min <= max
 * @invariant internal scale = max(min.scale, max.scale)
 * @invariant contains(amount) checks min <= amount <= max (inclusive)
 * @invariant clamp(amount) returns value within [min, max]
 *
 * @see Order::bounds() For usage in orders
 * @see docs/domain-invariants.md#orderbounds For complete constraints
 *
 * @api
 */
final class OrderBounds
{
    private function __construct(
        private readonly Money $min,
        private readonly Money $max,
    ) {
    }

    /**
     * Constructs an order bounds instance after validating currency consistency.
     *
     * @throws InvalidInput|PrecisionViolation when the provided amounts are inconsistent or cannot be normalized
     */
    public static function from(Money $min, Money $max): self
    {
        self::assertCurrencyConsistency($min, $max);
        if ($min->greaterThan($max)) {
            throw new InvalidInput('Minimum amount cannot exceed the maximum amount.');
        }

        $scale = max($min->scale(), $max->scale());

        return new self($min->withScale($scale), $max->withScale($scale));
    }

    /**
     * Returns the minimum permissible base asset amount.
     */
    public function min(): Money
    {
        return $this->min;
    }

    /**
     * Returns the maximum permissible base asset amount.
     */
    public function max(): Money
    {
        return $this->max;
    }

    /**
     * Checks whether the provided amount falls within the configured bounds.
     *
     * @throws InvalidInput|PrecisionViolation when the amount currency or scale is incompatible with the bounds
     */
    public function contains(Money $amount): bool
    {
        $this->assertCurrency($amount);
        $scaled = $amount->withScale($this->min->scale());

        return !$scaled->lessThan($this->min) && !$scaled->greaterThan($this->max);
    }

    /**
     * Clamps the provided amount to the bounds and returns the adjusted value.
     *
     * @throws InvalidInput|PrecisionViolation when the amount currency or scale is incompatible with the bounds
     */
    public function clamp(Money $amount): Money
    {
        $this->assertCurrency($amount);
        $scaled = $amount->withScale($this->min->scale());

        if ($scaled->lessThan($this->min)) {
            return $this->min;
        }

        if ($scaled->greaterThan($this->max)) {
            return $this->max;
        }

        return $scaled;
    }

    private static function assertCurrencyConsistency(Money $first, Money $second): void
    {
        if ($first->currency() !== $second->currency()) {
            throw new InvalidInput('Bounds must share the same currency.');
        }
    }

    private function assertCurrency(Money $money): void
    {
        if ($money->currency() !== $this->min->currency()) {
            throw new InvalidInput('Money currency must match order bounds.');
        }
    }
}
