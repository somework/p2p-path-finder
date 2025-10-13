<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Graph;

use SomeWork\P2PPathFinder\Domain\Order\FeeBreakdown;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

use function array_key_exists;

/**
 * Converts a collection of domain orders into a weighted directed graph representation.
 */
final class GraphBuilder
{
    /**
     * @param iterable<Order> $orders
     *
     * @return array<string, array{currency: string, edges: list<array<string, mixed>>}>
     */
    public function build(iterable $orders): array
    {
        $graph = [];

        foreach ($orders as $order) {
            $pair = $order->assetPair();

            [$fromCurrency, $toCurrency] = match ($order->side()) {
                OrderSide::BUY => [$pair->base(), $pair->quote()],
                OrderSide::SELL => [$pair->quote(), $pair->base()],
            };

            $this->initializeNode($graph, $fromCurrency);
            $this->initializeNode($graph, $toCurrency);

            $graph[$fromCurrency]['edges'][] = $this->createEdge($order, $fromCurrency, $toCurrency);
        }

        return $graph;
    }

    /**
     * @param array<string, array{currency: string, edges: list<array<string, mixed>>}> $graph
     */
    private function initializeNode(array &$graph, string $currency): void
    {
        if (array_key_exists($currency, $graph)) {
            return;
        }

        $graph[$currency] = [
            'currency' => $currency,
            'edges' => [],
        ];
    }

    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     orderSide: OrderSide,
     *     order: Order,
     *     rate: ExchangeRate,
     *     baseCapacity: array{min: Money, max: Money},
     *     quoteCapacity: array{min: Money, max: Money},
     *     grossBaseCapacity: array{min: Money, max: Money},
     *     segments: list<array{
     *         isMandatory: bool,
     *         base: array{min: Money, max: Money},
     *         quote: array{min: Money, max: Money},
     *         grossBase: array{min: Money, max: Money},
     *     }>,
     * }
     */
    private function createEdge(Order $order, string $fromCurrency, string $toCurrency): array
    {
        $bounds = $order->bounds();

        $minBase = $bounds->min();
        $maxBase = $bounds->max();

        $minFill = $this->evaluateFill($order, $minBase);
        $maxFill = $this->evaluateFill($order, $maxBase);

        return [
            'from' => $fromCurrency,
            'to' => $toCurrency,
            'orderSide' => $order->side(),
            'order' => $order,
            'rate' => $order->effectiveRate(),
            'baseCapacity' => [
                'min' => $minFill['netBase'],
                'max' => $maxFill['netBase'],
            ],
            'quoteCapacity' => [
                'min' => $minFill['quote'],
                'max' => $maxFill['quote'],
            ],
            'grossBaseCapacity' => [
                'min' => $minFill['grossBase'],
                'max' => $maxFill['grossBase'],
            ],
            'segments' => $this->buildSegments(
                $minFill['netBase'],
                $maxFill['netBase'],
                $minFill['quote'],
                $maxFill['quote'],
                $minFill['grossBase'],
                $maxFill['grossBase'],
            ),
        ];
    }

    /**
     * @return list<array{
     *     isMandatory: bool,
     *     base: array{min: Money, max: Money},
     *     quote: array{min: Money, max: Money},
     *     grossBase: array{min: Money, max: Money},
     * }>
     */
    private function buildSegments(
        Money $minBase,
        Money $maxBase,
        Money $minQuote,
        Money $maxQuote,
        Money $minGrossBase,
        Money $maxGrossBase
    ): array {
        $segments = [];

        if (!$minBase->isZero()) {
            $segments[] = [
                'isMandatory' => true,
                'base' => [
                    'min' => $minBase,
                    'max' => $minBase,
                ],
                'quote' => [
                    'min' => $minQuote,
                    'max' => $minQuote,
                ],
                'grossBase' => [
                    'min' => $minGrossBase,
                    'max' => $minGrossBase,
                ],
            ];
        }

        $baseRemainder = $maxBase->subtract($minBase);
        if (!$baseRemainder->isZero()) {
            $quoteRemainder = $maxQuote->subtract($minQuote);
            $grossBaseRemainder = $maxGrossBase->subtract($minGrossBase);

            $segments[] = [
                'isMandatory' => false,
                'base' => [
                    'min' => Money::zero($baseRemainder->currency(), $baseRemainder->scale()),
                    'max' => $baseRemainder,
                ],
                'quote' => [
                    'min' => Money::zero($quoteRemainder->currency(), $quoteRemainder->scale()),
                    'max' => $quoteRemainder,
                ],
                'grossBase' => [
                    'min' => Money::zero($grossBaseRemainder->currency(), $grossBaseRemainder->scale()),
                    'max' => $grossBaseRemainder,
                ],
            ];
        }

        if ([] === $segments) {
            $segments[] = [
                'isMandatory' => false,
                'base' => [
                    'min' => Money::zero($minBase->currency(), $minBase->scale()),
                    'max' => Money::zero($maxBase->currency(), $maxBase->scale()),
                ],
                'quote' => [
                    'min' => Money::zero($minQuote->currency(), $minQuote->scale()),
                    'max' => Money::zero($maxQuote->currency(), $maxQuote->scale()),
                ],
                'grossBase' => [
                    'min' => Money::zero($minGrossBase->currency(), $minGrossBase->scale()),
                    'max' => Money::zero($maxGrossBase->currency(), $maxGrossBase->scale()),
                ],
            ];
        }

        return $segments;
    }

    /**
     * @return array{
     *     quote: Money,
     *     grossBase: Money,
     *     netBase: Money,
     * }
     */
    private function evaluateFill(Order $order, Money $baseAmount): array
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
            ];
        }

        if (OrderSide::SELL === $order->side()) {
            $grossQuote = $rawQuote->add($quoteFee);

            return [
                'quote' => $grossQuote,
                'grossBase' => $grossBase,
                'netBase' => $netBase,
            ];
        }

        $netQuote = $rawQuote->subtract($quoteFee);

        return [
            'quote' => $netQuote,
            'grossBase' => $grossBase,
            'netBase' => $netBase,
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
