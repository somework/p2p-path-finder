<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Service;

use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\EdgeCapacity;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\EdgeSegment;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\Graph;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\GraphEdge;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\GraphEdgeCollection;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\GraphNode;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\GraphNodeCollection;
use SomeWork\P2PPathFinder\Application\PathSearch\Support\OrderFillEvaluator;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

use function array_keys;
use function sort;

/**
 * Converts a collection of domain orders into a weighted directed graph representation.
 *
 * @see Graph For the resulting graph structure
 * @see ExecutionPlanService For orchestration
 *
 * @api
 */
final class GraphBuilder
{
    private readonly OrderFillEvaluator $fillEvaluator;
    /**
     * @var array<string, Money>
     */
    private static array $zeroMoneyCache = [];

    public function __construct()
    {
        $this->fillEvaluator = new OrderFillEvaluator();
    }

    /**
     * @param iterable<Order> $orders
     *
     * @throws InvalidInput|PrecisionViolation when order processing fails or arithmetic operations exceed precision limits
     */
    public function build(iterable $orders): Graph
    {
        /** @var array<string, array<GraphEdge>> $edges */
        $edges = [];
        /** @var array<string, true> $currencies */
        $currencies = [];

        foreach ($orders as $order) {
            /* @phpstan-ignore-next-line instanceof.alwaysTrue */
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
                    self::zeroMoney($baseRemainder->currency(), $baseRemainder->scale()),
                    $baseRemainder,
                ),
                new EdgeCapacity(
                    self::zeroMoney($quoteRemainder->currency(), $quoteRemainder->scale()),
                    $quoteRemainder,
                ),
                new EdgeCapacity(
                    self::zeroMoney($grossBaseRemainder->currency(), $grossBaseRemainder->scale()),
                    $grossBaseRemainder,
                ),
            );
        }

        if ([] === $segments) {
            $segments[] = new EdgeSegment(
                false,
                new EdgeCapacity(
                    self::zeroMoney($minBase->currency(), $minBase->scale()),
                    self::zeroMoney($maxBase->currency(), $maxBase->scale()),
                ),
                new EdgeCapacity(
                    self::zeroMoney($minQuote->currency(), $minQuote->scale()),
                    self::zeroMoney($maxQuote->currency(), $maxQuote->scale()),
                ),
                new EdgeCapacity(
                    self::zeroMoney($minGrossBase->currency(), $minGrossBase->scale()),
                    self::zeroMoney($maxGrossBase->currency(), $maxGrossBase->scale()),
                ),
            );
        }

        return $segments;
    }

    private static function zeroMoney(string $currency, int $scale): Money
    {
        $key = $currency.':'.$scale;

        if (!isset(self::$zeroMoneyCache[$key])) {
            self::$zeroMoneyCache[$key] = Money::zero($currency, $scale);
        }

        return self::$zeroMoneyCache[$key];
    }
}
