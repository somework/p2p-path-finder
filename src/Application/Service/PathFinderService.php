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
use SomeWork\P2PPathFinder\Application\Support\OrderFillEvaluator;
use SomeWork\P2PPathFinder\Domain\Order\FeeBreakdown;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

use function strtoupper;
use function substr;

/**
 * High level facade orchestrating order filtering, graph building and path search.
 */
final class PathFinderService
{
    private const COST_SCALE = 18;
    private const SELL_RESOLUTION_MAX_ITERATIONS = 16;
    private const SELL_RESOLUTION_RELATIVE_TOLERANCE = '0.000001';
    private const SELL_RESOLUTION_COMPARISON_SCALE = 18;
    private const SELL_RESOLUTION_RATIO_EXTRA_SCALE = 6;
    private const SELL_RESOLUTION_TOLERANCE_SCALE = 12;
    private const BUY_ADJUSTMENT_MAX_ITERATIONS = 12;
    private const RESIDUAL_TOLERANCE_EPSILON = 0.000001;

    private readonly OrderFillEvaluator $fillEvaluator;

    public function __construct(private readonly GraphBuilder $graphBuilder, ?OrderFillEvaluator $fillEvaluator = null)
    {
        $this->fillEvaluator = $fillEvaluator ?? new OrderFillEvaluator();
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
            [
                'min' => $config->minimumSpendAmount(),
                'max' => $config->maximumSpendAmount(),
                'desired' => $config->spendAmount(),
            ],
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

                $initialSeed = $this->determineInitialSpendAmount($config, $firstEdge);
                if (null === $initialSeed) {
                    return false;
                }

                $result = $this->materializePath(
                    $candidate['edges'],
                    $config->spendAmount(),
                    $initialSeed,
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

        $minEvaluation = $this->evaluateSellQuote($order, $bounds->min());
        $maxEvaluation = $this->evaluateSellQuote($order, $bounds->max());

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

    /**
     * @param array{from: string, to: string, order: Order, orderSide: OrderSide} $edge
     *
     * @return array{net: Money, gross: Money, grossCeiling: Money}|null
     */
    private function determineInitialSpendAmount(PathSearchConfig $config, array $edge): ?array
    {
        $desired = $config->spendAmount();
        $configMin = $config->minimumSpendAmount();
        $configMax = $config->maximumSpendAmount();
        $order = $edge['order'];

        [$orderMinGross, $orderMaxGross] = $this->determineOrderSpendBounds($order);

        if (OrderSide::SELL === $order->side()) {
            $bounds = $order->bounds();
            $minEvaluation = $this->evaluateSellQuote($order, $bounds->min());
            $maxEvaluation = $this->evaluateSellQuote($order, $bounds->max());

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

            $resolved = $this->resolveSellLegAmounts($order, $candidate);
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

        $resolved = $this->resolveBuyFill($order, $desiredNet, $targetGross, $grossUpper);
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
     * @param list<array{from: string, to: string, order: Order, orderSide: OrderSide}> $edges
     * @param array{net: Money, gross: Money, grossCeiling: Money}                      $initialSeed
     */
    private function materializePath(array $edges, Money $requestedSpend, array $initialSeed, string $targetCurrency, PathSearchConfig $config): ?PathResult
    {
        $legs = [];
        $current = $initialSeed['net'];
        $currentCurrency = $current->currency();
        $feeBreakdown = [];

        $initialGrossSeed = $initialSeed['gross'];
        $initialGrossCeiling = $initialSeed['grossCeiling'];

        $budgetScale = max($initialGrossSeed->scale(), $initialGrossCeiling->scale());
        $initialGrossSeed = $initialGrossSeed->withScale($budgetScale);
        $remainingGrossBudget = $initialGrossCeiling->withScale($budgetScale);

        $grossSpentScale = max($requestedSpend->scale(), $budgetScale);
        $grossSpent = Money::zero($initialGrossSeed->currency(), $grossSpentScale);
        $toleranceSpent = Money::zero($initialGrossSeed->currency(), $grossSpentScale);

        $applyTolerance = true;

        foreach ($edges as $edge) {
            $order = $edge['order'];
            $orderSide = $edge['orderSide'];
            $from = $edge['from'];
            $to = $edge['to'];

            if ($from !== $currentCurrency) {
                return null;
            }

            if (OrderSide::BUY === $orderSide) {
                $grossSeedForLeg = $applyTolerance ? $initialGrossSeed : $current;
                $grossCeilingForLeg = $applyTolerance ? $remainingGrossBudget : $grossSeedForLeg;

                $resolved = $this->resolveBuyLegAmounts($order, $current, $grossSeedForLeg, $grossCeilingForLeg);

                if (null === $resolved) {
                    return null;
                }

                [$spent, $received, $fees] = $resolved;

                if ($applyTolerance && $spent->currency() === $remainingGrossBudget->currency()) {
                    $remainingGrossBudget = $this->reduceBudget($remainingGrossBudget, $spent);
                }

                $applyTolerance = false;
            } else {
                $targetEffectiveQuote = $current->withScale(max($current->scale(), $order->bounds()->min()->scale()));
                $availableBudget = $current;

                if ($applyTolerance && $current->currency() === $remainingGrossBudget->currency()) {
                    $budgetScale = max($current->scale(), $remainingGrossBudget->scale());
                    $availableBudget = $remainingGrossBudget->withScale($budgetScale);
                }

                $resolved = $this->resolveSellLegAmounts($order, $targetEffectiveQuote, $availableBudget);

                if (null === $resolved) {
                    return null;
                }

                [$spent, $received, $fees] = $resolved;

                if ($applyTolerance && $spent->currency() === $remainingGrossBudget->currency()) {
                    $remainingGrossBudget = $this->reduceBudget($remainingGrossBudget, $spent);
                }

                $applyTolerance = false;
            }

            $legFees = $this->convertFeesToMap($fees);
            $this->accumulateFeeBreakdown($feeBreakdown, $legFees);

            if ($spent->currency() === $grossSpent->currency()) {
                $grossSpent = $grossSpent->add($spent, $grossSpentScale);
            }

            if ($spent->currency() === $toleranceSpent->currency()) {
                $toleranceSpent = $toleranceSpent->add($spent, $grossSpentScale);
            }

            $legs[] = new PathLeg($from, $to, $spent, $received, $legFees);
            $current = $received;
            $currentCurrency = $current->currency();
        }

        if ($currentCurrency !== $targetCurrency) {
            return null;
        }

        $residual = $this->calculateResidualTolerance($requestedSpend, $toleranceSpent);

        $requestedComparable = $requestedSpend->withScale(max($requestedSpend->scale(), $toleranceSpent->scale()));
        $actualComparable = $toleranceSpent->withScale($requestedComparable->scale());

        if (
            $actualComparable->lessThan($requestedComparable)
            && $residual - $config->minimumTolerance() > self::RESIDUAL_TOLERANCE_EPSILON
        ) {
            return null;
        }

        if (
            $actualComparable->greaterThan($requestedComparable)
            && $residual - $config->maximumTolerance() > self::RESIDUAL_TOLERANCE_EPSILON
        ) {
            return null;
        }

        return new PathResult(
            $grossSpent,
            $current,
            $residual,
            $legs,
            $feeBreakdown,
        );
    }

    /**
     * @param array<string, Money> $feeBreakdown
     * @param array<string, Money> $legFees
     */
    private function accumulateFeeBreakdown(array &$feeBreakdown, array $legFees): void
    {
        foreach ($legFees as $fee) {
            $this->accumulateFee($feeBreakdown, $fee);
        }
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

    private function resolveFeeBreakdown(Order $order, OrderSide $side, Money $baseAmount, Money $rawQuote): FeeBreakdown
    {
        $policy = $order->feePolicy();
        if (null === $policy) {
            return FeeBreakdown::none();
        }

        return $policy->calculate($side, $baseAmount, $rawQuote);
    }

    /**
     * @return array<string, Money>
     */
    private function convertFeesToMap(FeeBreakdown $fees): array
    {
        $normalized = [];

        $baseFee = $fees->baseFee();
        if (null !== $baseFee && !$baseFee->isZero()) {
            $this->accumulateFee($normalized, $baseFee);
        }

        $quoteFee = $fees->quoteFee();
        if (null !== $quoteFee && !$quoteFee->isZero()) {
            $this->accumulateFee($normalized, $quoteFee);
        }

        ksort($normalized);

        return $normalized;
    }

    private function reduceBudget(Money $budget, Money $spent): Money
    {
        if ($budget->currency() !== $spent->currency() || $spent->isZero()) {
            return $budget;
        }

        $scale = max($budget->scale(), $spent->scale());
        $remaining = $budget->subtract($spent, $scale);
        $zero = Money::zero($budget->currency(), $remaining->scale());

        if ($remaining->lessThan($zero)) {
            return $zero;
        }

        return $remaining;
    }

    /**
     * @return array{0: Money, 1: Money, 2: FeeBreakdown}|null
     */
    private function resolveBuyLegAmounts(Order $order, Money $netSeed, Money $grossSeed, Money $grossCeiling): ?array
    {
        $resolved = $this->resolveBuyFill($order, $netSeed, $grossSeed, $grossCeiling);

        if (null === $resolved) {
            return null;
        }

        return [
            $resolved['gross'],
            $resolved['quote'],
            $resolved['fees'],
        ];
    }

    /**
     * @return array{gross: Money, quote: Money, fees: FeeBreakdown, net: Money}|null
     */
    private function resolveBuyFill(Order $order, Money $netSeed, Money $grossSeed, Money $grossCeiling): ?array
    {
        $bounds = $order->bounds();
        $minNet = $bounds->min();
        $maxNet = $bounds->max();
        $boundsScale = max($minNet->scale(), $maxNet->scale());

        $netCandidate = $bounds->clamp($netSeed->withScale(max($netSeed->scale(), $boundsScale)));
        $grossScale = max($grossSeed->scale(), $grossCeiling->scale(), $boundsScale);
        $grossSeed = $grossSeed->withScale($grossScale);
        $grossCeiling = $grossCeiling->withScale(max($grossCeiling->scale(), $grossSeed->scale()));

        if (!$minNet->isZero()) {
            $minFill = $this->fillEvaluator->evaluate($order, $minNet);
            $minGross = $minFill['grossBase']->withScale(max($minFill['grossBase']->scale(), $grossCeiling->scale()));
            $budgetComparable = $grossCeiling->withScale($minGross->scale());

            if ($minGross->greaterThan($budgetComparable)) {
                return null;
            }
        }

        for ($attempt = 0; $attempt < self::BUY_ADJUSTMENT_MAX_ITERATIONS; ++$attempt) {
            $netCandidate = $netCandidate->withScale($boundsScale);
            $fill = $this->fillEvaluator->evaluate($order, $netCandidate);
            $grossBase = $fill['grossBase'];
            $comparisonScale = max($grossBase->scale(), $grossCeiling->scale());
            $grossComparable = $grossBase->withScale($comparisonScale);
            $ceilingComparable = $grossCeiling->withScale($comparisonScale);

            if (!$grossComparable->greaterThan($ceilingComparable)) {
                $grossResult = $grossBase->withScale(max($grossBase->scale(), $grossSeed->scale(), $grossCeiling->scale()));
                $quote = $fill['quote'];

                return [
                    'gross' => $grossResult,
                    'quote' => $quote,
                    'fees' => $fill['fees'],
                    'net' => $netCandidate->withScale($boundsScale),
                ];
            }

            $ratioScale = max($comparisonScale, 12);
            $ceilingAmount = $ceilingComparable->withScale($ratioScale)->amount();
            $grossAmount = $grossComparable->withScale($ratioScale)->amount();

            if (0 === BcMath::comp($grossAmount, '0', $ratioScale)) {
                return null;
            }

            $ratio = BcMath::div($ceilingAmount, $grossAmount, $ratioScale + 4);

            if (0 === BcMath::comp($ratio, '0', $ratioScale + 4)) {
                return null;
            }

            $nextNet = $netCandidate->multiply($ratio, max($netCandidate->scale(), $ratioScale));
            $nextNet = $bounds->clamp($nextNet);

            if ($nextNet->equals($netCandidate)) {
                return null;
            }

            $netCandidate = $nextNet;
        }

        return null;
    }

    /**
     * @return array{0: Money, 1: Money, 2: FeeBreakdown}|null
     */
    private function resolveSellLegAmounts(Order $order, Money $targetEffectiveQuote, ?Money $availableQuoteBudget = null): ?array
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

            if (null !== $availableQuoteBudget && $spent->greaterThan($availableQuoteBudget)) {
                return null;
            }

            return [
                $spent,
                $received,
                FeeBreakdown::none(),
            ];
        }

        $rate = $order->effectiveRate()->invert();
        $scale = max(
            $targetEffectiveQuote->scale(),
            $bounds->min()->scale(),
            $bounds->max()->scale(),
            $rate->scale(),
        );

        $originalTarget = $targetEffectiveQuote;
        $currentTarget = $targetEffectiveQuote->withScale($scale);
        $baseAmount = $rate->convert($currentTarget, $scale);
        $baseAmount = $this->alignBaseScale($bounds->min()->scale(), $bounds->max()->scale(), $baseAmount);

        $converged = false;

        for ($attempt = 0; $attempt < self::SELL_RESOLUTION_MAX_ITERATIONS; ++$attempt) {
            $evaluation = $this->evaluateSellQuote($order, $baseAmount);
            $fees = $evaluation['fees'];
            $effectiveQuoteAmount = $evaluation['effectiveQuote'];
            $grossQuoteAmount = $evaluation['grossQuote'];

            if (null !== $availableQuoteBudget) {
                $grossComparisonScale = max(
                    $grossQuoteAmount->scale(),
                    $availableQuoteBudget->scale(),
                    self::SELL_RESOLUTION_COMPARISON_SCALE,
                );

                $grossComparable = $grossQuoteAmount->withScale($grossComparisonScale);
                $availableComparable = $availableQuoteBudget->withScale($grossComparisonScale);

                if ($grossComparable->greaterThan($availableComparable)) {
                    if ($this->isWithinSellResolutionTolerance($availableComparable, $grossComparable)) {
                        $grossComparable = $availableComparable;
                    } else {
                        $ratio = $this->calculateSellAdjustmentRatio($availableComparable, $grossComparable, $grossComparisonScale);
                        if (null === $ratio) {
                            return null;
                        }

                        $previousBase = $baseAmount;
                        $baseAmount = $baseAmount->multiply($ratio, max($baseAmount->scale(), $grossComparisonScale));
                        $baseAmount = $this->alignBaseScale($bounds->min()->scale(), $bounds->max()->scale(), $baseAmount);

                        if ($baseAmount->equals($previousBase)) {
                            return null;
                        }

                        $currentTarget = $effectiveQuoteAmount->multiply($ratio, max($effectiveQuoteAmount->scale(), $grossComparisonScale));
                        $currentTarget = $currentTarget->withScale(max($currentTarget->scale(), $scale, $grossComparisonScale));

                        continue;
                    }
                }
            }

            $comparisonScale = max(
                $effectiveQuoteAmount->scale(),
                $currentTarget->scale(),
                self::SELL_RESOLUTION_COMPARISON_SCALE,
            );

            $effectiveComparable = $effectiveQuoteAmount->withScale($comparisonScale);
            $targetComparable = $currentTarget->withScale($comparisonScale);

            if ($this->isWithinSellResolutionTolerance($targetComparable, $effectiveComparable)) {
                $currentTarget = $targetComparable;
                $converged = true;

                break;
            }

            $ratio = $this->calculateSellAdjustmentRatio($targetComparable, $effectiveComparable, $comparisonScale);
            if (null === $ratio) {
                return null;
            }

            $baseAmount = $baseAmount->multiply($ratio, max($baseAmount->scale(), $comparisonScale));
            $baseAmount = $this->alignBaseScale($bounds->min()->scale(), $bounds->max()->scale(), $baseAmount);
        }

        if (!$converged) {
            return null;
        }

        if (!$bounds->contains($baseAmount->withScale(max($baseAmount->scale(), $bounds->min()->scale())))) {
            return null;
        }

        $baseAmount = $baseAmount->withScale($bounds->min()->scale());
        $evaluation = $this->evaluateSellQuote($order, $baseAmount);
        $grossQuoteSpend = $evaluation['grossQuote'];
        $fees = $evaluation['fees'];
        $effectiveQuoteAmount = $evaluation['effectiveQuote'];
        $netBaseAmount = $evaluation['netBase'];

        if (null !== $availableQuoteBudget) {
            $grossComparisonScale = max(
                $grossQuoteSpend->scale(),
                $availableQuoteBudget->scale(),
                self::SELL_RESOLUTION_COMPARISON_SCALE,
            );

            $grossComparable = $grossQuoteSpend->withScale($grossComparisonScale);
            $availableComparable = $availableQuoteBudget->withScale($grossComparisonScale);

            if ($grossComparable->greaterThan($availableComparable)) {
                if ($this->isWithinSellResolutionTolerance($availableComparable, $grossComparable)) {
                    $grossComparable = $availableComparable;
                } else {
                    return null;
                }
            }
        }

        $effectiveQuoteAmount = $effectiveQuoteAmount->withScale(max(
            $effectiveQuoteAmount->scale(),
            $originalTarget->scale(),
        ));
        $grossQuoteSpend = $grossQuoteSpend->withScale(max(
            $grossQuoteSpend->scale(),
            $originalTarget->scale(),
        ));
        $netBaseAmount = $netBaseAmount->withScale(max(
            $netBaseAmount->scale(),
            $baseAmount->scale(),
            $bounds->min()->scale(),
        ));

        return [
            $grossQuoteSpend,
            $netBaseAmount,
            $fees,
        ];
    }

    private function isWithinSellResolutionTolerance(Money $target, Money $actual): bool
    {
        $comparisonScale = max($target->scale(), $actual->scale(), self::SELL_RESOLUTION_COMPARISON_SCALE);

        $targetAmount = $target->withScale($comparisonScale)->amount();
        $actualAmount = $actual->withScale($comparisonScale)->amount();

        if (0 === BcMath::comp($targetAmount, '0', $comparisonScale)) {
            return 0 === BcMath::comp($actualAmount, '0', $comparisonScale);
        }

        $difference = BcMath::sub($actualAmount, $targetAmount, $comparisonScale + self::SELL_RESOLUTION_RATIO_EXTRA_SCALE);
        if ('-' === $difference[0]) {
            $difference = substr($difference, 1);
        }

        if ('' === $difference) {
            $difference = '0';
        }

        $relative = BcMath::div($difference, $targetAmount, $comparisonScale + self::SELL_RESOLUTION_RATIO_EXTRA_SCALE);

        return BcMath::comp($relative, self::SELL_RESOLUTION_RELATIVE_TOLERANCE, self::SELL_RESOLUTION_TOLERANCE_SCALE) <= 0;
    }

    private function calculateSellAdjustmentRatio(Money $target, Money $actual, int $scale): ?string
    {
        $targetAmount = $target->withScale($scale)->amount();
        $actualAmount = $actual->withScale($scale)->amount();

        if (0 === BcMath::comp($actualAmount, '0', $scale)) {
            return null;
        }

        $targetSignNegative = '-' === $targetAmount[0];
        $actualSignNegative = '-' === $actualAmount[0];

        if ($targetSignNegative !== $actualSignNegative && 0 !== BcMath::comp($targetAmount, '0', $scale)) {
            return null;
        }

        return BcMath::div($targetAmount, $actualAmount, $scale + self::SELL_RESOLUTION_RATIO_EXTRA_SCALE);
    }

    private function alignBaseScale(int $minScale, int $maxScale, Money $baseAmount): Money
    {
        $scale = max($baseAmount->scale(), $minScale, $maxScale);

        return $baseAmount->withScale($scale);
    }

    /**
     * @return array{
     *     grossQuote: Money,
     *     fees: FeeBreakdown,
     *     effectiveQuote: Money,
     *     netBase: Money,
     * }
     */
    private function evaluateSellQuote(Order $order, Money $baseAmount): array
    {
        $rawQuote = $order->calculateQuoteAmount($baseAmount);
        $fees = $this->resolveFeeBreakdown($order, OrderSide::SELL, $baseAmount, $rawQuote);
        $quoteFee = $fees->quoteFee();
        $effectiveQuote = $rawQuote;
        $grossQuote = $rawQuote;

        if (null !== $quoteFee && !$quoteFee->isZero()) {
            $effectiveQuote = $rawQuote->subtract($quoteFee);
            $grossQuote = $rawQuote->add($quoteFee);
        }

        $netBase = $baseAmount;
        if (OrderSide::SELL === $order->side()) {
            $baseFee = $fees->baseFee();
            if (null !== $baseFee && !$baseFee->isZero()) {
                $netBase = $baseAmount->subtract($baseFee);
            }
        }

        return [
            'grossQuote' => $grossQuote,
            'fees' => $fees,
            'effectiveQuote' => $effectiveQuote,
            'netBase' => $netBase,
        ];
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
