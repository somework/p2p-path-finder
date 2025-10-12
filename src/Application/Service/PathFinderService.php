<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Service;

use InvalidArgumentException;
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Application\PathFinder\PathFinder;
use SomeWork\P2PPathFinder\Application\Result\PathLeg;
use SomeWork\P2PPathFinder\Application\Result\PathResult;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

use function strtoupper;

final class PathFinderService
{
    public function __construct(private readonly GraphBuilder $graphBuilder)
    {
    }

    public function findBestPath(OrderBook $orderBook, PathSearchConfig $config, string $targetAsset): ?PathResult
    {
        if ('' === $targetAsset) {
            throw new InvalidArgumentException('Target asset cannot be empty.');
        }

        $sourceCurrency = $config->spendAmount()->currency();
        $targetCurrency = strtoupper($targetAsset);

        $orders = $this->filterOrders($orderBook, $config);
        if ([] === $orders) {
            return null;
        }

        $graph = $this->graphBuilder->build($orders);
        if (!isset($graph[$sourceCurrency], $graph[$targetCurrency])) {
            return null;
        }

        $pathFinder = new PathFinder($config->maximumHops(), $config->pathFinderTolerance());
        $rawPath = $pathFinder->findBestPath($graph, $sourceCurrency, $targetCurrency);
        if (null === $rawPath) {
            return null;
        }

        if ($rawPath['hops'] < $config->minimumHops() || $rawPath['hops'] > $config->maximumHops()) {
            return null;
        }

        if ([] === $rawPath['edges']) {
            return null;
        }

        $firstEdge = $rawPath['edges'][0];
        if ($firstEdge['from'] !== $sourceCurrency) {
            return null;
        }

        $initialSpend = $this->determineInitialSpendAmount($config, $firstEdge);
        if (null === $initialSpend) {
            return null;
        }

        return $this->materializePath($rawPath['edges'], $config->spendAmount(), $initialSpend, $targetCurrency, $config);
    }

    /**
     * @return list<Order>
     */
    private function filterOrders(OrderBook $orderBook, PathSearchConfig $config): array
    {
        $sourceCurrency = $config->spendAmount()->currency();
        $minimum = $config->minimumSpendAmount();
        $maximum = $config->maximumSpendAmount();

        $orders = [];

        foreach ($orderBook as $order) {
            $spendCurrency = $this->determineSpendCurrency($order);
            if ($spendCurrency !== $sourceCurrency) {
                $orders[] = $order;

                continue;
            }

            [$orderMin, $orderMax] = $this->determineOrderSpendBounds($order);
            $scale = max($orderMin->scale(), $orderMax->scale(), $minimum->scale(), $maximum->scale());

            $orderMin = $orderMin->withScale($scale);
            $orderMax = $orderMax->withScale($scale);
            $minBound = $minimum->withScale($scale);
            $maxBound = $maximum->withScale($scale);

            if ($orderMax->lessThan($minBound) || $orderMin->greaterThan($maxBound)) {
                continue;
            }

            $orders[] = $order;
        }

        return $orders;
    }

    /**
     * @return array{Money, Money}
     */
    private function determineOrderSpendBounds(Order $order): array
    {
        $bounds = $order->bounds();

        if (OrderSide::BUY === $order->side()) {
            return [$bounds->min(), $bounds->max()];
        }

        $minQuote = $order->calculateEffectiveQuoteAmount($bounds->min());
        $maxQuote = $order->calculateEffectiveQuoteAmount($bounds->max());

        return [$minQuote, $maxQuote];
    }

    private function determineSpendCurrency(Order $order): string
    {
        $pair = $order->assetPair();

        return match ($order->side()) {
            OrderSide::BUY => $pair->base(),
            OrderSide::SELL => $pair->quote(),
        };
    }

    /**
     * @param array{from: string, to: string, order: Order, orderSide: OrderSide} $edge
     */
    private function determineInitialSpendAmount(PathSearchConfig $config, array $edge): ?Money
    {
        $desired = $config->spendAmount();
        $configMin = $config->minimumSpendAmount();
        $configMax = $config->maximumSpendAmount();
        $order = $edge['order'];

        [$orderMin, $orderMax] = $this->determineOrderSpendBounds($order);

        $scale = max(
            $desired->scale(),
            $configMin->scale(),
            $configMax->scale(),
            $orderMin->scale(),
            $orderMax->scale(),
        );

        $desired = $desired->withScale($scale);
        $orderMin = $orderMin->withScale($scale);
        $orderMax = $orderMax->withScale($scale);
        $configMin = $configMin->withScale($scale);
        $configMax = $configMax->withScale($scale);

        $lowerBound = $orderMin->greaterThan($configMin) ? $orderMin : $configMin;
        $upperBound = $orderMax->lessThan($configMax) ? $orderMax : $configMax;

        if ($lowerBound->greaterThan($upperBound)) {
            return null;
        }

        if ($desired->lessThan($lowerBound)) {
            return $lowerBound;
        }

        if ($desired->greaterThan($upperBound)) {
            return $upperBound;
        }

        return $desired;
    }

    /**
     * @param list<array{from: string, to: string, order: Order, orderSide: OrderSide}> $edges
     */
    private function materializePath(array $edges, Money $requestedSpend, Money $actualSpend, string $targetCurrency, PathSearchConfig $config): ?PathResult
    {
        $legs = [];
        $current = $actualSpend;
        $currentCurrency = $current->currency();
        $totalFees = Money::zero($requestedSpend->currency(), $requestedSpend->scale());

        foreach ($edges as $edge) {
            $order = $edge['order'];
            $orderSide = $edge['orderSide'];
            $from = $edge['from'];
            $to = $edge['to'];

            if ($from !== $currentCurrency) {
                return null;
            }

            if (OrderSide::BUY === $orderSide) {
                $spent = $current->withScale(max($current->scale(), $order->bounds()->min()->scale()));
                if (!$order->bounds()->contains($spent)) {
                    return null;
                }

                $received = $order->calculateEffectiveQuoteAmount($spent);
                $fee = Money::zero($spent->currency(), $spent->scale());
            } else {
                $spent = $current;
                $rate = $order->effectiveRate()->invert();
                $scale = max($spent->scale(), $order->bounds()->min()->scale(), $rate->scale());
                $spent = $spent->withScale($scale);
                $received = $rate->convert($spent, $scale);

                if (!$order->bounds()->contains($received->withScale(max($received->scale(), $order->bounds()->min()->scale())))) {
                    return null;
                }

                $fee = Money::zero($spent->currency(), $spent->scale());
            }

            $legs[] = new PathLeg($from, $to, $spent, $received, $fee);
            $current = $received;
            $currentCurrency = $current->currency();
        }

        if ($currentCurrency !== $targetCurrency) {
            return null;
        }

        $residual = $this->calculateResidualTolerance($requestedSpend, $actualSpend);

        $requestedComparable = $requestedSpend->withScale(max($requestedSpend->scale(), $actualSpend->scale()));
        $actualComparable = $actualSpend->withScale($requestedComparable->scale());

        if ($actualComparable->lessThan($requestedComparable) && $residual > $config->minimumTolerance()) {
            return null;
        }

        if ($actualComparable->greaterThan($requestedComparable) && $residual > $config->maximumTolerance()) {
            return null;
        }

        return new PathResult(
            $actualSpend,
            $current,
            $totalFees,
            $residual,
            $legs,
        );
    }

    private function calculateResidualTolerance(Money $desired, Money $actual): float
    {
        $scale = max($desired->scale(), $actual->scale(), 8);
        $desiredAmount = $desired->withScale($scale)->amount();

        if (0 === BcMath::comp($desiredAmount, '0', $scale)) {
            return 0.0;
        }

        $actualAmount = $actual->withScale($scale)->amount();
        $diff = BcMath::sub($actualAmount, $desiredAmount, $scale + 4);

        if ('-' === $diff[0]) {
            $diff = substr($diff, 1);
        }

        $ratio = BcMath::div($diff, $desiredAmount, $scale + 4);

        return (float) $ratio;
    }
}
