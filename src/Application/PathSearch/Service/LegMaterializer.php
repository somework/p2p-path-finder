<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Service;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\PathEdgeSequence;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathHop;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathHopCollection;
use SomeWork\P2PPathFinder\Application\PathSearch\Support\OrderFillEvaluator;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Money\MoneyMap;
use SomeWork\P2PPathFinder\Domain\Order\Fee\FeeBreakdown;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\DecimalHelperTrait;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

use function max;

/**
 * Resolves concrete path hops from abstract graph edges.
 *
 * @internal
 */
final class LegMaterializer
{
    use DecimalHelperTrait;

    private const SELL_RESOLUTION_MAX_ITERATIONS = 16;
    private const SELL_RESOLUTION_RELATIVE_TOLERANCE = '0.000001';
    /**
     * @see DecimalHelperTrait::CANONICAL_SCALE
     */
    private const SELL_RESOLUTION_COMPARISON_SCALE = self::CANONICAL_SCALE;
    private const SELL_RESOLUTION_RATIO_EXTRA_SCALE = 6;
    private const SELL_RESOLUTION_TOLERANCE_SCALE = 12;
    private const BUY_ADJUSTMENT_MAX_ITERATIONS = 12;
    private const BUY_ADJUSTMENT_RATIO_MIN_SCALE = 12;
    private const BUY_ADJUSTMENT_RATIO_EXTRA_SCALE = 4;

    private readonly OrderFillEvaluator $fillEvaluator;

    public function __construct(?OrderFillEvaluator $fillEvaluator = null)
    {
        $this->fillEvaluator = $fillEvaluator ?? new OrderFillEvaluator();
    }

    /**
     * @param array{net: Money, gross: Money, grossCeiling: Money} $initialSeed
     *
     * @throws InvalidInput|PrecisionViolation when path edges cannot be materialized or arithmetic operations fail
     *
     * @return array{
     *     totalSpent: Money,
     *     totalReceived: Money,
     *     toleranceSpent: Money,
     *     hops: PathHopCollection,
     *     feeBreakdown: MoneyMap,
     * }|null
     */
    public function materialize(PathEdgeSequence $edges, Money $requestedSpend, array $initialSeed, string $targetCurrency): ?array
    {
        // Validate entry conditions
        if ($edges->isEmpty()) {
            // Cannot materialize empty path
            return null;
        }

        $zeroNet = Money::zero($initialSeed['net']->currency(), $initialSeed['net']->scale());
        if ($initialSeed['net']->isZero() || $initialSeed['net']->lessThan($zeroNet)) {
            // Invalid initial net seed - must be positive
            return null;
        }

        $zeroGross = Money::zero($initialSeed['gross']->currency(), $initialSeed['gross']->scale());
        if ($initialSeed['gross']->isZero() || $initialSeed['gross']->lessThan($zeroGross)) {
            // Invalid initial gross seed - must be positive
            return null;
        }

        $zeroCeiling = Money::zero($initialSeed['grossCeiling']->currency(), $initialSeed['grossCeiling']->scale());
        if ($initialSeed['grossCeiling']->isZero() || $initialSeed['grossCeiling']->lessThan($zeroCeiling)) {
            // Invalid gross ceiling - must be positive
            return null;
        }

        $zeroSpend = Money::zero($requestedSpend->currency(), $requestedSpend->scale());
        if ($requestedSpend->isZero() || $requestedSpend->lessThan($zeroSpend)) {
            // Invalid requested spend - must be positive
            return null;
        }

        $hops = [];
        $current = $initialSeed['net'];
        $currentCurrency = $current->currency();
        $feeBreakdown = MoneyMap::empty();

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
            $order = $edge->order();
            $orderSide = $edge->orderSide();
            $from = $edge->from();
            $to = $edge->to();

            if ($from !== $currentCurrency) {
                // Edge sequence is not contiguous - path cannot be materialized
                return null;
            }

            if (OrderSide::BUY === $orderSide) {
                $grossSeedForLeg = $applyTolerance ? $initialGrossSeed : $current;
                $grossCeilingForLeg = $applyTolerance ? $remainingGrossBudget : $grossSeedForLeg;

                $resolved = $this->resolveBuyLegAmounts($order, $current, $grossSeedForLeg, $grossCeilingForLeg);

                if (null === $resolved) {
                    // Buy leg cannot be resolved - insufficient budget or order bounds cannot be satisfied
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
                    // Sell leg cannot be resolved - budget exceeded, convergence failed, or order bounds violated
                    return null;
                }

                [$spent, $received, $fees] = $resolved;

                if ($applyTolerance && $spent->currency() === $remainingGrossBudget->currency()) {
                    $remainingGrossBudget = $this->reduceBudget($remainingGrossBudget, $spent);
                }

                $applyTolerance = false;
            }

            $legFees = $this->convertFeesToMap($fees);
            $feeBreakdown = $feeBreakdown->merge($legFees);

            if ($spent->currency() === $grossSpent->currency()) {
                $grossSpent = $grossSpent->add($spent, $grossSpentScale);
            }

            if ($spent->currency() === $toleranceSpent->currency()) {
                $toleranceSpent = $toleranceSpent->add($spent, $grossSpentScale);
            }

            $hops[] = new PathHop($from, $to, $spent, $received, $order, $legFees);
            $current = $received;
            $currentCurrency = $current->currency();
        }

        if ($currentCurrency !== $targetCurrency) {
            // Final currency does not match target - path does not reach destination
            return null;
        }

        return [
            'totalSpent' => $grossSpent,
            'totalReceived' => $current,
            'toleranceSpent' => $toleranceSpent,
            'hops' => PathHopCollection::fromList($hops),
            'feeBreakdown' => $feeBreakdown,
        ];
    }

    /**
     * @throws PrecisionViolation when monetary calculations exceed precision limits
     *
     * @return array{0: Money, 1: Money, 2: FeeBreakdown}|null
     */
    public function resolveSellLegAmounts(Order $order, Money $targetEffectiveQuote, ?Money $availableQuoteBudget = null): ?array
    {
        $bounds = $order->bounds();

        if (null === $order->feePolicy()) {
            // For SELL: taker spends quote (e.g., RUB), receives base (e.g., USDT)
            // received = spent / rate (where rate is base/quote, e.g., USDT/RUB = 95)
            // Using direct division preserves precision (rate inversion can lose precision)
            $rate = $order->effectiveRate();

            // Output scale matches input precision requirements
            $outputScale = max(
                $targetEffectiveQuote->scale(),
                $bounds->min()->scale(),
                $rate->scale(),
            );

            // Working scale uses higher precision for accurate calculation
            $workingScale = max($outputScale, self::SELL_RESOLUTION_COMPARISON_SCALE);

            $spent = $targetEffectiveQuote->withScale($outputScale);
            // Direct division: base_amount = quote_amount / rate (use high precision, then scale result)
            $receivedDecimal = $targetEffectiveQuote->decimal()->dividedBy($rate->decimal(), $workingScale, RoundingMode::HalfUp);
            $received = Money::fromString(
                $order->assetPair()->base(),
                self::decimalToString($receivedDecimal, $outputScale),
                $outputScale
            );

            if (!$bounds->contains($received->withScale(max($received->scale(), $bounds->min()->scale())))) {
                // Received amount falls outside order bounds
                return null;
            }

            if (null !== $availableQuoteBudget && $spent->greaterThan($availableQuoteBudget)) {
                // Spent amount exceeds available budget
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
                        // Cannot calculate adjustment ratio - division by zero or sign mismatch
                        return null;
                    }

                    $previousBase = $baseAmount;
                    $baseAmount = $baseAmount->multiply($ratio, max($baseAmount->scale(), $grossComparisonScale));
                    $baseAmount = $this->alignBaseScale($bounds->min()->scale(), $bounds->max()->scale(), $baseAmount);

                    if ($baseAmount->equals($previousBase)) {
                        // Base amount adjustment converged to same value - cannot make progress
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
                // Cannot calculate adjustment ratio - division by zero or sign mismatch
                return null;
            }

            $baseAmount = $baseAmount->multiply($ratio, max($baseAmount->scale(), $comparisonScale));
            $baseAmount = $this->alignBaseScale($bounds->min()->scale(), $bounds->max()->scale(), $baseAmount);
        }

        if (!$converged) {
            // Iterative resolution failed to converge within maximum iterations
            return null;
        }

        if (!$bounds->contains($baseAmount->withScale(max($baseAmount->scale(), $bounds->min()->scale())))) {
            // Calculated base amount falls outside order bounds
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
                // Final gross quote exceeds available budget after convergence
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
     * @throws PrecisionViolation when monetary calculations exceed precision limits
     *
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
                // Minimum order fill requires more budget than available ceiling
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

            $ratioScale = max($comparisonScale, self::BUY_ADJUSTMENT_RATIO_MIN_SCALE);
            $ceilingDecimal = self::scaleDecimal($ceilingComparable->decimal(), $ratioScale);
            $grossDecimal = self::scaleDecimal($grossComparable->decimal(), $ratioScale);

            if ($grossDecimal->isZero()) {
                // Gross amount is zero - cannot calculate budget adjustment ratio
                return null;
            }

            $divisionScale = $ratioScale + self::BUY_ADJUSTMENT_RATIO_EXTRA_SCALE;
            $ratio = $ceilingDecimal->dividedBy($grossDecimal, $divisionScale, RoundingMode::HalfUp);

            if ($ratio->isZero()) {
                // Adjustment ratio collapsed to zero - cannot make progress
                return null;
            }

            $ratioString = self::decimalToString($ratio, $divisionScale);
            $nextNet = $netCandidate->multiply($ratioString, max($netCandidate->scale(), $divisionScale));
            $nextNet = $bounds->clamp($nextNet);

            if ($nextNet->equals($netCandidate)) {
                // Net amount adjustment converged to same value - cannot make progress
                return null;
            }

            $netCandidate = $nextNet;
        }

        // Buy fill adjustment failed to converge within maximum iterations
        return null;
    }

    /**
     * @throws PrecisionViolation when fee calculation or monetary operations exceed precision limits
     *
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

    private function convertFeesToMap(FeeBreakdown $fees): MoneyMap
    {
        $baseFee = $fees->baseFee();
        $quoteFee = $fees->quoteFee();

        $entries = [];
        if (null !== $baseFee) {
            $entries[] = $baseFee;
        }

        if (null !== $quoteFee) {
            $entries[] = $quoteFee;
        }

        return MoneyMap::fromList($entries, true);
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

        $targetDecimal = self::scaleDecimal($target->decimal(), $comparisonScale);
        $actualDecimal = self::scaleDecimal($actual->decimal(), $comparisonScale);

        if ($targetDecimal->isZero()) {
            return $actualDecimal->isZero();
        }

        $difference = $actualDecimal->minus($targetDecimal)->abs();
        $relativeScale = $comparisonScale + self::SELL_RESOLUTION_RATIO_EXTRA_SCALE;
        $relative = $difference->dividedBy($targetDecimal->abs(), $relativeScale, RoundingMode::HalfUp);

        $tolerance = self::scaleDecimal(
            BigDecimal::of(self::SELL_RESOLUTION_RELATIVE_TOLERANCE),
            self::SELL_RESOLUTION_TOLERANCE_SCALE,
        );

        return $relative->compareTo($tolerance) <= 0;
    }

    /**
     * @return numeric-string|null
     */
    private function calculateSellAdjustmentRatio(Money $target, Money $actual, int $scale): ?string
    {
        $targetDecimal = self::scaleDecimal($target->decimal(), $scale);
        $actualDecimal = self::scaleDecimal($actual->decimal(), $scale);

        if ($actualDecimal->isZero()) {
            // Actual amount is zero - cannot calculate adjustment ratio
            return null;
        }

        $targetNegative = $targetDecimal->isNegative();
        $actualNegative = $actualDecimal->isNegative();

        if ($targetNegative !== $actualNegative && !$targetDecimal->isZero()) {
            // Target and actual have different signs - cannot calculate meaningful ratio
            return null;
        }

        $ratioScale = $scale + self::SELL_RESOLUTION_RATIO_EXTRA_SCALE;
        $ratio = $targetDecimal->dividedBy($actualDecimal, $ratioScale, RoundingMode::HalfUp);

        return self::decimalToString($ratio, $ratioScale);
    }

    private function alignBaseScale(int $minScale, int $maxScale, Money $baseAmount): Money
    {
        $scale = max($baseAmount->scale(), $minScale, $maxScale);

        return $baseAmount->withScale($scale);
    }
}
