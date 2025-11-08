<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Support;

use SomeWork\P2PPathFinder\Domain\Order\FeeBreakdown;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

/**
 * Helper responsible for evaluating fills while accounting for order fees.
 *
 * @internal
 */
final class OrderFillEvaluator
{
    /**
     * @return array{
     *     quote: Money,
     *     grossBase: Money,
     *     netBase: Money,
     *     fees: FeeBreakdown,
     * }
     */
    public function evaluate(Order $order, Money $baseAmount): array
    {
        $rawQuote = $order->calculateQuoteAmount($baseAmount);
        $fees = $this->resolveFeeBreakdown($order, $baseAmount, $rawQuote);

        $grossBase = $order->calculateGrossBaseSpend($baseAmount, $fees);

        $netBase = $baseAmount;
        if (OrderSide::SELL === $order->side()) {
            $baseFee = $fees->baseFee();
            if (null !== $baseFee && !$baseFee->isZero()) {
                $netBase = $baseAmount->subtract($baseFee);
            }
        }

        $quoteFee = $fees->quoteFee();
        if (null === $quoteFee || $quoteFee->isZero()) {
            return [
                'quote' => $rawQuote,
                'grossBase' => $grossBase,
                'netBase' => $netBase,
                'fees' => $fees,
            ];
        }

        if (OrderSide::SELL === $order->side()) {
            $quote = $rawQuote->add($quoteFee);

            return [
                'quote' => $quote,
                'grossBase' => $grossBase,
                'netBase' => $netBase,
                'fees' => $fees,
            ];
        }

        $netQuote = $rawQuote->subtract($quoteFee);

        return [
            'quote' => $netQuote,
            'grossBase' => $grossBase,
            'netBase' => $netBase,
            'fees' => $fees,
        ];
    }

    private function resolveFeeBreakdown(Order $order, Money $baseAmount, Money $rawQuote): FeeBreakdown
    {
        $feePolicy = $order->feePolicy();
        if (null === $feePolicy) {
            return FeeBreakdown::none();
        }

        return $feePolicy->calculate($order->side(), $baseAmount, $rawQuote);
    }
}
