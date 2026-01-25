<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Domain\Order;

use SomeWork\P2PPathFinder\Domain\Money\AssetPair;
use SomeWork\P2PPathFinder\Domain\Money\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\Fee\FeeBreakdown;
use SomeWork\P2PPathFinder\Domain\Order\Fee\FeePolicy;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

/**
 * Domain entity describing an order that can be traversed within a path search.
 *
 * ## Invariants
 *
 * - **Currency consistency**: All components must align with AssetPair
 *   - Bounds must be in base currency
 *   - Effective rate base/quote must match asset pair base/quote
 *   - Base fees (if present) must be in base currency
 *   - Quote fees (if present) must be in quote currency
 * - **Partial fill validation**: Fill amounts must be within bounds and in base currency
 * - **Quote calculation**: calculateQuoteAmount = effectiveRate.convert(baseAmount)
 * - **Effective quote**: calculateEffectiveQuoteAmount = quoteAmount - quoteFee (if present)
 * - **Gross spend**: calculateGrossBaseSpend = baseAmount + baseFee (if present)
 *
 * @invariant bounds.currency == assetPair.base
 * @invariant effectiveRate.baseCurrency == assetPair.base
 * @invariant effectiveRate.quoteCurrency == assetPair.quote
 * @invariant baseFee (if present) in base currency
 * @invariant quoteFee (if present) in quote currency
 * @invariant validatePartialFill ensures bounds.contains(amount)
 * @invariant calculateQuoteAmount = effectiveRate.convert(baseAmount)
 * @invariant calculateEffectiveQuoteAmount = quoteAmount - quoteFee
 * @invariant calculateGrossBaseSpend = baseAmount + baseFee
 *
 * @api
 */
final class Order
{
    public function __construct(
        private readonly OrderSide $side,
        private readonly AssetPair $assetPair,
        private readonly OrderBounds $bounds,
        private readonly ExchangeRate $effectiveRate,
        private readonly ?FeePolicy $feePolicy = null,
    ) {
        $this->assertConsistency();
    }

    /**
     * Returns whether the order is a buy or sell side order.
     */
    public function side(): OrderSide
    {
        return $this->side;
    }

    /**
     * Returns the asset pair quoted by the order.
     */
    public function assetPair(): AssetPair
    {
        return $this->assetPair;
    }

    /**
     * Returns the admissible fill bounds for the order's base asset.
     */
    public function bounds(): OrderBounds
    {
        return $this->bounds;
    }

    /**
     * Returns the effective exchange rate applied when filling the order.
     */
    public function effectiveRate(): ExchangeRate
    {
        return $this->effectiveRate;
    }

    /**
     * Returns the fee policy, if any, associated with the order.
     */
    public function feePolicy(): ?FeePolicy
    {
        return $this->feePolicy;
    }

    /**
     * Returns whether this order represents a same-currency transfer.
     *
     * Transfer orders have identical base and quote currencies in their asset pair
     * and represent cross-exchange movements rather than currency conversions.
     * The exchange rate should be 1:1 and any fees represent network/withdrawal costs.
     */
    public function isTransfer(): bool
    {
        return $this->assetPair->isTransfer();
    }

    /**
     * Validates that the provided amount can be used to partially fill the order.
     *
     * @throws InvalidInput|PrecisionViolation when the amount currency is invalid or outside the allowed bounds
     */
    public function validatePartialFill(Money $baseAmount): void
    {
        $this->assertBaseCurrency($baseAmount);

        if (!$this->bounds->contains($baseAmount)) {
            throw new InvalidInput('Fill amount must be within order bounds.');
        }
    }

    /**
     * Calculates the quote currency proceeds for the provided base amount.
     *
     * @throws InvalidInput|PrecisionViolation when the base amount does not use the order's base currency
     */
    public function calculateQuoteAmount(Money $baseAmount): Money
    {
        $this->assertBaseCurrency($baseAmount);

        $scale = max($baseAmount->scale(), $this->effectiveRate->scale());

        return $this->effectiveRate->convert($baseAmount, $scale);
    }

    /**
     * Calculates the quote amount adjusted by the fee policy when present.
     *
     * @throws InvalidInput|PrecisionViolation when the provided amounts or fee breakdown violate currency constraints
     */
    public function calculateEffectiveQuoteAmount(Money $baseAmount): Money
    {
        $this->validatePartialFill($baseAmount);

        $quoteAmount = $this->calculateQuoteAmount($baseAmount);

        if (null === $this->feePolicy) {
            return $quoteAmount;
        }

        $fees = $this->feePolicy->calculate($this->side, $baseAmount, $quoteAmount);
        $quoteFee = $fees->quoteFee();

        if (null === $quoteFee || $quoteFee->isZero()) {
            $baseFee = $fees->baseFee();
            if (null !== $baseFee && !$baseFee->isZero()) {
                $this->assertBaseFeeCurrency($baseFee, $baseAmount);
            }

            return $quoteAmount;
        }

        $this->assertQuoteFeeCurrency($quoteFee, $quoteAmount);

        $baseFee = $fees->baseFee();
        if (null !== $baseFee && !$baseFee->isZero()) {
            $this->assertBaseFeeCurrency($baseFee, $baseAmount);
        }

        return $quoteAmount->subtract($quoteFee);
    }

    /**
     * Calculates the total base asset required to fill the provided net amount.
     *
     * @throws InvalidInput|PrecisionViolation when the requested amount or fee breakdown uses inconsistent currencies
     */
    public function calculateGrossBaseSpend(Money $baseAmount, ?FeeBreakdown $feeBreakdown = null): Money
    {
        $this->validatePartialFill($baseAmount);

        $fees = $feeBreakdown;
        if (null === $fees) {
            if (null === $this->feePolicy) {
                return $baseAmount;
            }

            $quoteAmount = $this->calculateQuoteAmount($baseAmount);
            $fees = $this->feePolicy->calculate($this->side, $baseAmount, $quoteAmount);
        }

        $baseFee = $fees->baseFee();
        if (null === $baseFee || $baseFee->isZero()) {
            return $baseAmount;
        }

        $this->assertBaseFeeCurrency($baseFee, $baseAmount);

        return $baseAmount->add($baseFee);
    }

    private function assertConsistency(): void
    {
        $boundsCurrency = $this->bounds->min()->currency();
        if ($boundsCurrency !== $this->assetPair->base()) {
            throw new InvalidInput('Order bounds must be expressed in the base asset.');
        }

        if ($this->effectiveRate->baseCurrency() !== $this->assetPair->base()) {
            throw new InvalidInput('Effective rate base currency must match asset pair base.');
        }

        if ($this->effectiveRate->quoteCurrency() !== $this->assetPair->quote()) {
            throw new InvalidInput('Effective rate quote currency must match asset pair quote.');
        }
    }

    private function assertBaseCurrency(Money $money): void
    {
        if ($money->currency() !== $this->assetPair->base()) {
            throw new InvalidInput('Fill amount must use the order base asset.');
        }
    }

    private function assertQuoteFeeCurrency(Money $fee, Money $quoteAmount): void
    {
        if ($fee->currency() !== $quoteAmount->currency()) {
            throw new InvalidInput('Fee policy must return money in quote asset currency.');
        }
    }

    private function assertBaseFeeCurrency(Money $fee, Money $baseAmount): void
    {
        if ($fee->currency() !== $baseAmount->currency()) {
            throw new InvalidInput('Fee policy must return money in base asset currency.');
        }
    }
}
