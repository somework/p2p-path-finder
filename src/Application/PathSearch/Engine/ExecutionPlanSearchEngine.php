<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Engine;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Guard\SearchGuards;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\State\PortfolioState;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\EdgeCapacity;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\Graph;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\GraphEdge;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\SearchGuardReport;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\DecimalHelperTrait;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

use function array_key_exists;
use function array_keys;
use function array_push;
use function array_reverse;
use function strtoupper;

/**
 * Execution plan search engine implementing successive shortest augmenting paths algorithm.
 *
 * ## Algorithm Overview
 *
 * This engine finds optimal execution plans for converting an amount from a source currency
 * to a target currency by finding successive shortest augmenting paths:
 *
 * 1. Initialize portfolio with source currency balance
 * 2. While target balance < requested amount AND within budget:
 *    a. Find cheapest augmenting path from any currency with balance to target
 *    b. Calculate bottleneck (minimum flow along path)
 *    c. Execute flow along path, updating portfolio state
 * 3. Return execution plan with all steps
 *
 * ## Supported Path Types
 *
 * - **Linear paths**: A → B → C → D (single chain)
 * - **Multi-order same direction**: Multiple orders A → B combined
 * - **Split routes**: A → B → D and A → C → D (parallel paths merging)
 * - **Complex combinations**: Any combination of the above
 *
 * ## Key Features
 *
 * - Uses {@see PortfolioState} for multi-currency balance tracking
 * - Prevents backtracking (cannot return to fully spent currency)
 * - Each order used only once
 * - Deterministic results via consistent ordering
 * - Guard limits (expansions, time, visited states)
 *
 * @internal
 */
final class ExecutionPlanSearchEngine
{
    use DecimalHelperTrait;

    private const SCALE = self::CANONICAL_SCALE;

    public const DEFAULT_MAX_EXPANSIONS = 10000;

    public const DEFAULT_MAX_VISITED_STATES = 50000;

    public const DEFAULT_TIME_BUDGET_MS = 5000;

    public function __construct(
        private readonly int $maxExpansions = self::DEFAULT_MAX_EXPANSIONS,
        private readonly ?int $timeBudgetMs = self::DEFAULT_TIME_BUDGET_MS,
        private readonly int $maxVisitedStates = self::DEFAULT_MAX_VISITED_STATES,
    ) {
        if ($maxExpansions < 1) {
            throw new InvalidInput('Maximum expansions must be at least one.');
        }

        if ($maxVisitedStates < 1) {
            throw new InvalidInput('Maximum visited states must be at least one.');
        }

        if (null !== $timeBudgetMs && $timeBudgetMs < 1) {
            throw new InvalidInput('Time budget must be at least one millisecond.');
        }
    }

    /**
     * Searches for an optimal execution plan to convert source currency to target currency.
     *
     * @param Graph  $graph          The trading graph to search
     * @param string $sourceCurrency Source currency code
     * @param string $targetCurrency Target currency code
     * @param Money  $spendAmount    Amount to spend in source currency
     *
     * @return ExecutionPlanSearchOutcome The search outcome containing plan and guard report
     */
    public function search(
        Graph $graph,
        string $sourceCurrency,
        string $targetCurrency,
        Money $spendAmount,
    ): ExecutionPlanSearchOutcome {
        $sourceCurrency = strtoupper($sourceCurrency);
        $targetCurrency = strtoupper($targetCurrency);

        // Validate currencies exist in graph
        if (!$graph->hasNode($sourceCurrency) || !$graph->hasNode($targetCurrency)) {
            return ExecutionPlanSearchOutcome::empty(
                SearchGuardReport::idle($this->maxVisitedStates, $this->maxExpansions, $this->timeBudgetMs)
            );
        }

        // Validate spend amount matches source currency
        if ($spendAmount->currency() !== $sourceCurrency) {
            throw new InvalidInput('Spend amount currency must match source currency.');
        }

        if ($spendAmount->isZero()) {
            return ExecutionPlanSearchOutcome::empty(
                SearchGuardReport::idle($this->maxVisitedStates, $this->maxExpansions, $this->timeBudgetMs)
            );
        }

        // Same currency - check for transfer orders (cross-exchange movements)
        if ($sourceCurrency === $targetCurrency) {
            return $this->executeTransferSearch($graph, $sourceCurrency, $spendAmount);
        }

        return $this->executeSearch($graph, $sourceCurrency, $targetCurrency, $spendAmount);
    }

    private function executeSearch(
        Graph $graph,
        string $sourceCurrency,
        string $targetCurrency,
        Money $spendAmount,
    ): ExecutionPlanSearchOutcome {
        $portfolio = PortfolioState::initial($spendAmount);
        $guards = new SearchGuards($this->maxExpansions, $this->timeBudgetMs);
        $visitedStates = 1;
        $visitedGuardReached = false;

        /** @var list<array{order: Order, spend: Money, sequence: int}> $rawFills */
        $rawFills = [];
        $sequenceNumber = 1;

        // Main loop: find augmenting paths until no more balance to convert
        while ($this->hasRemainingBalance($portfolio, $targetCurrency)) {
            if (!$guards->canExpand()) {
                break;
            }

            if ($visitedStates >= $this->maxVisitedStates) {
                $visitedGuardReached = true;
                break;
            }

            $guards->recordExpansion();
            ++$visitedStates;

            // Find cheapest augmenting path from any currency with balance to target
            $pathResult = $this->findAugmentingPath($portfolio, $graph, $targetCurrency);

            if (null === $pathResult) {
                // No more paths available
                break;
            }

            /** @var list<GraphEdge> $path */
            $path = $pathResult['path'];
            /** @var string $startCurrency */
            $startCurrency = $pathResult['startCurrency'];

            // Calculate bottleneck (maximum flow we can push through this path)
            $bottleneck = $this->calculateBottleneck($path, $portfolio, $startCurrency);

            if ($bottleneck->isZero()) {
                // No flow possible on this path - mark orders as used and try another
                foreach ($path as $edge) {
                    $portfolio = $portfolio->markOrderUsed($edge->order());
                }
                continue;
            }

            // Execute flow along path and collect raw fills
            $flowResult = $this->executeFlow($path, $portfolio, $bottleneck, $startCurrency, $sequenceNumber);
            $portfolio = $flowResult['portfolio'];
            /** @var list<array{order: Order, spend: Money, sequence: int}> $newFills */
            $newFills = $flowResult['fills'];
            $sequenceNumber = $flowResult['sequenceNumber'];

            array_push($rawFills, ...$newFills);
        }

        $guardReport = $guards->finalize($visitedStates, $this->maxVisitedStates, $visitedGuardReached);

        if ([] === $rawFills) {
            return ExecutionPlanSearchOutcome::empty($guardReport);
        }

        // Check if we achieved full conversion: source currency balance should be zero
        // and we should have received something in target currency
        $sourceBalance = $portfolio->balance($sourceCurrency);
        $targetBalance = $portfolio->balance($targetCurrency);
        $isComplete = $sourceBalance->isZero() && !$targetBalance->isZero();

        if ($isComplete) {
            return ExecutionPlanSearchOutcome::complete($rawFills, $guardReport, $sourceCurrency, $targetCurrency);
        }

        return ExecutionPlanSearchOutcome::partial($rawFills, $guardReport, $sourceCurrency, $targetCurrency);
    }

    /**
     * Executes a transfer search for same-currency movements.
     *
     * Transfer orders represent cross-exchange movements of the same currency,
     * where the rate is 1:1 but fees may apply.
     */
    private function executeTransferSearch(
        Graph $graph,
        string $currency,
        Money $spendAmount,
    ): ExecutionPlanSearchOutcome {
        $portfolio = PortfolioState::initial($spendAmount);
        $guards = new SearchGuards($this->maxExpansions, $this->timeBudgetMs);
        $visitedStates = 1;
        $visitedGuardReached = false;
        $exitedDueToGuards = false;

        /** @var list<array{order: Order, spend: Money, sequence: int}> $rawFills */
        $rawFills = [];
        $sequenceNumber = 1;

        // Look for transfer orders (self-loop edges) at the currency
        $node = $graph->node($currency);
        if (null === $node) {
            return ExecutionPlanSearchOutcome::empty(
                SearchGuardReport::idle($this->maxVisitedStates, $this->maxExpansions, $this->timeBudgetMs)
            );
        }

        // Find all transfer edges (self-loops)
        $transferEdges = [];
        foreach ($node->edges() as $edge) {
            if ($edge->to() === $currency && $edge->order()->isTransfer()) {
                $transferEdges[] = $edge;
            }
        }

        if ([] === $transferEdges) {
            // No transfer orders available
            return ExecutionPlanSearchOutcome::empty(
                SearchGuardReport::idle($this->maxVisitedStates, $this->maxExpansions, $this->timeBudgetMs)
            );
        }

        // Execute transfers in sequence (sorted by cost - cheapest first)
        while ($portfolio->hasBalance($currency)) {
            if (!$guards->canExpand()) {
                $exitedDueToGuards = true;
                break;
            }

            if ($visitedStates >= $this->maxVisitedStates) {
                $visitedGuardReached = true;
                $exitedDueToGuards = true;
                break;
            }

            $guards->recordExpansion();
            ++$visitedStates;

            // Find best unused transfer edge
            $bestEdge = null;
            $bestCost = null;

            foreach ($transferEdges as $edge) {
                if ($portfolio->hasUsedOrder($edge->order())) {
                    continue;
                }

                $edgeCost = $this->calculateEdgeCost($edge);
                if (null === $edgeCost) {
                    continue;
                }

                if (null === $bestCost || $edgeCost->isGreaterThan($bestCost)) {
                    $bestCost = $edgeCost;
                    $bestEdge = $edge;
                }
            }

            if (null === $bestEdge) {
                // No more unused transfer orders - natural completion
                break;
            }

            // Calculate bottleneck for single-edge path
            $bottleneck = $this->calculateBottleneck([$bestEdge], $portfolio, $currency);

            if ($bottleneck->isZero()) {
                $portfolio = $portfolio->markOrderUsed($bestEdge->order());
                continue;
            }

            // Execute the transfer and collect raw fills
            $flowResult = $this->executeFlow([$bestEdge], $portfolio, $bottleneck, $currency, $sequenceNumber);
            $portfolio = $flowResult['portfolio'];
            /** @var list<array{order: Order, spend: Money, sequence: int}> $newFills */
            $newFills = $flowResult['fills'];
            $sequenceNumber = $flowResult['sequenceNumber'];

            array_push($rawFills, ...$newFills);
        }

        $guardReport = $guards->finalize($visitedStates, $this->maxVisitedStates, $visitedGuardReached);

        if ([] === $rawFills) {
            return ExecutionPlanSearchOutcome::empty($guardReport);
        }

        // For same-currency transfers, completion semantics differ from cross-currency conversions:
        // - "Complete" means we naturally finished: exhausted all transfer capacity or balance
        // - "Partial" means we stopped early due to guard limits (time/expansion/visited states)
        //
        // Since transfers are self-loops (source === target), checking source=0 && target>0
        // doesn't apply. Instead, we track whether we exited due to guard limits.
        if ($exitedDueToGuards) {
            return ExecutionPlanSearchOutcome::partial($rawFills, $guardReport, $currency, $currency);
        }

        return ExecutionPlanSearchOutcome::complete($rawFills, $guardReport, $currency, $currency);
    }

    /**
     * Checks if there is remaining balance to convert (not yet at target).
     *
     * Returns true if any non-target currency still has positive balance,
     * indicating more conversion work can potentially be done.
     */
    private function hasRemainingBalance(PortfolioState $portfolio, string $targetCurrency): bool
    {
        $nonZeroBalances = $portfolio->nonZeroBalances();

        foreach ($nonZeroBalances as $currency => $balance) {
            if ($currency !== $targetCurrency) {
                return true;
            }
        }

        return false;
    }

    /**
     * Finds the cheapest augmenting path from any currency with balance to the target.
     *
     * Uses Dijkstra's algorithm starting from all currencies with positive balance.
     *
     * @return array{path: list<GraphEdge>, startCurrency: string, cost: BigDecimal}|null
     */
    private function findAugmentingPath(
        PortfolioState $portfolio,
        Graph $graph,
        string $target,
    ): ?array {
        $nonZeroBalances = $portfolio->nonZeroBalances();

        if ([] === $nonZeroBalances) {
            return null;
        }

        // Remove target from starting currencies (we're done if all balance is in target)
        unset($nonZeroBalances[$target]);

        if ([] === $nonZeroBalances) {
            return null;
        }

        // Dijkstra from all currencies with balance
        /** @var array<string, BigDecimal> $dist */
        $dist = [];

        /** @var array<string, array{edge: GraphEdge, prev: string}|null> $prev */
        $prev = [];

        /** @var array<string, string> $startCurrencyMap */
        $startCurrencyMap = [];

        // Priority queue: [cost, currency, insertOrder]
        /** @var \SplPriorityQueue<array{0: int, 1: int}, string> $queue */
        $queue = new \SplPriorityQueue();
        $queue->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);

        $insertOrder = 0;

        // Initialize distances from all source currencies
        foreach (array_keys($nonZeroBalances) as $currency) {
            $dist[$currency] = BigDecimal::one();
            $prev[$currency] = null;
            $startCurrencyMap[$currency] = $currency;

            // Priority is negative cost (SplPriorityQueue is max-heap)
            // Use array [-cost_major, -insertOrder] for tie-breaking
            $queue->insert($currency, [-1, -$insertOrder++]);
        }

        $bestPath = null;
        $bestCost = null;
        $bestStartCurrency = null;

        /** @var array<string, bool> $visited */
        $visited = [];

        while (!$queue->isEmpty()) {
            /** @var array{priority: array{0: int, 1: int}, data: string} $extracted */
            $extracted = $queue->extract();
            $current = $extracted['data'];

            // Skip if already visited (stale entry)
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;

            $currentCost = $dist[$current] ?? null;
            if (null === $currentCost) {
                continue;
            }

            // Found target - reconstruct path
            if ($current === $target) {
                if (null === $bestCost || $currentCost->isLessThan($bestCost)) {
                    $path = $this->reconstructPath($prev, $current);
                    if ([] !== $path) {
                        $bestPath = $path;
                        $bestCost = $currentCost;
                        $bestStartCurrency = $startCurrencyMap[$current] ?? null;
                    }
                }
                // Continue to find potentially better paths
                continue;
            }

            $node = $graph->node($current);
            if (null === $node) {
                continue;
            }

            foreach ($node->edges() as $edge) {
                $nextCurrency = $edge->to();
                $isTransferEdge = $edge->order()->isTransfer();

                // Skip if order already used
                if ($portfolio->hasUsedOrder($edge->order())) {
                    continue;
                }

                // Skip if cannot receive into this currency (backtracking prevention)
                // Transfer edges (self-loops) skip this check since source === target
                if (!$isTransferEdge && !$portfolio->canReceiveInto($nextCurrency)) {
                    continue;
                }

                // Skip if already visited (Dijkstra optimization)
                // Exception: transfer edges (self-loops) can be considered if order is unused
                // since they represent different state transitions (using a different order)
                if (!$isTransferEdge && isset($visited[$nextCurrency])) {
                    continue;
                }

                // For self-loops (transfers), skip if we're already at the target
                // since transfers don't help us reach the target faster
                if ($isTransferEdge && $nextCurrency === $target) {
                    continue;
                }

                // Calculate edge cost (inverse of conversion rate)
                $edgeCost = $this->calculateEdgeCost($edge);
                if (null === $edgeCost || $edgeCost->isLessThanOrEqualTo(BigDecimal::zero())) {
                    continue;
                }

                $newCost = self::scaleDecimal(
                    $currentCost->dividedBy($edgeCost, self::SCALE, RoundingMode::HalfUp),
                    self::SCALE
                );

                $existingCost = $dist[$nextCurrency] ?? null;
                if (null === $existingCost || $newCost->isLessThan($existingCost)) {
                    $dist[$nextCurrency] = $newCost;
                    $prev[$nextCurrency] = ['edge' => $edge, 'prev' => $current];
                    $startCurrencyMap[$nextCurrency] = $startCurrencyMap[$current] ?? $current;

                    // Insert with negative cost for min-heap behavior
                    // Use floor to avoid rounding issues
                    $costForPriority = $newCost->multipliedBy(BigDecimal::of(1000000))
                        ->toScale(0, RoundingMode::Floor);
                    $costInt = $costForPriority->toInt();
                    $queue->insert($nextCurrency, [-$costInt, -$insertOrder++]);
                }
            }
        }

        if (null === $bestPath || null === $bestStartCurrency || null === $bestCost) {
            return null;
        }

        return [
            'path' => $bestPath,
            'startCurrency' => $bestStartCurrency,
            'cost' => $bestCost,
        ];
    }

    /**
     * Reconstructs the path from predecessors.
     *
     * @param array<string, array{edge: GraphEdge, prev: string}|null> $prev
     *
     * @return list<GraphEdge>
     */
    private function reconstructPath(array $prev, string $target): array
    {
        $path = [];
        $current = $target;

        while (array_key_exists($current, $prev)) {
            $entry = $prev[$current];
            if (null === $entry) {
                break;
            }

            $path[] = $entry['edge'];
            $current = $entry['prev'];
        }

        return array_reverse($path);
    }

    /**
     * Calculates the effective conversion rate for an edge.
     *
     * Returns quote/base ratio representing how much quote currency
     * is received per unit of base currency spent.
     */
    private function calculateEdgeCost(GraphEdge $edge): ?BigDecimal
    {
        $baseCapacity = OrderSide::BUY === $edge->orderSide()
            ? $edge->grossBaseCapacity()
            : $edge->baseCapacity();

        $baseMax = $baseCapacity->max()->decimal();
        if ($baseMax->isZero()) {
            return null;
        }

        $quoteCapacity = $edge->quoteCapacity();
        $quoteMax = $quoteCapacity->max()->decimal();

        return $quoteMax->dividedBy($baseMax, self::SCALE, RoundingMode::HalfUp);
    }

    /**
     * Calculates the bottleneck (maximum flow) for a path.
     *
     * The bottleneck is the maximum amount that can be pushed through all edges
     * in the path, limited by:
     * - Available balance at start
     * - Each edge's capacity (based on order side)
     *
     * @param list<GraphEdge> $path
     */
    private function calculateBottleneck(array $path, PortfolioState $portfolio, string $startCurrency): Money
    {
        if ([] === $path) {
            return Money::zero($startCurrency, self::SCALE);
        }

        $balance = $portfolio->balance($startCurrency);

        // Start with minimum of balance and first edge's max capacity
        // Use the correct capacity based on order side:
        // - BUY order: taker spends base → use grossBaseCapacity
        // - SELL order: taker spends quote → use quoteCapacity
        $firstEdge = $path[0];
        $firstCapacity = $this->getSpendCapacity($firstEdge);

        $scale = max($balance->scale(), $firstCapacity->max()->scale());
        $balance = $balance->withScale($scale);
        $firstMax = $firstCapacity->max()->withScale($scale);

        $bottleneck = $balance->greaterThan($firstMax) ? $firstMax : $balance;

        if ($bottleneck->isZero()) {
            return Money::zero($startCurrency, self::SCALE);
        }

        // Propagate through path to find actual bottleneck
        $currentAmount = $bottleneck;

        foreach ($path as $edge) {
            $capacity = $this->getSpendCapacity($edge);

            $edgeScale = max($currentAmount->scale(), $capacity->max()->scale());
            $currentAmount = $currentAmount->withScale($edgeScale);
            $edgeMax = $capacity->max()->withScale($edgeScale);
            $edgeMin = $capacity->min()->withScale($edgeScale);

            // If current amount exceeds edge max, reduce bottleneck proportionally
            if ($currentAmount->greaterThan($edgeMax)) {
                if (!$currentAmount->isZero()) {
                    $ratio = $edgeMax->divide($currentAmount->amount(), $edgeScale);
                    $bottleneck = $bottleneck->multiply($ratio->amount(), $bottleneck->scale());
                }
                $currentAmount = $edgeMax;
            }

            // If current amount is below edge minimum, path is not viable
            if ($currentAmount->lessThan($edgeMin)) {
                return Money::zero($startCurrency, self::SCALE);
            }

            if ($currentAmount->isZero()) {
                return Money::zero($startCurrency, self::SCALE);
            }

            // Calculate received amount for next edge based on order side
            $received = $this->calculateReceivedAmount($edge, $currentAmount);
            $currentAmount = $received;
        }

        return $bottleneck;
    }

    /**
     * Gets the spend capacity for an edge based on order side.
     *
     * - BUY order: taker spends base → use grossBaseCapacity
     * - SELL order: taker spends quote → use quoteCapacity
     */
    private function getSpendCapacity(GraphEdge $edge): EdgeCapacity
    {
        return OrderSide::BUY === $edge->orderSide()
            ? $edge->grossBaseCapacity()
            : $edge->quoteCapacity();
    }

    /**
     * Calculates the received amount for an edge based on order side.
     */
    private function calculateReceivedAmount(GraphEdge $edge, Money $spendAmount): Money
    {
        $order = $edge->order();

        if (OrderSide::BUY === $edge->orderSide()) {
            // BUY order: spend base, receive quote
            $baseAmount = Money::fromString(
                $order->assetPair()->base(),
                $spendAmount->amount(),
                $spendAmount->scale()
            );

            return $order->calculateEffectiveQuoteAmount($baseAmount);
        }

        // SELL order: spend quote, receive base
        // Convert quote amount to base amount using direct division (preserves precision)
        // Using direct division instead of rate inversion avoids precision loss
        $rate = $order->effectiveRate();

        // Create quote money
        $quoteAmount = Money::fromString(
            $order->assetPair()->quote(),
            $spendAmount->amount(),
            $spendAmount->scale()
        );

        // Direct division: base_amount = quote_amount / rate
        $receivedDecimal = $quoteAmount->decimal()->dividedBy($rate->decimal(), self::SCALE, RoundingMode::HalfUp);
        $baseAmount = Money::fromString(
            $order->assetPair()->base(),
            self::decimalToString($receivedDecimal, self::SCALE),
            self::SCALE
        );

        // Apply fee deduction if present (fees are subtracted from received amount)
        $feePolicy = $order->feePolicy();
        if (null !== $feePolicy) {
            // For SELL orders, we need to check if there's a base fee that should be deducted
            // from the received base amount
            $quoteForFee = $rate->convert(
                Money::fromString($order->assetPair()->base(), $baseAmount->amount(), $baseAmount->scale()),
                self::SCALE
            );
            $feeBreakdown = $feePolicy->calculate($order->side(), $baseAmount, $quoteForFee);
            $baseFee = $feeBreakdown->baseFee();

            if (null !== $baseFee && !$baseFee->isZero()) {
                $baseAmount = $baseAmount->subtract($baseFee);
            }
        }

        return $baseAmount;
    }

    /**
     * Executes flow along a path, collecting raw order fills.
     *
     * Returns raw fill data (order, spend amount, sequence) that can be materialized
     * into ExecutionStep objects by the ExecutionPlanMaterializer.
     *
     * @param list<GraphEdge> $path
     *
     * @return array{portfolio: PortfolioState, fills: list<array{order: Order, spend: Money, sequence: int}>, sequenceNumber: int}
     */
    private function executeFlow(
        array $path,
        PortfolioState $portfolio,
        Money $bottleneck,
        string $startCurrency,
        int $sequenceNumber,
    ): array {
        /** @var list<array{order: Order, spend: Money, sequence: int}> $fills */
        $fills = [];
        $currentAmount = $bottleneck;

        foreach ($path as $edge) {
            $order = $edge->order();
            $isBuy = OrderSide::BUY === $edge->orderSide();

            // Get the correct spend currency based on order side
            $spendCurrency = $isBuy ? $order->assetPair()->base() : $order->assetPair()->quote();

            // Convert current amount to spend currency for order execution
            $spendAmount = Money::fromString($spendCurrency, $currentAmount->amount(), $currentAmount->scale());

            // Clamp to capacity bounds (use correct capacity based on order side)
            $capacity = $this->getSpendCapacity($edge);
            $boundsScale = max($spendAmount->scale(), $capacity->max()->scale());
            $spendAmount = $spendAmount->withScale($boundsScale);
            $boundsMax = $capacity->max()->withScale($boundsScale);
            $boundsMin = $capacity->min()->withScale($boundsScale);

            if ($spendAmount->greaterThan($boundsMax)) {
                $spendAmount = $boundsMax;
            }

            if ($spendAmount->lessThan($boundsMin)) {
                // Use minimum if below
                $spendAmount = $boundsMin;
            }

            // Calculate received amount based on order side (needed for portfolio update)
            $received = $this->calculateReceivedAmount($edge, $spendAmount);

            // Record raw fill data - materialization will happen in the service layer
            $fills[] = [
                'order' => $order,
                'spend' => Money::fromString($spendCurrency, $spendAmount->amount(), $spendAmount->scale()),
                'sequence' => $sequenceNumber++,
            ];

            // Calculate cost for portfolio update
            $cost = $this->calculateStepCost($spendAmount, $received);

            // Update portfolio using order-side-aware method
            $stepSpend = Money::fromString($edge->from(), $spendAmount->amount(), $spendAmount->scale());
            $stepReceived = Money::fromString($edge->to(), $received->amount(), $received->scale());
            $portfolio = $portfolio->executeOrderWithSide($order, $edge->orderSide(), $stepSpend, $stepReceived, $cost);

            // Set up for next edge
            $currentAmount = $received;
        }

        return [
            'portfolio' => $portfolio,
            'fills' => $fills,
            'sequenceNumber' => $sequenceNumber,
        ];
    }

    /**
     * Calculates the cost ratio for a step (spent / received).
     *
     * Lower cost is better. Returns a very high cost for zero-received (infeasible) steps.
     */
    private function calculateStepCost(Money $spent, Money $received): BigDecimal
    {
        if ($received->isZero()) {
            // Infeasible step - return maximum cost to discourage this path
            return BigDecimal::of('999999999999');
        }

        return self::scaleDecimal(
            $spent->decimal()->dividedBy($received->decimal(), self::SCALE, RoundingMode::HalfUp),
            self::SCALE
        );
    }
}
