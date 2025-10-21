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
     * Create a GraphBuilder with an optional OrderFillEvaluator dependency.
     *
     * If no evaluator is provided, a new OrderFillEvaluator instance is created and used.
     *
     * @param OrderFillEvaluator|null $fillEvaluator Evaluator used to compute order fill details; if null a default evaluator is constructed.
     */
    public function __construct(?OrderFillEvaluator $fillEvaluator = null)
    {
        $this->fillEvaluator = $fillEvaluator ?? new OrderFillEvaluator();
    }

    /**
     * Builds a weighted directed graph representing the provided orders as edges between currencies.
     *
     * @param iterable<Order> $orders Collection of Order objects; elements that are not Order instances are ignored.
     * @return Graph Associative array keyed by currency code where each node contains 'currency' and an 'edges' list.
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

            $this->initializeNode($graph, $fromCurrency);
            $this->initializeNode($graph, $toCurrency);

            $graph[$fromCurrency]['edges'][] = $this->createEdge($order, $fromCurrency, $toCurrency);
        }

        return $graph;
    }

    /**
     * Ensure the graph contains a node for the given currency, creating one with an empty
     * 'edges' list if it does not already exist.
     *
     * @param Graph $graph The graph to mutate (passed by reference).
     * @param string $currency The currency code for the node.
     *
     * @psalm-param Graph $graph
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
     * Builds a graph edge representation for the given order connecting two currency nodes.
     *
     * @param Order $order The order used to derive capacities, rate, and segment data.
     * @param string $fromCurrency Currency code for the source node of the edge.
     * @param string $toCurrency Currency code for the destination node of the edge.
     *
     * @return array GraphEdge associative array containing:
     *               - 'from' => string Currency code for the source node.
     *               - 'to' => string Currency code for the destination node.
     *               - 'orderSide' => mixed The order side value.
     *               - 'order' => Order The original order object.
     *               - 'rate' => mixed The order's effective rate.
     *               - 'baseCapacity' => array{'min': Money,'max': Money} Net base capacity bounds.
     *               - 'quoteCapacity' => array{'min': Money,'max': Money} Quote capacity bounds.
     *               - 'grossBaseCapacity' => array{'min': Money,'max': Money} Gross base capacity bounds.
     *               - 'segments' => list<array> Segment definitions describing mandatory and optional fill portions.
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
     * Builds fee-bearing segments that describe mandatory and optional fill ranges for an order.
     *
     * If `$hasFees` is false, no segments are produced. When fees exist, the method will:
     * - add a mandatory segment equal to the minimum base/quote/grossBase fills if `minBase` is greater than zero;
     * - add a non-mandatory remainder segment covering the difference between max and min fills when a remainder exists;
     * - if no segments were produced by the above rules, add a single non-mandatory segment with zero minima and maxima spanning the provided ranges.
     *
     * @param Money $minBase Minimum net base fill required.
     * @param Money $maxBase Maximum net base fill available.
     * @param Money $minQuote Minimum net quote fill corresponding to `$minBase`.
     * @param Money $maxQuote Maximum net quote fill corresponding to `$maxBase`.
     * @param Money $minGrossBase Minimum gross base fill required (before fees).
     * @param Money $maxGrossBase Maximum gross base fill available (before fees).
     * @param bool  $hasFees Whether the order can incur fees; when false, the result is an empty list.
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
     * Return a zero-valued Money for the given currency and scale, caching the instance per GraphBuilder.
     *
     * @param string $currency Currency code for the zero Money.
     * @param int $scale Decimal scale for the Money.
     * @return Money A Money instance representing zero for the specified currency and scale.
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