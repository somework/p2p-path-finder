<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Graph;

use SomeWork\P2PPathFinder\Application\PathFinder\PathFinder;
use SomeWork\P2PPathFinder\Application\Support\OrderFillEvaluator;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

use function array_key_exists;

/**
 * Converts a collection of domain orders into a weighted directed graph representation.
 *
 * @psalm-import-type Graph from PathFinder
 * @psalm-import-type GraphEdge from PathFinder
 *
 * @phpstan-import-type Graph from PathFinder
 * @phpstan-import-type GraphEdge from PathFinder
 */
final class GraphBuilder
{
    private OrderFillEvaluator $fillEvaluator;

    public function __construct(?OrderFillEvaluator $fillEvaluator = null)
    {
        $this->fillEvaluator = $fillEvaluator ?? new OrderFillEvaluator();
    }

    /**
     * @param iterable<Order> $orders
     *
     * @return Graph
     *
     * @psalm-return Graph
     */
    public function build(iterable $orders): array
    {
        /** @var Graph $graph */
        /** @psalm-var Graph $graph */
        $graph = [];

        foreach ($orders as $order) {
            if (!$order instanceof Order) {
                continue;
            }

            $pair = $order->assetPair();

            [$fromCurrency, $toCurrency] = match ($order->side()) {
                OrderSide::BUY => [$pair->base(), $pair->quote()],
                OrderSide::SELL => [$pair->quote(), $pair->base()],
            };

            $graph = $this->initializeNode($graph, $fromCurrency);
            $graph = $this->initializeNode($graph, $toCurrency);

            /** @var array{currency: string, edges: list<GraphEdge>} $fromNode */
            $fromNode = $graph[$fromCurrency];
            $fromNode['edges'][] = $this->createEdge($order, $fromCurrency, $toCurrency);
            $graph[$fromCurrency] = $fromNode;
        }

        return $graph;
    }

    /**
     * @param Graph $graph
     *
     * @psalm-param Graph $graph
     *
     * @return Graph
     *
     * @psalm-return Graph
     */
    private function initializeNode(array $graph, string $currency): array
    {
        if (array_key_exists($currency, $graph)) {
            return $graph;
        }

        /** @psalm-var list<GraphEdge> $edges */
        $edges = [];

        $graph[$currency] = [
            'currency' => $currency,
            'edges' => $edges,
        ];

        return $graph;
    }

    /**
     * @return GraphEdge
     *
     * @psalm-return GraphEdge
     */
    private function createEdge(Order $order, string $fromCurrency, string $toCurrency): array
    {
        $bounds = $order->bounds();

        $minBase = $bounds->min();
        $maxBase = $bounds->max();

        $minFill = $this->fillEvaluator->evaluate($order, $minBase);
        $maxFill = $this->fillEvaluator->evaluate($order, $maxBase);

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
     * @codeCoverageIgnore
     */
    public function fillEvaluator(): OrderFillEvaluator
    {
        return $this->fillEvaluator;
    }
}
