<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Service;

use SomeWork\P2PPathFinder\Application\PathSearch\Result\ExecutionPlan;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\ExecutionStep;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\ExecutionStepCollection;
use SomeWork\P2PPathFinder\Application\PathSearch\Support\OrderFillEvaluator;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Money\MoneyMap;
use SomeWork\P2PPathFinder\Domain\Order\Fee\FeeBreakdown;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\Tolerance\DecimalTolerance;

use function max;

/**
 * Materializes raw order fills from the search engine into a structured ExecutionPlan.
 *
 * This service converts the output of ExecutionPlanSearchEngine (list of order fills with
 * spend amounts and sequence numbers) into properly computed ExecutionStep objects with
 * received amounts, fees, and aggregates them into an ExecutionPlan.
 *
 * @internal
 */
final class ExecutionPlanMaterializer
{
    private readonly OrderFillEvaluator $fillEvaluator;
    private readonly LegMaterializer $legMaterializer;

    public function __construct(
        ?OrderFillEvaluator $fillEvaluator = null,
        ?LegMaterializer $legMaterializer = null,
    ) {
        $this->fillEvaluator = $fillEvaluator ?? new OrderFillEvaluator();
        $this->legMaterializer = $legMaterializer ?? new LegMaterializer($this->fillEvaluator);
    }

    /**
     * Materializes raw order fills into a complete ExecutionPlan.
     *
     * @param list<array{order: Order, spend: Money, sequence: int}> $orderFills     raw fills from search engine
     * @param string                                                 $sourceCurrency the starting currency of the path
     * @param string                                                 $targetCurrency the destination currency of the path
     * @param DecimalTolerance                                       $tolerance      residual tolerance for the plan
     *
     * @throws \SomeWork\P2PPathFinder\Exception\InvalidInput when steps don't match source/target currencies
     *
     * @return ExecutionPlan|null null if fills are empty or any fill cannot be materialized
     */
    public function materialize(
        array $orderFills,
        string $sourceCurrency,
        string $targetCurrency,
        DecimalTolerance $tolerance,
    ): ?ExecutionPlan {
        if ([] === $orderFills) {
            return null;
        }

        $steps = [];

        foreach ($orderFills as $fill) {
            $step = $this->processOrderFill($fill['order'], $fill['spend'], $fill['sequence']);

            if (null === $step) {
                return null;
            }

            $steps[] = $step;
        }

        $stepCollection = ExecutionStepCollection::fromList($steps);

        return ExecutionPlan::fromSteps(
            $stepCollection,
            $sourceCurrency,
            $targetCurrency,
            $tolerance,
        );
    }

    /**
     * Processes a single order fill into an ExecutionStep.
     *
     * @param Order $order    the order being filled
     * @param Money $spend    the amount being spent
     * @param int   $sequence the execution sequence number
     *
     * @return ExecutionStep|null null if the fill cannot be processed
     */
    private function processOrderFill(Order $order, Money $spend, int $sequence): ?ExecutionStep
    {
        if ($sequence < 1) {
            return null;
        }

        if ($spend->isZero()) {
            return null;
        }

        $pair = $order->assetPair();
        $spendCurrency = $spend->currency();

        // Determine direction based on order side
        // BUY order: taker spends base, receives quote
        // SELL order: taker spends quote, receives base
        if (OrderSide::BUY === $order->side()) {
            return $this->processBuyOrderFill($order, $spend, $sequence, $pair->base(), $pair->quote());
        }

        return $this->processSellOrderFill($order, $spend, $sequence, $pair->quote(), $pair->base());
    }

    /**
     * Processes a BUY order fill (taker spends base, receives quote).
     */
    private function processBuyOrderFill(
        Order $order,
        Money $spend,
        int $sequence,
        string $expectedFrom,
        string $expectedTo,
    ): ?ExecutionStep {
        $spendCurrency = $spend->currency();

        // Validate spend currency matches expected source for BUY orders
        if ($spendCurrency !== $expectedFrom) {
            return null;
        }

        // For BUY orders, we need to validate and compute amounts using the fill evaluator
        $bounds = $order->bounds();
        $boundsScale = max($bounds->min()->scale(), $bounds->max()->scale());

        // Normalize spend to match bounds scale for validation
        $normalizedSpend = $spend->withScale(max($spend->scale(), $boundsScale));

        // Validate spend is within order bounds
        if (!$bounds->contains($normalizedSpend)) {
            return null;
        }

        // Use OrderFillEvaluator to compute amounts including fees
        $evaluation = $this->fillEvaluator->evaluate($order, $normalizedSpend);
        $received = $evaluation['quote'];
        $fees = $evaluation['fees'];

        $feesMap = $this->convertFeesToMap($fees);

        return new ExecutionStep(
            $expectedFrom,
            $expectedTo,
            $spend,
            $received,
            $order,
            $feesMap,
            $sequence,
        );
    }

    /**
     * Processes a SELL order fill (taker spends quote, receives base).
     */
    private function processSellOrderFill(
        Order $order,
        Money $spend,
        int $sequence,
        string $expectedFrom,
        string $expectedTo,
    ): ?ExecutionStep {
        $spendCurrency = $spend->currency();

        // Validate spend currency matches expected source for SELL orders
        if ($spendCurrency !== $expectedFrom) {
            return null;
        }

        // For SELL orders, use LegMaterializer to resolve amounts
        // The spend is in quote currency, we need to find the base amount
        $resolved = $this->legMaterializer->resolveSellLegAmounts($order, $spend);

        if (null === $resolved) {
            return null;
        }

        [$grossSpent, $netReceived, $fees] = $resolved;

        $feesMap = $this->convertFeesToMap($fees);

        return new ExecutionStep(
            $expectedFrom,
            $expectedTo,
            $grossSpent,
            $netReceived,
            $order,
            $feesMap,
            $sequence,
        );
    }

    /**
     * Converts a FeeBreakdown into a MoneyMap for the ExecutionStep.
     */
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
}
