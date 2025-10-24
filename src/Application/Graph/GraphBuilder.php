<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Graph;

use SomeWork\P2PPathFinder\Application\Support\OrderFillEvaluator;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

use function array_keys;
use function sort;

/**
 * Converts a collection of domain orders into a weighted directed graph representation.
 */
final class GraphBuilder
{
    private OrderFillEvaluator $fillEvaluator;
    /**
     * @var array<string, Money>
     */
    private array $zeroMoneyCache = [];

    public function __construct(?OrderFillEvaluator $fillEvaluator = null)
    {
        $this->fillEvaluator = $fillEvaluator ?? new OrderFillEvaluator();
    }

    /**
     * @param iterable<Order> $orders
     */
    public function build(iterable $orders): Graph
    {
        /** @var array<string, list<GraphEdge>> $edges */
        $edges = [];
        /** @var array<string, true> $currencies */
        $currencies = [];

        foreach ($orders as $order) {
            if (!$order instanceof Order) {
                continue;
            }

            $pair = $order->assetPair();

            [$fromCurrency, $toCurrency] = match ($order->side()) {
                OrderSide::BUY => [$pair->base(), $pair->quote()],
                OrderSide::SELL => [$pair->quote(), $pair->base()],
            };

            $currencies[$fromCurrency] = true;
            $currencies[$toCurrency] = true;

            $edges[$fromCurrency][] = $this->createEdge($order, $fromCurrency, $toCurrency);
        }

        $nodes = [];
        $currencyList = array_keys($currencies);
        sort($currencyList);

        foreach ($currencyList as $currency) {
            $nodes[] = new GraphNode(
                $currency,
                GraphEdgeCollection::fromArray($edges[$currency] ?? []),
            );
        }

        return new Graph(GraphNodeCollection::fromArray($nodes));
    }

    private function createEdge(Order $order, string $fromCurrency, string $toCurrency): GraphEdge
    {
        $bounds = $order->bounds();

        $minBase = $bounds->min();
        $maxBase = $bounds->max();

        $minFill = $this->fillEvaluator->evaluate($order, $minBase);
        $maxFill = $this->fillEvaluator->evaluate($order, $maxBase);

        $minFees = $minFill['fees'];
        $maxFees = $maxFill['fees'];
        $hasFees = !$minFees->isZero() || !$maxFees->isZero();

        return new GraphEdge(
            $fromCurrency,
            $toCurrency,
            $order->side(),
            $order,
            $order->effectiveRate(),
            new EdgeCapacity($minFill['netBase'], $maxFill['netBase']),
            new EdgeCapacity($minFill['quote'], $maxFill['quote']),
            new EdgeCapacity($minFill['grossBase'], $maxFill['grossBase']),
            $this->buildSegments(
                $minFill['netBase'],
                $maxFill['netBase'],
                $minFill['quote'],
                $maxFill['quote'],
                $minFill['grossBase'],
                $maxFill['grossBase'],
                $hasFees,
            ),
        );
    }

    /**
     * @return list<EdgeSegment>
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
            $segments[] = new EdgeSegment(
                true,
                new EdgeCapacity($minBase, $minBase),
                new EdgeCapacity($minQuote, $minQuote),
                new EdgeCapacity($minGrossBase, $minGrossBase),
            );
        }

        $baseRemainder = $maxBase->subtract($minBase);
        if (!$baseRemainder->isZero()) {
            $quoteRemainder = $maxQuote->subtract($minQuote);
            $grossBaseRemainder = $maxGrossBase->subtract($minGrossBase);

            $segments[] = new EdgeSegment(
                false,
                new EdgeCapacity(
                    $this->zeroMoney($baseRemainder->currency(), $baseRemainder->scale()),
                    $baseRemainder,
                ),
                new EdgeCapacity(
                    $this->zeroMoney($quoteRemainder->currency(), $quoteRemainder->scale()),
                    $quoteRemainder,
                ),
                new EdgeCapacity(
                    $this->zeroMoney($grossBaseRemainder->currency(), $grossBaseRemainder->scale()),
                    $grossBaseRemainder,
                ),
            );
        }

        if ([] === $segments) {
            $segments[] = new EdgeSegment(
                false,
                new EdgeCapacity(
                    $this->zeroMoney($minBase->currency(), $minBase->scale()),
                    $this->zeroMoney($maxBase->currency(), $maxBase->scale()),
                ),
                new EdgeCapacity(
                    $this->zeroMoney($minQuote->currency(), $minQuote->scale()),
                    $this->zeroMoney($maxQuote->currency(), $maxQuote->scale()),
                ),
                new EdgeCapacity(
                    $this->zeroMoney($minGrossBase->currency(), $minGrossBase->scale()),
                    $this->zeroMoney($maxGrossBase->currency(), $maxGrossBase->scale()),
                ),
            );
        }

        return $segments;
    }

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
