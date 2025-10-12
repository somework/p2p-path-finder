<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Domain\Order;

use InvalidArgumentException;
use SomeWork\P2PPathFinder\Domain\ValueObject\AssetPair;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Domain\ValueObject\OrderBounds;

/**
 * Domain entity describing an order that can be traversed within a path search.
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
     * Validates that the provided amount can be used to partially fill the order.
     */
    public function validatePartialFill(Money $baseAmount): void
    {
        $this->assertBaseCurrency($baseAmount);

        if (!$this->bounds->contains($baseAmount)) {
            throw new InvalidArgumentException('Fill amount must be within order bounds.');
        }
    }

    /**
     * Calculates the quote currency proceeds for the provided base amount.
     */
    public function calculateQuoteAmount(Money $baseAmount): Money
    {
        $this->assertBaseCurrency($baseAmount);

        $scale = max($baseAmount->scale(), $this->effectiveRate->scale());

        return $this->effectiveRate->convert($baseAmount, $scale);
    }

    /**
     * Calculates the quote amount adjusted by the fee policy when present.
     */
    public function calculateEffectiveQuoteAmount(Money $baseAmount): Money
    {
        $this->validatePartialFill($baseAmount);

        $quoteAmount = $this->calculateQuoteAmount($baseAmount);

        if (null === $this->feePolicy) {
            return $quoteAmount;
        }

        $fee = $this->feePolicy->calculate($this->side, $baseAmount, $quoteAmount);
        if ($fee->currency() !== $quoteAmount->currency()) {
            throw new InvalidArgumentException('Fee policy must return money in quote asset currency.');
        }

        return $quoteAmount->subtract($fee);
    }

    private function assertConsistency(): void
    {
        $boundsCurrency = $this->bounds->min()->currency();
        if ($boundsCurrency !== $this->assetPair->base()) {
            throw new InvalidArgumentException('Order bounds must be expressed in the base asset.');
        }

        if ($this->effectiveRate->baseCurrency() !== $this->assetPair->base()) {
            throw new InvalidArgumentException('Effective rate base currency must match asset pair base.');
        }

        if ($this->effectiveRate->quoteCurrency() !== $this->assetPair->quote()) {
            throw new InvalidArgumentException('Effective rate quote currency must match asset pair quote.');
        }
    }

    private function assertBaseCurrency(Money $money): void
    {
        if ($money->currency() !== $this->assetPair->base()) {
            throw new InvalidArgumentException('Fill amount must use the order base asset.');
        }
    }
}
