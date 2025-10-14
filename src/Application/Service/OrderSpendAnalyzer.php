<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Service;

use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Application\Support\OrderFillEvaluator;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

use function max;

/**
 * Encapsulates filtering orders and determining spend bounds.
 */
final class OrderSpendAnalyzer
{
    private readonly OrderFillEvaluator $fillEvaluator;
    private readonly LegMaterializer $legMaterializer;

    public function __construct(?OrderFillEvaluator $fillEvaluator = null, ?LegMaterializer $legMaterializer = null)
    {
        $this->fillEvaluator = $fillEvaluator ?? new OrderFillEvaluator();
        $this->legMaterializer = $legMaterializer ?? new LegMaterializer($this->fillEvaluator);
    }

    /**
     * @return list<Order>
     */
    public function filterOrders(OrderBook $orderBook, PathSearchConfig $config): array
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
     * @param array{from: string, to: string, order: Order, orderSide: OrderSide} $edge
     *
     * @return array{net: Money, gross: Money, grossCeiling: Money}|null
     */
    public function determineInitialSpendAmount(PathSearchConfig $config, array $edge): ?array
    {
        $desired = $config->spendAmount();
        $configMin = $config->minimumSpendAmount();
        $configMax = $config->maximumSpendAmount();
        $order = $edge['order'];

        [$orderMinGross, $orderMaxGross] = $this->determineOrderSpendBounds($order);

        if (OrderSide::SELL === $order->side()) {
            $bounds = $order->bounds();
            $minEvaluation = $this->legMaterializer->evaluateSellQuote($order, $bounds->min());
            $maxEvaluation = $this->legMaterializer->evaluateSellQuote($order, $bounds->max());

            $orderEffectiveMin = $minEvaluation['effectiveQuote'];
            $orderEffectiveMax = $maxEvaluation['effectiveQuote'];

            $scale = max(
                $desired->scale(),
                $configMin->scale(),
                $configMax->scale(),
                $orderEffectiveMin->scale(),
                $orderEffectiveMax->scale(),
            );

            $desired = $desired->withScale($scale);
            $configMin = $configMin->withScale($scale);
            $configMax = $configMax->withScale($scale);
            $orderEffectiveMin = $orderEffectiveMin->withScale($scale);
            $orderEffectiveMax = $orderEffectiveMax->withScale($scale);

            $lowerBound = $orderEffectiveMin->greaterThan($configMin) ? $orderEffectiveMin : $configMin;
            $upperBound = $orderEffectiveMax->lessThan($configMax) ? $orderEffectiveMax : $configMax;

            if ($lowerBound->greaterThan($upperBound)) {
                return null;
            }

            if ($desired->lessThan($lowerBound)) {
                $candidate = $lowerBound;
            } elseif ($desired->greaterThan($upperBound)) {
                $candidate = $upperBound;
            } else {
                $candidate = $desired;
            }

            $resolved = $this->legMaterializer->resolveSellLegAmounts($order, $candidate);
            if (null === $resolved) {
                return null;
            }

            [$grossSpent] = $resolved;
            $grossScale = max(
                $grossSpent->scale(),
                $configMin->scale(),
                $configMax->scale(),
                $orderMinGross->scale(),
                $orderMaxGross->scale(),
            );

            $grossSpent = $grossSpent->withScale($grossScale);
            $minGross = $orderMinGross->withScale($grossScale);
            $maxGross = $orderMaxGross->withScale($grossScale);
            $configMin = $configMin->withScale($grossScale);
            $configMax = $configMax->withScale($grossScale);

            if ($grossSpent->lessThan($configMin) || $grossSpent->greaterThan($configMax)) {
                return null;
            }

            if ($grossSpent->lessThan($minGross) || $grossSpent->greaterThan($maxGross)) {
                return null;
            }

            return [
                'net' => $candidate,
                'gross' => $grossSpent,
                'grossCeiling' => $grossSpent,
            ];
        }

        $bounds = $order->bounds();
        $minNet = $bounds->min();
        $maxNet = $bounds->max();

        $grossScale = max(
            $orderMinGross->scale(),
            $orderMaxGross->scale(),
            $configMin->scale(),
            $configMax->scale(),
        );

        $minGross = $orderMinGross->withScale($grossScale);
        $maxGross = $orderMaxGross->withScale($grossScale);
        $configMinGross = $configMin->withScale($grossScale);
        $configMaxGross = $configMax->withScale($grossScale);

        $grossLower = $minGross->greaterThan($configMinGross) ? $minGross : $configMinGross;
        $grossUpper = $maxGross->lessThan($configMaxGross) ? $maxGross : $configMaxGross;

        if ($grossLower->greaterThan($grossUpper)) {
            return null;
        }

        $scale = max(
            $desired->scale(),
            $configMin->scale(),
            $configMax->scale(),
            $minNet->scale(),
            $maxNet->scale(),
        );

        $desiredNet = $desired->withScale($scale);
        $minNet = $minNet->withScale($scale);
        $maxNet = $maxNet->withScale($scale);

        if ($desiredNet->lessThan($minNet)) {
            $desiredNet = $minNet;
        } elseif ($desiredNet->greaterThan($maxNet)) {
            $desiredNet = $maxNet;
        }

        $desiredFill = $this->fillEvaluator->evaluate($order, $desiredNet);
        $desiredGross = $desiredFill['grossBase']->withScale($grossScale);
        $targetGross = $desiredGross->greaterThan($grossUpper) ? $grossUpper : $desiredGross;

        $resolved = $this->legMaterializer->resolveBuyFill($order, $desiredNet, $targetGross, $grossUpper);
        if (null === $resolved) {
            return null;
        }

        $gross = $resolved['gross']->withScale($grossScale);
        $net = $resolved['net']->withScale($scale);

        if ($gross->lessThan($grossLower)) {
            return null;
        }

        $grossCeiling = $grossUpper->withScale(max($grossUpper->scale(), $gross->scale()));

        return [
            'net' => $net,
            'gross' => $gross,
            'grossCeiling' => $grossCeiling,
        ];
    }

    /**
     * @return array{Money, Money}
     */
    private function determineOrderSpendBounds(Order $order): array
    {
        $bounds = $order->bounds();

        if (OrderSide::BUY === $order->side()) {
            $minFill = $this->fillEvaluator->evaluate($order, $bounds->min());
            $maxFill = $this->fillEvaluator->evaluate($order, $bounds->max());

            $minGross = $minFill['grossBase'];
            $maxGross = $maxFill['grossBase'];

            $scale = max($minGross->scale(), $maxGross->scale());

            return [
                $minGross->withScale($scale),
                $maxGross->withScale($scale),
            ];
        }

        $minEvaluation = $this->legMaterializer->evaluateSellQuote($order, $bounds->min());
        $maxEvaluation = $this->legMaterializer->evaluateSellQuote($order, $bounds->max());

        $minGross = $minEvaluation['grossQuote'];
        $maxGross = $maxEvaluation['grossQuote'];

        $scale = max($minGross->scale(), $maxGross->scale());

        return [
            $minGross->withScale($scale),
            $maxGross->withScale($scale),
        ];
    }

    private function determineSpendCurrency(Order $order): string
    {
        $pair = $order->assetPair();

        return match ($order->side()) {
            OrderSide::BUY => $pair->base(),
            OrderSide::SELL => $pair->quote(),
        };
    }
}
