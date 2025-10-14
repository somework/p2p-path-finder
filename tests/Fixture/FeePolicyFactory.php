<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Fixture;

use SomeWork\P2PPathFinder\Domain\Order\FeeBreakdown;
use SomeWork\P2PPathFinder\Domain\Order\FeePolicy;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

final class FeePolicyFactory
{
    /**
     * @param numeric-string $ratio
     */
    public static function baseSurcharge(string $ratio, int $scale = 6): FeePolicy
    {
        return new class($ratio, $scale) implements FeePolicy {
            public function __construct(private readonly string $ratio, private readonly int $scale)
            {
            }

            public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
            {
                $feeScale = max($baseAmount->scale(), $this->scale);
                $fee = $baseAmount->multiply($this->ratio, $feeScale)->withScale($baseAmount->scale());

                return FeeBreakdown::forBase($fee);
            }
        };
    }

    /**
     * @param numeric-string $baseRatio
     * @param numeric-string $quoteRatio
     */
    public static function baseAndQuoteSurcharge(string $baseRatio, string $quoteRatio, int $scale = 6): FeePolicy
    {
        return new class($baseRatio, $quoteRatio, $scale) implements FeePolicy {
            public function __construct(
                private readonly string $baseRatio,
                private readonly string $quoteRatio,
                private readonly int $scale
            ) {
            }

            public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
            {
                $feeScale = max($this->scale, $baseAmount->scale(), $quoteAmount->scale());
                $baseFee = $baseAmount->multiply($this->baseRatio, $feeScale)->withScale($baseAmount->scale());
                $quoteFee = $quoteAmount->multiply($this->quoteRatio, $feeScale)->withScale($quoteAmount->scale());

                return FeeBreakdown::of($baseFee, $quoteFee);
            }
        };
    }
}
