<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Domain\Order;

use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

/**
 * Immutable value object describing the fee components for an order fill.
 */
final class FeeBreakdown
{
    private function __construct(
        private readonly ?Money $baseFee,
        private readonly ?Money $quoteFee,
    ) {
    }

    public static function none(): self
    {
        return new self(null, null);
    }

    public static function forBase(Money $baseFee): self
    {
        return new self($baseFee, null);
    }

    public static function forQuote(Money $quoteFee): self
    {
        return new self(null, $quoteFee);
    }

    public static function of(?Money $baseFee, ?Money $quoteFee): self
    {
        if (null === $baseFee && null === $quoteFee) {
            return self::none();
        }

        return new self($baseFee, $quoteFee);
    }

    public function baseFee(): ?Money
    {
        return $this->baseFee;
    }

    public function quoteFee(): ?Money
    {
        return $this->quoteFee;
    }

    public function hasBaseFee(): bool
    {
        return null !== $this->baseFee && !$this->baseFee->isZero();
    }

    public function hasQuoteFee(): bool
    {
        return null !== $this->quoteFee && !$this->quoteFee->isZero();
    }

    public function isZero(): bool
    {
        $baseZero = null === $this->baseFee || $this->baseFee->isZero();
        $quoteZero = null === $this->quoteFee || $this->quoteFee->isZero();

        return $baseZero && $quoteZero;
    }

    public function merge(self $other): self
    {
        $baseFee = $this->baseFee;
        if (null !== $other->baseFee) {
            if (null === $baseFee) {
                $baseFee = $other->baseFee;
            } else {
                $baseFee = $baseFee->add($other->baseFee);
            }
        }

        $quoteFee = $this->quoteFee;
        if (null !== $other->quoteFee) {
            if (null === $quoteFee) {
                $quoteFee = $other->quoteFee;
            } else {
                $quoteFee = $quoteFee->add($other->quoteFee);
            }
        }

        return new self($baseFee, $quoteFee);
    }
}
