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
    /**
     * @var array<string, Money>
     */
    private array $zeroMoneyCache = [];

    /**
     * Constructs the GraphBuilder and sets the OrderFillEvaluator used for evaluating order fills.
     *
     * @param OrderFillEvaluator|null $fillEvaluator Optional evaluator to use; when `null`, a new OrderFillEvaluator is created and used.
     */
    public function __construct(?OrderFillEvaluator $fillEvaluator = null)
    {
        $this->fillEvaluator = $fillEvaluator ?? new OrderFillEvaluator();
    }

    /**
     * Build a weighted directed graph representation from a collection of orders.
     *
     * Each node represents a currency and contains outgoing edges derived from orders.
     * Non-Order values in the iterable are ignored.
     *
     * @param iterable<Order> $orders Iterable of Order objects to convert into graph edges.
     *
     * @psalm-param iterable<Order> $orders
     *
     * @return Graph Mapping of currency code to node data (`'currency'` and `'edges'`).
     *
     * @psalm-return Graph
     */
    public function build(iterable $orders): array
    {
        /**
         * @var Graph $graph
         *
         * @psalm-var Graph $graph
         */
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

            $this->initializeNode($graph, $fromCurrency);
            $this->initializeNode($graph, $toCurrency);

            $graph[$fromCurrency]['edges'][] = $this->createEdge($order, $fromCurrency, $toCurrency);
        }

        return $graph;
    }

    /**
         * Ensure a graph node for the given currency exists in the provided graph.
         *
         * If a node for $currency is already present this function does nothing;
         * otherwise it adds an entry with keys `currency` (the currency code) and
         * `edges` (an empty list).
         *
         * @param array<string, array{currency: string, edges: list<GraphEdge>}> &$graph Graph map keyed by currency; will be modified in place.
         *
         * @psalm-param array<string, array{currency: string, edges: list<GraphEdge>}> &$graph
         * @param string $currency Currency code for the node to ensure
         */
    private function initializeNode(array &$graph, string $currency): void
    {
        if (array_key_exists($currency, $graph)) {
            return;
        }

        /** @psalm-var list<GraphEdge> $edges */
        $edges = [];

        $graph[$currency] = [
            'currency' => $currency,
            'edges' => $edges,
        ];
    }

    /**
     * Build a graph edge representing the provided order between two currencies, including capacity ranges, effective rate, and fee-aware segments.
     *
     * @return GraphEdge
     * @psalm-return GraphEdge
     */
    private function createEdge(Order $order, string $fromCurrency, string $toCurrency): array
    {
        $bounds = $order->bounds();

        $minBase = $bounds->min();
        $maxBase = $bounds->max();

        $minFill = $this->fillEvaluator->evaluate($order, $minBase);
        $maxFill = $this->fillEvaluator->evaluate($order, $maxBase);

        $minFees = $minFill['fees'];
        $maxFees = $maxFill['fees'];
        $hasFees = !$minFees->isZero() || !$maxFees->isZero();

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
                $hasFees,
            ),
        ];
    }

    /**
         * Builds capacity segments for an edge when order fees are present.
         *
         * Each returned segment describes a capacity range for base, quote and gross base amounts.
         * - `isMandatory`: whether the segment is a required (fixed) allocation.
         * - `base`, `quote`, `grossBase`: each an array with `min` and `max` Money values defining the segment bounds.
         *
         * @param Money $minBase Minimum net base amount for the edge.
         * @param Money $maxBase Maximum net base amount for the edge.
         * @param Money $minQuote Minimum quote amount corresponding to $minBase.
         * @param Money $maxQuote Maximum quote amount corresponding to $maxBase.
         * @param Money $minGrossBase Minimum gross base amount for the edge.
         * @param Money $maxGrossBase Maximum gross base amount for the edge.
         * @param bool $hasFees Whether the order has fees; when false an empty list is returned.
         *
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
        Money $maxGrossBase,
        bool $hasFees
    ): array {
        if (!$hasFees) {
            return [];
        }

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
                    'min' => $this->zeroMoney($baseRemainder->currency(), $baseRemainder->scale()),
                    'max' => $baseRemainder,
                ],
                'quote' => [
                    'min' => $this->zeroMoney($quoteRemainder->currency(), $quoteRemainder->scale()),
                    'max' => $quoteRemainder,
                ],
                'grossBase' => [
                    'min' => $this->zeroMoney($grossBaseRemainder->currency(), $grossBaseRemainder->scale()),
                    'max' => $grossBaseRemainder,
                ],
            ];
        }

        if ([] === $segments) {
            $segments[] = [
                'isMandatory' => false,
                'base' => [
                    'min' => $this->zeroMoney($minBase->currency(), $minBase->scale()),
                    'max' => $this->zeroMoney($maxBase->currency(), $maxBase->scale()),
                ],
                'quote' => [
                    'min' => $this->zeroMoney($minQuote->currency(), $minQuote->scale()),
                    'max' => $this->zeroMoney($maxQuote->currency(), $maxQuote->scale()),
                ],
                'grossBase' => [
                    'min' => $this->zeroMoney($minGrossBase->currency(), $minGrossBase->scale()),
                    'max' => $this->zeroMoney($maxGrossBase->currency(), $maxGrossBase->scale()),
                ],
            ];
        }

        return $segments;
    }

    /**
     * Return a zero-valued Money instance for the given currency and scale, reusing a cached instance if available.
     *
     * @param string $currency ISO currency code.
     * @param int $scale Decimal scale (number of fraction digits) for the Money instance.
     * @return Money A zero Money object for the specified currency and scale.
     */
    private function zeroMoney(string $currency, int $scale): Money
    {
        $key = $currency.':'.$scale;

        if (!isset($this->zeroMoneyCache[$key])) {
            $this->zeroMoneyCache[$key] = Money::zero($currency, $scale);
        }

        return $this->zeroMoneyCache[$key];
    }

    /**
     * @codeCoverageIgnore
     */
    public function fillEvaluator(): OrderFillEvaluator
    {
        return $this->fillEvaluator;
    }
}