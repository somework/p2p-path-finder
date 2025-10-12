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

/**
 * High level facade orchestrating order filtering, graph building and path search.
 */
final class PathFinderService
{
    private const COST_SCALE = 18;

    public function __construct(private readonly GraphBuilder $graphBuilder)
    {
    }

    /**
     * Searches for the best conversion path from the configured spend asset to the target asset.
     */
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

        $materializedResult = null;
        $materializedCost = null;
        $rawPath = $pathFinder->findBestPath(
            $graph,
            $sourceCurrency,
            $targetCurrency,
            $config->minimumSpendAmount(),
            function (array $candidate) use (&$materializedResult, &$materializedCost, $config, $sourceCurrency, $targetCurrency) {
                if ($candidate['hops'] < $config->minimumHops() || $candidate['hops'] > $config->maximumHops()) {
                    return false;
                }

                if ([] === $candidate['edges']) {
                    return false;
                }

                $firstEdge = $candidate['edges'][0];
                if ($firstEdge['from'] !== $sourceCurrency) {
                    return false;
                }

                $initialSpend = $this->determineInitialSpendAmount($config, $firstEdge);
                if (null === $initialSpend) {
                    return false;
                }

                $result = $this->materializePath(
                    $candidate['edges'],
                    $config->spendAmount(),
                    $initialSpend,
                    $targetCurrency,
                    $config,
                );

                if (null === $result) {
                    return false;
                }

                if (null === $materializedCost || -1 === BcMath::comp($candidate['cost'], $materializedCost, self::COST_SCALE)) {
                    $materializedCost = $candidate['cost'];
                    $materializedResult = $result;
                }

                return true;
            }
        );

        if (null === $rawPath || null === $materializedResult) {
            return null;
        }

        return $materializedResult;
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
        $feeBreakdown = [];

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

                $rawQuote = $order->calculateQuoteAmount($spent);
                $fee = $this->resolveFee($order, $orderSide, $spent, $rawQuote);
                $received = $rawQuote;
                if (!$fee->isZero()) {
                    $received = $rawQuote->add($fee);
                }
            } else {
                $targetEffectiveQuote = $current->withScale(max($current->scale(), $order->bounds()->min()->scale()));
                $resolved = $this->resolveSellLegAmounts($order, $targetEffectiveQuote);

                if (null === $resolved) {
                    return null;
                }

                [$spent, $received, $fee] = $resolved;
            }

            $this->accumulateFee($feeBreakdown, $fee);

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
            $residual,
            $legs,
            $feeBreakdown,
        );
    }

    /**
     * @param array<string, Money> $feeBreakdown
     */
    private function accumulateFee(array &$feeBreakdown, Money $fee): void
    {
        if ($fee->isZero()) {
            return;
        }

        $currency = $fee->currency();

        if (isset($feeBreakdown[$currency])) {
            $feeBreakdown[$currency] = $feeBreakdown[$currency]->add($fee);

            return;
        }

        $feeBreakdown[$currency] = $fee;
    }

    private function resolveFee(Order $order, OrderSide $side, Money $baseAmount, Money $rawQuote): Money
    {
        $policy = $order->feePolicy();
        if (null === $policy) {
            return Money::zero($rawQuote->currency(), $rawQuote->scale());
        }

        return $policy->calculate($side, $baseAmount, $rawQuote);
    }

    /**
     * @return array{0: Money, 1: Money, 2: Money}|null
     */
    private function resolveSellLegAmounts(Order $order, Money $targetEffectiveQuote): ?array
    {
        $bounds = $order->bounds();

        if (null === $order->feePolicy()) {
            $rate = $order->effectiveRate()->invert();
            $scale = max(
                $targetEffectiveQuote->scale(),
                $bounds->min()->scale(),
                $rate->scale(),
            );

            $spent = $targetEffectiveQuote->withScale($scale);
            $received = $rate->convert($spent, $scale);

            if (!$bounds->contains($received->withScale(max($received->scale(), $bounds->min()->scale())))) {
                return null;
            }

            return [
                $spent,
                $received,
                Money::zero($spent->currency(), $spent->scale()),
            ];
        }

        $rate = $order->effectiveRate()->invert();
        $scale = max(
            $targetEffectiveQuote->scale(),
            $bounds->min()->scale(),
            $bounds->max()->scale(),
            $rate->scale(),
        );

        $effectiveQuote = $targetEffectiveQuote->withScale($scale);
        $baseAmount = $rate->convert($effectiveQuote, $scale);
        $baseAmount = $this->alignBaseScale($bounds->min()->scale(), $bounds->max()->scale(), $baseAmount);

        [$rawQuote, $fee, $effectiveQuoteAmount] = $this->evaluateSellQuote($order, $baseAmount);

        $effectiveQuoteAmount = $effectiveQuoteAmount->withScale(max($effectiveQuoteAmount->scale(), $effectiveQuote->scale()));
        $effectiveQuote = $effectiveQuote->withScale($effectiveQuoteAmount->scale());

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            if (0 === $effectiveQuoteAmount->compare($effectiveQuote)) {
                break;
            }

            if ($effectiveQuoteAmount->isZero()) {
                $effectiveQuoteAmount = $effectiveQuote;

                break;
            }

            $ratioScale = max($effectiveQuoteAmount->scale(), $effectiveQuote->scale(), 12);
            $targetAmount = $effectiveQuote->withScale($ratioScale)->amount();
            $currentAmount = $effectiveQuoteAmount->withScale($ratioScale)->amount();

            if (0 === BcMath::comp($currentAmount, '0', $ratioScale)) {
                return null;
            }

            $ratio = BcMath::div($targetAmount, $currentAmount, $ratioScale + 6);
            $baseAmount = $baseAmount->multiply($ratio, max($baseAmount->scale(), $ratioScale));
            $baseAmount = $this->alignBaseScale($bounds->min()->scale(), $bounds->max()->scale(), $baseAmount);

            [$rawQuote, $fee, $effectiveQuoteAmount] = $this->evaluateSellQuote($order, $baseAmount);
            $effectiveQuoteAmount = $effectiveQuoteAmount->withScale(max($effectiveQuoteAmount->scale(), $effectiveQuote->scale()));
            $effectiveQuote = $effectiveQuote->withScale($effectiveQuoteAmount->scale());
        }

        $effectiveQuoteAmount = $targetEffectiveQuote->withScale(max($targetEffectiveQuote->scale(), $bounds->min()->scale()));
        $fee = $fee->withScale($effectiveQuoteAmount->scale());

        if (!$bounds->contains($baseAmount->withScale(max($baseAmount->scale(), $bounds->min()->scale())))) {
            return null;
        }

        $baseAmount = $baseAmount->withScale($bounds->min()->scale());

        return [
            $effectiveQuoteAmount,
            $baseAmount,
            $fee,
        ];
    }

    private function alignBaseScale(int $minScale, int $maxScale, Money $baseAmount): Money
    {
        $scale = max($baseAmount->scale(), $minScale, $maxScale);

        return $baseAmount->withScale($scale);
    }

    /**
     * @return array{0: Money, 1: Money, 2: Money}
     */
    private function evaluateSellQuote(Order $order, Money $baseAmount): array
    {
        $rawQuote = $order->calculateQuoteAmount($baseAmount);
        $fee = $this->resolveFee($order, OrderSide::SELL, $baseAmount, $rawQuote);
        $effectiveQuote = $rawQuote;

        if (!$fee->isZero()) {
            $effectiveQuote = $rawQuote->subtract($fee);
        }

        return [$rawQuote, $fee, $effectiveQuote];
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
