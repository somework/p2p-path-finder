<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Graph;

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
     *     segments: list<array{
     *         isMandatory: bool,
     *         base: array{min: Money, max: Money},
     *         quote: array{min: Money, max: Money},
     *     }>,
     * }
     */
    private function createEdge(Order $order, string $fromCurrency, string $toCurrency): array
    {
        $bounds = $order->bounds();

        $minBase = $bounds->min();
        $maxBase = $bounds->max();

        $minQuote = $order->calculateEffectiveQuoteAmount($minBase);
        $maxQuote = $order->calculateEffectiveQuoteAmount($maxBase);

        return [
            'from' => $fromCurrency,
            'to' => $toCurrency,
            'orderSide' => $order->side(),
            'order' => $order,
            'rate' => $order->effectiveRate(),
            'baseCapacity' => [
                'min' => $minBase,
                'max' => $maxBase,
            ],
            'quoteCapacity' => [
                'min' => $minQuote,
                'max' => $maxQuote,
            ],
            'segments' => $this->buildSegments($minBase, $maxBase, $minQuote, $maxQuote),
        ];
    }

    /**
     * @return list<array{
     *     isMandatory: bool,
     *     base: array{min: Money, max: Money},
     *     quote: array{min: Money, max: Money},
     * }>
     */
    private function buildSegments(Money $minBase, Money $maxBase, Money $minQuote, Money $maxQuote): array
    {
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
            ];
        }

        $baseRemainder = $maxBase->subtract($minBase);
        if (!$baseRemainder->isZero()) {
            $quoteRemainder = $maxQuote->subtract($minQuote);

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
            ];
        }

        return $segments;
    }
}
