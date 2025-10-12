<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Domain\Order;

use InvalidArgumentException;
use SomeWork\P2PPathFinder\Domain\ValueObject\AssetPair;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Domain\ValueObject\OrderBounds;

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

    public function side(): OrderSide
    {
        return $this->side;
    }

    public function assetPair(): AssetPair
    {
        return $this->assetPair;
    }

    public function bounds(): OrderBounds
    {
        return $this->bounds;
    }

    public function effectiveRate(): ExchangeRate
    {
        return $this->effectiveRate;
    }

    public function feePolicy(): ?FeePolicy
    {
        return $this->feePolicy;
    }

    public function validatePartialFill(Money $baseAmount): void
    {
        $this->assertBaseCurrency($baseAmount);

        if (!$this->bounds->contains($baseAmount)) {
            throw new InvalidArgumentException('Fill amount must be within order bounds.');
        }
    }

    public function calculateQuoteAmount(Money $baseAmount): Money
    {
        $this->assertBaseCurrency($baseAmount);

        $scale = max($baseAmount->scale(), $this->effectiveRate->scale());

        return $this->effectiveRate->convert($baseAmount, $scale);
    }

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

        return match ($this->side) {
            OrderSide::BUY => $quoteAmount->add($fee),
            OrderSide::SELL => $quoteAmount->subtract($fee),
        };
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
