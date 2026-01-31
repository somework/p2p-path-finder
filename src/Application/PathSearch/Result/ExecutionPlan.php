<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Result;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Money\MoneyMap;
use SomeWork\P2PPathFinder\Domain\Tolerance\DecimalTolerance;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

use function count;
use function implode;
use function sort;
use function spl_object_id;
use function strtoupper;
use function trim;

/**
 * Represents a complete execution plan that can express both linear paths and split/merge execution.
 *
 * An execution plan aggregates multiple {@see ExecutionStep} instances and provides:
 * - Total spent/received amounts across all steps
 * - Merged fee breakdown from all steps
 * - Detection of linear vs branched execution topology
 * - Conversion back to {@see Path} for linear plans
 *
 * Linear execution: A → B → C → D (single chain)
 * Multi-order: A → B (order1), A → B (order2) → C
 * Split/merge: A → B, A → C, then B → D, C → D
 *
 * @api
 */
final class ExecutionPlan implements SearchResultInterface
{
    public function __construct(
        private readonly ExecutionStepCollection $steps,
        private readonly string $sourceCurrency,
        private readonly string $targetCurrency,
        private readonly Money $totalSpent,
        private readonly Money $totalReceived,
        private readonly MoneyMap $feeBreakdown,
        private readonly DecimalTolerance $residualTolerance,
    ) {
    }

    /**
     * Creates an ExecutionPlan from a collection of steps.
     *
     * Calculates total spent (sum of spends from source currency), total received
     * (sum of receives into target currency), and merged fee breakdown.
     *
     * @throws InvalidInput when steps collection is empty or aggregation fails
     */
    public static function fromSteps(
        ExecutionStepCollection $steps,
        string $sourceCurrency,
        string $targetCurrency,
        DecimalTolerance $tolerance,
    ): self {
        if ($steps->isEmpty()) {
            throw new InvalidInput('Execution plan must contain at least one step.');
        }

        $normalizedSource = strtoupper(trim($sourceCurrency));
        $normalizedTarget = strtoupper(trim($targetCurrency));

        if ('' === $normalizedSource) {
            throw new InvalidInput('Source currency cannot be empty.');
        }

        if ('' === $normalizedTarget) {
            throw new InvalidInput('Target currency cannot be empty.');
        }

        $totalSpent = self::calculateTotalSpent($steps, $normalizedSource);
        $totalReceived = self::calculateTotalReceived($steps, $normalizedTarget);
        $feeBreakdown = self::aggregateFees($steps);

        return new self(
            $steps,
            $normalizedSource,
            $normalizedTarget,
            $totalSpent,
            $totalReceived,
            $feeBreakdown,
            $tolerance,
        );
    }

    /**
     * Determines whether the execution plan represents a linear path.
     *
     * A plan is linear if:
     * 1. For each step (except first), there exists exactly one preceding step
     *    that outputs to this step's input currency
     * 2. No two steps share the same source currency (no splits)
     * 3. Steps form a single contiguous chain from source to target
     */
    public function isLinear(): bool
    {
        return null !== $this->buildLinearChain();
    }

    /**
     * Converts a linear execution plan to a Path.
     *
     * @throws InvalidInput when conversion fails due to invalid data
     *
     * @return Path|null null if the plan is not linear
     */
    public function asLinearPath(): ?Path
    {
        $orderedSteps = $this->buildLinearChain();

        if (null === $orderedSteps) {
            return null;
        }

        $hops = [];
        foreach ($orderedSteps as $step) {
            $hops[] = new PathHop(
                $step->from(),
                $step->to(),
                $step->spent(),
                $step->received(),
                $step->order(),
                $step->fees(),
            );
        }

        $hopCollection = PathHopCollection::fromList($hops);

        return new Path($hopCollection, $this->residualTolerance);
    }

    /**
     * Attempts to build a linear chain of steps from source to target.
     *
     * @return list<ExecutionStep>|null ordered steps if linear, null otherwise
     */
    private function buildLinearChain(): ?array
    {
        if ($this->steps->isEmpty()) {
            return null;
        }

        $stepList = $this->steps->all();
        $stepCount = count($stepList);

        if (1 === $stepCount) {
            $step = $stepList[0];
            if ($step->from() === $this->sourceCurrency && $step->to() === $this->targetCurrency) {
                return $stepList;
            }

            return null;
        }

        // Track which currencies are used as step sources (from)
        /** @var array<string, int> $sourceUsage Currency => count of steps using it as source */
        $sourceUsage = [];

        // Track which currencies are produced as outputs (to)
        /** @var array<string, int> $outputUsage Currency => count of steps producing it */
        $outputUsage = [];

        /** @var array<string, ExecutionStep> $stepsBySource */
        $stepsBySource = [];

        foreach ($stepList as $step) {
            $from = $step->from();
            $to = $step->to();

            $sourceUsage[$from] = ($sourceUsage[$from] ?? 0) + 1;
            $outputUsage[$to] = ($outputUsage[$to] ?? 0) + 1;
            $stepsBySource[$from] = $step;
        }

        // Check for splits: no currency should be used as source more than once
        foreach ($sourceUsage as $count) {
            if ($count > 1) {
                return null;
            }
        }

        // Check for merges: no currency should be produced as output more than once
        foreach ($outputUsage as $count) {
            if ($count > 1) {
                return null;
            }
        }

        // Build ordered chain by walking from source to target
        $currentCurrency = $this->sourceCurrency;
        $orderedSteps = [];

        while (isset($stepsBySource[$currentCurrency])) {
            $step = $stepsBySource[$currentCurrency];
            $orderedSteps[] = $step;
            $currentCurrency = $step->to();

            if (count($orderedSteps) > $stepCount) {
                // Cycle detected
                return null;
            }
        }

        // All steps should be visited and we should end at target
        if (count($orderedSteps) === $stepCount && $currentCurrency === $this->targetCurrency) {
            return $orderedSteps;
        }

        return null;
    }

    public function stepCount(): int
    {
        return $this->steps->count();
    }

    public function steps(): ExecutionStepCollection
    {
        return $this->steps;
    }

    public function sourceCurrency(): string
    {
        return $this->sourceCurrency;
    }

    public function targetCurrency(): string
    {
        return $this->targetCurrency;
    }

    public function totalSpent(): Money
    {
        return $this->totalSpent;
    }

    public function totalReceived(): Money
    {
        return $this->totalReceived;
    }

    public function feeBreakdown(): MoneyMap
    {
        return $this->feeBreakdown;
    }

    public function residualTolerance(): DecimalTolerance
    {
        return $this->residualTolerance;
    }

    /**
     * Returns a deterministic signature for duplicate detection.
     *
     * The signature combines the route (source->target currencies) with
     * sorted order object IDs to uniquely identify a plan's structure.
     * Plans with identical signatures use the exact same orders for the
     * exact same currency transformation.
     *
     * @return string Deterministic signature based on route and orders
     */
    public function signature(): string
    {
        $orderIds = [];
        foreach ($this->steps as $step) {
            $orderIds[] = spl_object_id($step->order());
        }
        sort($orderIds);

        return $this->sourceCurrency.'->'.$this->targetCurrency
             .':'.implode(',', $orderIds);
    }

    /**
     * Checks if this plan is effectively a duplicate of another plan.
     *
     * Plans are considered duplicates if they have:
     * - Identical signature (same orders for same route), OR
     * - Identical effective cost within epsilon tolerance
     *
     * This is used during reusable Top-K search to filter out plans that
     * would provide no additional value to the user.
     *
     * @param self   $other       The plan to compare against
     * @param string $costEpsilon Maximum cost difference to consider equal (default: 0.000001)
     *
     * @return bool True if this plan is effectively a duplicate of the other
     */
    public function isDuplicateOf(self $other, string $costEpsilon = '0.000001'): bool
    {
        // Same signature = definite duplicate
        if ($this->signature() === $other->signature()) {
            return true;
        }

        // Same route required for cost comparison to be meaningful
        if ($this->sourceCurrency !== $other->sourceCurrency
            || $this->targetCurrency !== $other->targetCurrency) {
            return false;
        }

        // Compare effective costs (spent / received ratio)
        // Lower cost = better (spend less to receive more)
        $thisCost = $this->calculateEffectiveCost();
        $otherCost = $other->calculateEffectiveCost();

        $diff = $thisCost->minus($otherCost)->abs();
        $epsilon = BigDecimal::of($costEpsilon);

        return $diff->isLessThanOrEqualTo($epsilon);
    }

    /**
     * Calculates the effective cost ratio (spent / received).
     *
     * @return BigDecimal Cost ratio with high precision
     */
    private function calculateEffectiveCost(): BigDecimal
    {
        if ($this->totalReceived->isZero()) {
            return BigDecimal::of('999999999999999999');
        }

        return $this->totalSpent->decimal()->dividedBy(
            $this->totalReceived->decimal(),
            18,
            RoundingMode::HalfUp
        );
    }

    /**
     * @return array{
     *     steps: list<array{from: string, to: string, spent: string, received: string, fees: array<string, string>, sequence: int}>,
     *     sourceCurrency: string,
     *     targetCurrency: string,
     *     totalSpent: string,
     *     totalReceived: string,
     *     feeBreakdown: array<string, string>,
     *     residualTolerance: string,
     * }
     */
    public function toArray(): array
    {
        $feesArray = [];
        foreach ($this->feeBreakdown as $currency => $money) {
            $feesArray[$currency] = $money->amount();
        }

        return [
            'steps' => $this->steps->toArray(),
            'sourceCurrency' => $this->sourceCurrency,
            'targetCurrency' => $this->targetCurrency,
            'totalSpent' => $this->totalSpent->amount(),
            'totalReceived' => $this->totalReceived->amount(),
            'feeBreakdown' => $feesArray,
            'residualTolerance' => $this->residualTolerance->ratio(),
        ];
    }

    /**
     * Calculates total spent from all steps that spend the source currency.
     */
    private static function calculateTotalSpent(ExecutionStepCollection $steps, string $sourceCurrency): Money
    {
        /** @var Money|null $total */
        $total = null;

        foreach ($steps as $step) {
            if ($step->from() !== $sourceCurrency) {
                continue;
            }

            if (null === $total) {
                $total = $step->spent();
            } else {
                $total = $total->add($step->spent());
            }
        }

        if (null === $total) {
            throw new InvalidInput('No steps found spending the source currency.');
        }

        /* @var Money $total */
        return $total;
    }

    /**
     * Calculates total received from all steps that receive the target currency.
     */
    private static function calculateTotalReceived(ExecutionStepCollection $steps, string $targetCurrency): Money
    {
        /** @var Money|null $total */
        $total = null;

        foreach ($steps as $step) {
            if ($step->to() !== $targetCurrency) {
                continue;
            }

            if (null === $total) {
                $total = $step->received();
            } else {
                $total = $total->add($step->received());
            }
        }

        if (null === $total) {
            throw new InvalidInput('No steps found receiving the target currency.');
        }

        /* @var Money $total */
        return $total;
    }

    /**
     * Aggregates fees from all steps into a single MoneyMap.
     */
    private static function aggregateFees(ExecutionStepCollection $steps): MoneyMap
    {
        $aggregate = MoneyMap::empty();

        foreach ($steps as $step) {
            $aggregate = $aggregate->merge($step->fees());
        }

        return $aggregate;
    }
}
