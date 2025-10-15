<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Service;

use SomeWork\P2PPathFinder\Application\PathFinder\PathFinder;
use SomeWork\P2PPathFinder\Application\Result\PathLeg;
use SomeWork\P2PPathFinder\Application\Support\OrderFillEvaluator;
use SomeWork\P2PPathFinder\Domain\Order\FeeBreakdown;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

use function ksort;
use function max;
use function substr;

/**
 * Resolves concrete path legs from abstract graph edges.
 *
 * @psalm-import-type GraphEdge from PathFinder
 * @psalm-import-type PathEdge from PathFinder
 */
final class LegMaterializer
{
    private const SELL_RESOLUTION_MAX_ITERATIONS = 16;
    private const SELL_RESOLUTION_RELATIVE_TOLERANCE = '0.000001';
    private const SELL_RESOLUTION_COMPARISON_SCALE = 18;
    private const SELL_RESOLUTION_RATIO_EXTRA_SCALE = 6;
    private const SELL_RESOLUTION_TOLERANCE_SCALE = 12;
    private const BUY_ADJUSTMENT_MAX_ITERATIONS = 12;

    private readonly OrderFillEvaluator $fillEvaluator;

    public function __construct(?OrderFillEvaluator $fillEvaluator = null)
    {
        $this->fillEvaluator = $fillEvaluator ?? new OrderFillEvaluator();
    }

    /**
     * @param list<GraphEdge>|list<PathEdge>                       $edges
     * @param array{net: Money, gross: Money, grossCeiling: Money} $initialSeed
     *
     * @return array{
     *     totalSpent: Money,
     *     totalReceived: Money,
     *     toleranceSpent: Money,
     *     legs: list<PathLeg>,
     *     feeBreakdown: array<string, Money>,
     * }|null
     */
    public function materialize(array $edges, Money $requestedSpend, array $initialSeed, string $targetCurrency): ?array
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

        return [
            'totalSpent' => $grossSpent,
            'totalReceived' => $current,
            'toleranceSpent' => $toleranceSpent,
            'legs' => $legs,
            'feeBreakdown' => $feeBreakdown,
        ];
    }

    /**
     * @return array{0: Money, 1: Money, 2: FeeBreakdown}|null
     */
    public function resolveSellLegAmounts(Order $order, Money $targetEffectiveQuote, ?Money $availableQuoteBudget = null): ?array
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

                if ($grossComparable->greaterThan($availableComparable) && !$this->isWithinSellResolutionTolerance($availableComparable, $grossComparable)) {
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

            $comparisonScale = max(
                $effectiveQuoteAmount->scale(),
                $currentTarget->scale(),
                self::SELL_RESOLUTION_COMPARISON_SCALE,
            );

            $effectiveComparable = $effectiveQuoteAmount->withScale($comparisonScale);
            $targetComparable = $currentTarget->withScale($comparisonScale);

            if ($this->isWithinSellResolutionTolerance($targetComparable, $effectiveComparable)) {
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
        $netBaseAmount = $evaluation['netBase'];

        if (null !== $availableQuoteBudget) {
            $grossComparisonScale = max(
                $grossQuoteSpend->scale(),
                $availableQuoteBudget->scale(),
                self::SELL_RESOLUTION_COMPARISON_SCALE,
            );

            $grossComparable = $grossQuoteSpend->withScale($grossComparisonScale);
            $availableComparable = $availableQuoteBudget->withScale($grossComparisonScale);

            if ($grossComparable->greaterThan($availableComparable) && !$this->isWithinSellResolutionTolerance($availableComparable, $grossComparable)) {
                return null;
            }
        }

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

    /**
     * @return array{gross: Money, quote: Money, fees: FeeBreakdown, net: Money}|null
     */
    public function resolveBuyFill(Order $order, Money $netSeed, Money $grossSeed, Money $grossCeiling): ?array
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
     * @return array{
     *     grossQuote: Money,
     *     fees: FeeBreakdown,
     *     effectiveQuote: Money,
     *     netBase: Money,
     * }
     */
    public function evaluateSellQuote(Order $order, Money $baseAmount): array
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

    private function resolveFeeBreakdown(Order $order, OrderSide $side, Money $baseAmount, Money $rawQuote): FeeBreakdown
    {
        $policy = $order->feePolicy();
        if (null === $policy) {
            return FeeBreakdown::none();
        }

        return $policy->calculate($side, $baseAmount, $rawQuote);
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

        BcMath::ensureNumeric($difference);
        $relative = BcMath::div($difference, $targetAmount, $comparisonScale + self::SELL_RESOLUTION_RATIO_EXTRA_SCALE);

        return BcMath::comp($relative, self::SELL_RESOLUTION_RELATIVE_TOLERANCE, self::SELL_RESOLUTION_TOLERANCE_SCALE) <= 0;
    }

    /**
     * @return numeric-string|null
     */
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
}
