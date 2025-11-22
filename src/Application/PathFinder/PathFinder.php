<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use SomeWork\P2PPathFinder\Application\Graph\EdgeSegmentCollection;
use SomeWork\P2PPathFinder\Application\Graph\Graph;
use SomeWork\P2PPathFinder\Application\Graph\GraphEdge;
use SomeWork\P2PPathFinder\Application\PathFinder\Guard\SearchGuards;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Heap\CandidateHeapEntry;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Heap\CandidatePriority;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\CostHopsSignatureOrderingStrategy;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathCost;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathOrderKey;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathOrderStrategy;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\RouteSignature;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\PathResultSet;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\PathResultSetEntry;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\SearchGuardReport;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\SearchOutcome;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\InsertionOrderCounter;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchBootstrap;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchQueueEntry;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchState;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchStatePriority;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchStateRecord;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchStateRegistry;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchStateSignature;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\CandidatePath;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\PathEdge;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\PathEdgeSequence;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\SpendConstraints;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\SpendRange;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\DecimalHelperTrait;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\GuardLimitExceeded;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

use function sprintf;
use function strtoupper;

/**
 * Best-first search algorithm for finding optimal trading paths in a currency exchange graph.
 *
 * ## Algorithm Overview
 *
 * PathFinder implements a **best-first search** (priority queue) with:
 * - **Tolerance-based pruning**: Dynamically prunes states worse than best known cost
 * - **Dominance filtering**: Tracks best states per node to eliminate redundant exploration
 * - **Cycle prevention**: Per-path visited tracking prevents revisiting nodes
 * - **Guard rails**: Configurable limits prevent runaway exploration
 *
 * ## Core Algorithm
 *
 * ```
 * 1. Initialize priority queue with source state (cost=1.0, hops=0)
 * 2. While queue not empty AND guards allow:
 *    a. Extract lowest-cost state from queue
 *    b. If state at target: invoke callback, record if accepted
 *    c. If state hops >= maxHops: skip (depth limit)
 *    d. For each outgoing edge:
 *       - Skip if already visited in this path (cycle prevention)
 *       - Calculate next cost via conversion rate
 *       - Check if dominated by existing state at next node
 *       - Check if exceeds tolerance window
 *       - Propagate spend constraints
 *       - Add to queue with updated priority
 * 3. Return top-K results ordered by cost, hops, signature
 * ```
 *
 * ## Tolerance and Pruning
 *
 * **Tolerance Parameter**: `0 ≤ tolerance < 1`
 *
 * Tolerance allows exploring paths slightly worse than the best known path.
 *
 * **Amplifier Formula**:
 * ```
 * amplifier = 1 / (1 - tolerance)
 * ```
 *
 * **Pruning Rule**:
 * ```
 * if (nextCost > bestTargetCost × amplifier) {
 *     prune state  // Too expensive
 * }
 * ```
 *
 * **Examples**:
 * - `tolerance = 0.0` → amplifier = 1.0 (no degradation allowed)
 * - `tolerance = 0.1` → amplifier ≈ 1.111 (allow 11.1% worse)
 * - `tolerance = 0.2` → amplifier = 1.25 (allow 25% worse)
 * - `tolerance = 0.5` → amplifier = 2.0 (allow 100% worse)
 *
 * ## Hop Enforcement
 *
 * **Search-level**: States with `hops >= maxHops` are not expanded
 *
 * **Purpose**: Limit exploration depth, prevent excessively long paths
 *
 * **Guarantee**: All returned paths have `hops ≤ maxHops`
 *
 * ## Visited State Tracking
 *
 * **Two-tier system**:
 *
 * 1. **Per-path tracking** (cycle prevention):
 *    - Each state tracks nodes visited in its path
 *    - Prevents revisiting nodes within same path
 *    - Prevents cycles (A → B → C → A)
 *
 * 2. **Global registry** (dominance filtering):
 *    - Tracks best (cost, hops, signature) per node across all paths
 *    - Prunes dominated states
 *    - Reduces redundant exploration
 *
 * **Dominance**: State A dominates B if `A.cost ≤ B.cost` AND `A.hops ≤ B.hops`
 *
 * ## Search Guards
 *
 * **Three guard mechanisms** prevent runaway exploration:
 *
 * 1. **Expansion limit** (`maxExpansions`):
 *    - Limits number of states extracted from queue
 *    - Each queue extraction = 1 expansion
 *
 * 2. **Visited state limit** (`maxVisitedStates`):
 *    - Limits unique (node, signature) pairs tracked
 *    - Prevents memory exhaustion
 *
 * 3. **Time budget** (`timeBudgetMs`):
 *    - Wall-clock time limit in milliseconds
 *    - Includes all search operations
 *
 * **Breach Behavior**: When limit reached, search terminates and returns partial results
 *
 * ## Ordering and Determinism
 *
 * **Priority Queue Ordering** (lower = higher priority):
 * 1. Cost (ascending) - lower cost preferred
 * 2. Hops (ascending) - fewer hops preferred
 * 3. Route signature (lexicographic) - deterministic tie-break
 * 4. Insertion order (ascending) - stable sort
 *
 * **Result Ordering** (via `PathOrderStrategy`):
 * - Default: `CostHopsSignatureOrderingStrategy`
 * - Same criteria as queue but applied to final results
 * - **Deterministic**: Same inputs always produce same order
 *
 * ## Spend Constraints Propagation
 *
 * **If spend constraints provided**:
 * - Each state carries a `SpendRange` (min, max, desired)
 * - Range updated when traversing edges (currency conversion)
 * - Invalid ranges cause state pruning
 * - Target paths have accurate spend range for materialization
 *
 * ## Acceptance Callback
 *
 * **Optional callback** filters candidate paths:
 * - Invoked when state reaches target (before result recording)
 * - Returns `true` (accept), `false` (reject), or `null` (accept all)
 * - Rejection doesn't affect tolerance pruning
 * - Exceptions propagate to caller
 *
 * ## Complexity
 *
 * **Time Complexity** (worst case):
 * ```
 * O(V × E × log(V × E))
 * ```
 * Where:
 * - V = number of nodes (currencies)
 * - E = number of edges (orders)
 * - log factor from priority queue operations
 *
 * **Space Complexity**:
 * ```
 * O(V × S + K)
 * ```
 * Where:
 * - V = number of nodes
 * - S = number of unique signatures per node (typically small)
 * - K = topK result limit
 *
 * **Practical Performance** (from tests):
 * - Small graphs (5-10 nodes): < 10ms
 * - Medium graphs (10-20 nodes): < 50ms
 * - Complete graphs hit guards quickly (by design)
 *
 * ## Guarantees
 *
 * **Correctness**:
 * ✅ Finds optimal path if one exists (within tolerance)
 * ✅ Returned paths are valid (reachable, acyclic)
 * ✅ All paths respect `maxHops` limit
 * ✅ Result ordering is deterministic
 * ✅ Guards prevent runaway exploration
 *
 * **Properties**:
 * ✅ **Completeness**: Explores all reachable states (within guards)
 * ✅ **Optimality**: Best-first guarantees optimal exploration order
 * ✅ **Determinism**: Same inputs → same outputs
 * ✅ **Bounded**: Guards ensure finite termination
 *
 * **No Guarantees**:
 * ⚠️ Path materialization success (handled by PathFinderService)
 * ⚠️ Minimum hops (enforced at service level)
 * ⚠️ Real-time order availability (graph is snapshot)
 *
 * ## Limitations
 *
 * **Known Limitations**:
 * - No dynamic graph updates (snapshot-based)
 * - No concurrent search support
 * - No distributed search
 * - Time budget is wall-clock (system-dependent)
 *
 * **Recommended Limits**:
 * - `maxHops`: 3-5 (typical trading paths)
 * - `tolerance`: 0.0-0.2 (0-20% degradation)
 * - `maxExpansions`: 1000-10000 (prevents long searches)
 * - `maxVisitedStates`: 1000-5000 (memory bound)
 * - `timeBudgetMs`: 100-1000ms (responsiveness)
 *
 * ## Usage Example
 *
 * ```php
 * $pathFinder = new PathFinder(
 *     maxHops: 4,              // Max 4 hops
 *     tolerance: '0.1',        // Allow 10% worse paths
 *     topK: 5,                 // Return top 5 paths
 *     maxExpansions: 5000,     // Limit state expansions
 *     maxVisitedStates: 2000,  // Limit visited states
 *     timeBudgetMs: 500,       // 500ms time budget
 * );
 *
 * $result = $pathFinder->findBestPaths(
 *     $graph,
 *     'USD',                   // Source currency
 *     'EUR',                   // Target currency
 *     $spendConstraints,       // Optional spend bounds
 *     fn($path) => $path->hops() >= 2  // Optional filter
 * );
 *
 * foreach ($result->paths() as $path) {
 *     echo "Cost: {$path->cost()}, Hops: {$path->hops()}\n";
 * }
 *
 * if ($result->guardLimits()->anyLimitReached()) {
 *     echo "Search hit guard limits (partial results)\n";
 * }
 * ```
 *
 * @internal
 */
final class PathFinder
{
    use DecimalHelperTrait;
    /**
     * Canonical tolerance, cost and residual scale documented in docs/decimal-strategy.md.
     *
     * @see DecimalHelperTrait::CANONICAL_SCALE
     */
    private const SCALE = self::CANONICAL_SCALE;
    /**
     * Extra precision used when converting target and source deltas into a ratio to avoid premature rounding.
     */
    private const RATIO_EXTRA_SCALE = 4;
    /**
     * Extra precision used when applying the ratio to offsets before normalizing to the target scale.
     */
    private const SUM_EXTRA_SCALE = 2;

    public const DEFAULT_MAX_EXPANSIONS = 250000;

    public const DEFAULT_MAX_VISITED_STATES = 250000;

    private readonly BigDecimal $unitValue;
    private readonly BigDecimal $toleranceUpperBound;
    private readonly BigDecimal $tolerance;
    private readonly BigDecimal $toleranceAmplifier;
    private readonly PathOrderStrategy $orderingStrategy;

    /**
     * @param int    $maxHops   maximum number of edges a path may contain
     * @param string $tolerance value in the [0, 1) range representing the acceptable degradation of the best product
     *
     * @throws InvalidInput|PrecisionViolation when configuration parameters are invalid or tolerance normalization fails
     */
    public function __construct(
        private readonly int $maxHops = 4,
        string $tolerance = '0',
        private readonly int $topK = 1,
        private readonly int $maxExpansions = self::DEFAULT_MAX_EXPANSIONS,
        private readonly int $maxVisitedStates = self::DEFAULT_MAX_VISITED_STATES,
        ?PathOrderStrategy $orderingStrategy = null,
        private readonly ?int $timeBudgetMs = null,
    ) {
        if ($maxHops < 1) {
            throw new InvalidInput('Maximum hops must be at least one.');
        }

        if ($this->topK < 1) {
            throw new InvalidInput('Result limit must be at least one.');
        }

        if ($this->maxExpansions < 1) {
            throw new InvalidInput('Maximum expansions must be at least one.');
        }

        if ($this->maxVisitedStates < 1) {
            throw new InvalidInput('Maximum visited states must be at least one.');
        }

        if (null !== $this->timeBudgetMs && $this->timeBudgetMs < 1) {
            throw new InvalidInput('Time budget must be at least one millisecond.');
        }

        $this->unitValue = self::scaleDecimal(BigDecimal::one(), self::SCALE);
        // Compute tolerance upper bound as 0.999...9 (SCALE nines after decimal point)
        // This is 1 - (1 / 10^SCALE), which represents the highest tolerance < 1 at this scale
        $epsilon = BigDecimal::one()->dividedBy(
            BigDecimal::of(10)->power(self::SCALE),
            self::SCALE,
            RoundingMode::HALF_UP
        );
        $this->toleranceUpperBound = $this->unitValue->minus($epsilon);

        $this->tolerance = $this->normalizeTolerance($tolerance);
        $this->toleranceAmplifier = $this->calculateToleranceAmplifier($this->tolerance);
        $this->orderingStrategy = $orderingStrategy ?? new CostHopsSignatureOrderingStrategy(self::SCALE);
    }

    /**
     * Searches for the best paths from source to target with optional acceptance filtering.
     *
     * @param Graph                             $graph            The trading graph to search
     * @param string                            $source           Source currency code
     * @param string                            $target           Target currency code
     * @param SpendConstraints|null             $spendConstraints Optional spend amount constraints
     * @param callable(CandidatePath):bool|null $acceptCandidate  Optional callback to filter candidate paths
     *
     * ## Acceptance Callback Contract
     *
     * The callback is invoked when a path reaches the target node, **before** it's added to results.
     *
     * **Signature**: `callable(CandidatePath):bool`
     *
     * **Return values**:
     * - `true`: Accept path (add to results)
     * - `false`: Reject path (continue search for alternatives)
     * - `null` callback: Accept all paths (default behavior)
     *
     * **Guarantees when callback is invoked**:
     * - Candidate has valid structure (cost, product, hops, edges, range)
     * - Path reaches target node
     * - Path hops ≤ maxHops
     *
     * **Timing**: Called AFTER path construction, BEFORE result recording, BEFORE tolerance pruning update
     *
     * **Error handling**: Callback exceptions propagate to caller (no exception wrapper)
     *
     * **Side effects**: Callback may have side effects but must NOT modify graph or candidate
     *
     * **Search continuation**: Search always continues after callback, regardless of return value
     *
     * @throws GuardLimitExceeded              when a configured guard limit is exceeded during search
     * @throws InvalidInput|PrecisionViolation when path construction or arithmetic operations fail
     *
     * @return SearchOutcome<CandidatePath>
     *
     * @phpstan-return SearchOutcome<CandidatePath>
     *
     * @psalm-return SearchOutcome<CandidatePath>
     */
    public function findBestPaths(
        Graph $graph,
        string $source,
        string $target,
        ?SpendConstraints $spendConstraints = null,
        ?callable $acceptCandidate = null
    ): SearchOutcome {
        $source = strtoupper($source);
        $target = strtoupper($target);

        if (!$graph->hasNode($source) || !$graph->hasNode($target)) {
            /** @var SearchOutcome<CandidatePath> $empty */
            $empty = SearchOutcome::empty(SearchGuardReport::idle($this->maxVisitedStates, $this->maxExpansions, $this->timeBudgetMs));

            return $empty;
        }

        $range = null;
        $desiredSpend = null;
        if (null !== $spendConstraints) {
            $range = $spendConstraints->internalRange();
            $desiredSpend = $spendConstraints->desired();
        }

        $bootstrap = $this->initializeSearchStructures($source, $range, $desiredSpend);
        $queue = $bootstrap->queue();
        $results = $bootstrap->results();
        $bestPerNode = $bootstrap->registry();
        $insertionOrder = $bootstrap->insertionOrder();
        $resultInsertionOrder = $bootstrap->resultInsertionOrder();
        $visitedStates = $bootstrap->visitedStates();

        /** @var BigDecimal|null $bestTargetCost */
        $bestTargetCost = null;

        $guards = new SearchGuards($this->maxExpansions, $this->timeBudgetMs);
        $visitedGuardReached = false;

        while (!$queue->isEmpty()) {
            if (!$guards->canExpand()) {
                break;
            }

            $state = $queue->extract();
            $guards->recordExpansion();

            if ($state->node() === $target) {
                $candidateCostDecimal = $state->costDecimal();
                $candidateProductDecimal = $state->productDecimal();

                $candidateRange = null;
                $stateRange = $state->amountRange();
                if (null !== $stateRange) {
                    $candidateRange = SpendConstraints::from(
                        $stateRange->min(),
                        $stateRange->max(),
                        $state->desiredAmount(),
                    );
                }

                $candidate = CandidatePath::from(
                    $candidateCostDecimal,
                    $candidateProductDecimal,
                    $state->path()->count(),
                    $state->path(),
                    $candidateRange,
                );

                if (null === $acceptCandidate || $acceptCandidate($candidate)) {
                    if (null === $bestTargetCost || $candidateCostDecimal->isLessThan($bestTargetCost)) {
                        $bestTargetCost = $candidateCostDecimal;
                    }

                    $this->recordResult($results, $candidate, $resultInsertionOrder->next());
                }

                continue;
            }

            if ($state->hops() >= $this->maxHops) {
                continue;
            }

            $currentNode = $graph->node($state->node());
            if (null === $currentNode) {
                continue;
            }

            foreach ($currentNode->edges() as $edge) {
                $nextNode = $edge->to();
                if (!$graph->hasNode($nextNode)) {
                    continue;
                }

                if ($state->hasVisited($nextNode)) {
                    continue;
                }

                $conversionRate = $this->edgeEffectiveConversionRate($edge);
                if (!$conversionRate->isGreaterThan(BigDecimal::zero())) {
                    continue;
                }

                $currentRange = $state->amountRange();
                $currentDesired = $state->desiredAmount();
                if (null !== $currentRange) {
                    $feasibleRange = $this->edgeSupportsAmount($edge, $currentRange);
                    if (null === $feasibleRange) {
                        continue;
                    }

                    $nextRange = $this->calculateNextRange($edge, $feasibleRange);
                    $nextDesired = null;

                    if ($currentDesired instanceof Money) {
                        $clamped = $this->clampToRange($currentDesired, $feasibleRange);
                        $nextDesired = $this->convertEdgeAmount($edge, $clamped);
                    }
                } else {
                    $nextRange = null;
                    $nextDesired = $currentDesired instanceof Money
                        ? $this->convertEdgeAmount($edge, $currentDesired)
                        : null;
                }

                $nextCostDecimal = $this->calculateNextCostDecimal($state->costDecimal(), $conversionRate);
                $nextProductDecimal = $this->calculateNextProductDecimal($state->productDecimal(), $conversionRate);
                $nextHops = $state->hops() + 1;

                $signature = $this->stateSignature($nextRange, $nextDesired);
                $candidateRecord = new SearchStateRecord($nextCostDecimal, $nextHops, $signature);

                if ($bestPerNode->isDominated($nextNode, $candidateRecord, self::SCALE)) {
                    continue;
                }

                if (
                    $visitedStates >= $this->maxVisitedStates
                    && !$bestPerNode->hasSignature($nextNode, $signature)
                ) {
                    $visitedGuardReached = true;
                    continue;
                }

                $maxAllowedCost = $this->maxAllowedCost($bestTargetCost);
                if (null !== $maxAllowedCost && $nextCostDecimal->isGreaterThan($maxAllowedCost)) {
                    continue;
                }

                $nextState = $state->transition(
                    $nextNode,
                    $nextCostDecimal,
                    $nextProductDecimal,
                    PathEdge::fromGraphEdge($edge, $conversionRate),
                    $nextRange,
                    $nextDesired,
                );

                [$bestPerNode, $delta] = $bestPerNode->register($nextNode, $candidateRecord, self::SCALE);
                $visitedStates = max(0, $visitedStates + $delta);

                $queue->push(
                    new SearchQueueEntry(
                        $nextState,
                        new SearchStatePriority(
                            new PathCost($nextCostDecimal),
                            $nextState->hops(),
                            $this->routeSignature($nextState->path()),
                            $insertionOrder->next(),
                        ),
                    ),
                );
            }
        }

        $guardLimits = $guards->finalize($visitedStates, $this->maxVisitedStates, $visitedGuardReached);

        if (0 === $results->count()) {
            /** @var SearchOutcome<CandidatePath> $empty */
            $empty = SearchOutcome::empty($guardLimits);

            return $empty;
        }

        $finalized = $this->finalizeResults($results);

        return new SearchOutcome($finalized, $guardLimits);
    }

    private function stateSignature(?SpendRange $range, ?Money $desired): SearchStateSignature
    {
        if (null === $range) {
            return SearchStateSignature::compose([
                'range' => 'null',
                'desired' => $this->moneySignature($desired),
            ]);
        }

        $scale = $range->scale();
        if ($desired instanceof Money) {
            $scale = max($scale, $desired->scale());
        }

        $normalizedRange = $range->withScale($scale);
        $minimum = $normalizedRange->min();
        $maximum = $normalizedRange->max();

        $rangeSignature = sprintf(
            '%s:%s:%s:%d',
            $minimum->currency(),
            $this->moneySignatureAmount($minimum, $scale),
            $this->moneySignatureAmount($maximum, $scale),
            $scale,
        );

        return SearchStateSignature::compose([
            'range' => $rangeSignature,
            'desired' => $this->moneySignature($desired, $scale),
        ]);
    }

    private function moneySignature(?Money $amount, ?int $scale = null): string
    {
        if (null === $amount) {
            return 'null';
        }

        $scale ??= $amount->scale();

        return sprintf(
            '%s:%s:%d',
            $amount->currency(),
            $this->moneySignatureAmount($amount, $scale),
            $scale,
        );
    }

    /**
     * @return numeric-string
     */
    private function moneySignatureAmount(Money $amount, int $scale): string
    {
        // moneyToDecimal already scales to the requested scale via withScale(),
        // so we can directly convert to string without re-scaling
        $decimal = $this->moneyToDecimal($amount, $scale);
        /** @var numeric-string $result */
        $result = $decimal->__toString();

        return $result;
    }

    private function createQueue(): SearchStateQueue
    {
        return new SearchStateQueue(self::SCALE);
    }

    private function createResultHeap(): CandidateResultHeap
    {
        return new CandidateResultHeap(self::SCALE);
    }

    private function recordResult(CandidateResultHeap $results, CandidatePath $candidate, int $order): void
    {
        $entry = new CandidateHeapEntry(
            $candidate,
            new CandidatePriority(
                new PathCost($candidate->costDecimal()),
                $candidate->hops(),
                $this->routeSignature($candidate->edges()),
                $order,
            ),
        );

        $results->push($entry);

        if ($results->count() > $this->topK) {
            $results->extract();
        }
    }

    private function calculateNextCostDecimal(BigDecimal $currentCost, BigDecimal $conversionRate): BigDecimal
    {
        // dividedBy already produces a value at self::SCALE, no need to normalize again
        return $currentCost->dividedBy($conversionRate, self::SCALE, RoundingMode::HALF_UP);
    }

    private function calculateNextProductDecimal(BigDecimal $currentProduct, BigDecimal $conversionRate): BigDecimal
    {
        return $this->normalizeDecimal($currentProduct->multipliedBy($conversionRate));
    }

    /**
     * Calculates the maximum allowed cost for pruning based on tolerance.
     *
     * This method determines the cost threshold above which states will be pruned
     * from the search. States with `cost > maxAllowedCost` are not explored.
     *
     * **Logic**:
     * - If no best cost known yet: return null (no pruning)
     * - If tolerance = 0: maxAllowedCost = bestTargetCost (exact matching)
     * - If tolerance > 0: maxAllowedCost = bestTargetCost × amplifier
     *
     * **Example**:
     * ```
     * bestTargetCost = 100
     * tolerance = 0.2 → amplifier = 1.25
     * maxAllowedCost = 100 × 1.25 = 125
     * → Paths with cost ≤ 125 are explored, paths with cost > 125 are pruned
     * ```
     *
     * @param BigDecimal|null $bestTargetCost The best (lowest) cost found so far, or null if none yet
     *
     * @return BigDecimal|null Maximum allowed cost, or null if no best cost known
     */
    private function maxAllowedCost(?BigDecimal $bestTargetCost): ?BigDecimal
    {
        // No best cost known yet - don't prune anything
        if (null === $bestTargetCost) {
            return null;
        }

        // bestTargetCost is already normalized when set

        // Zero tolerance: only allow paths with cost ≤ bestTargetCost (no amplification)
        if (!$this->hasTolerance()) {
            return $bestTargetCost;
        }

        // Non-zero tolerance: allow paths with cost ≤ (bestTargetCost × amplifier)
        return $this->normalizeDecimal($bestTargetCost->multipliedBy($this->toleranceAmplifier));
    }

    private function normalizeDecimal(BigDecimal $value): BigDecimal
    {
        return self::scaleDecimal($value, self::SCALE);
    }

    private function initializeSearchStructures(string $source, ?SpendRange $range, ?Money $desiredSpend): SearchBootstrap
    {
        $queue = $this->createQueue();
        $results = $this->createResultHeap();
        $insertionOrder = new InsertionOrderCounter();
        $resultInsertionOrder = new InsertionOrderCounter();

        $unitDecimal = $this->unitValue;
        $initialState = SearchState::bootstrap($source, $unitDecimal, $range, $desiredSpend);
        $queue->push(
            new SearchQueueEntry(
                $initialState,
                new SearchStatePriority(
                    new PathCost($unitDecimal),
                    $initialState->hops(),
                    $this->routeSignature($initialState->path()),
                    $insertionOrder->next(),
                ),
            ),
        );

        $initialRecord = new SearchStateRecord(
            $unitDecimal,
            0,
            $this->stateSignature($range, $desiredSpend),
        );

        $bestPerNode = SearchStateRegistry::withInitial($source, $initialRecord);

        return new SearchBootstrap($queue, $results, $bestPerNode, $insertionOrder, $resultInsertionOrder, 1);
    }

    /**
     * @return PathResultSet<CandidatePath>
     */
    private function finalizeResults(CandidateResultHeap $results): PathResultSet
    {
        /** @var list<PathResultSetEntry<CandidatePath>> $entries */
        $entries = $this->collectResultEntries($results);

        /** @var PathResultSet<CandidatePath> $ordered */
        $ordered = PathResultSet::fromEntries($this->orderingStrategy, $entries);

        return $ordered;
    }

    /**
     * @return list<PathResultSetEntry<CandidatePath>>
     */
    private function collectResultEntries(CandidateResultHeap $results): array
    {
        /** @var list<PathResultSetEntry<CandidatePath>> $collected */
        $collected = [];
        $clone = clone $results;

        while (!$clone->isEmpty()) {
            $entry = $clone->extract();
            $priority = $entry->priority();
            /** @var PathResultSetEntry<CandidatePath> $resultEntry */
            $resultEntry = new PathResultSetEntry(
                $entry->candidate(),
                new PathOrderKey(
                    $priority->cost(),
                    $priority->hops(),
                    $priority->routeSignature(),
                    $priority->order(),
                ),
            );

            $collected[] = $resultEntry;
        }

        return $collected;
    }

    private function routeSignature(PathEdgeSequence $edges): RouteSignature
    {
        return RouteSignature::fromPathEdgeSequence($edges);
    }

    /**
     * Determines if an edge can accommodate the requested spend range.
     *
     * This method intersects the requested spend range with the edge's available capacity,
     * considering mandatory segments (order minimums). If there's no overlap, the edge
     * cannot satisfy the constraints and should be pruned.
     *
     * ## Intersection Logic
     *
     * - **No overlap**: Returns null (prune edge)
     *   - `requestedMax < capacityMin` (requested range entirely below capacity)
     *   - `requestedMin > capacityMax` (requested range entirely above capacity)
     *
     * - **Partial/full overlap**: Returns intersection range
     *   - `lowerBound = max(requestedMin, capacityMin)`
     *   - `upperBound = min(requestedMax, capacityMax)`
     *
     * ## Mandatory Segments
     *
     * When segments exist, uses `mandatory` capacity as the minimum bound (not raw capacity.min).
     * This ensures paths respect order minimums due to fees.
     *
     * @param GraphEdge  $edge  The edge to check
     * @param SpendRange $range The requested spend range
     *
     * @return SpendRange|null Feasible intersection range, or null if edge cannot satisfy constraints
     */
    private function edgeSupportsAmount(GraphEdge $edge, SpendRange $range): ?SpendRange
    {
        $isBuy = OrderSide::BUY === $edge->orderSide();
        $capacity = $isBuy ? $edge->grossBaseCapacity() : $edge->quoteCapacity();
        $measure = $isBuy
            ? EdgeSegmentCollection::MEASURE_GROSS_BASE
            : EdgeSegmentCollection::MEASURE_QUOTE;
        $segments = $edge->segmentCollection();

        $scale = max(
            $range->scale(),
            $capacity->min()->scale(),
            $capacity->max()->scale(),
        );

        $totals = $segments->capacityTotals($measure, $scale);
        if (null !== $totals) {
            $scale = max($scale, $totals->scale());
        }

        $requestedRange = $range->withScale($scale);
        if (null === $totals) {
            $capacityRange = SpendRange::fromBounds(
                $capacity->min()->withScale($scale),
                $capacity->max()->withScale($scale),
            );
        } else {
            $capacityRange = SpendRange::fromBounds(
                $totals->mandatory()->withScale($scale),
                $totals->maximum()->withScale($scale),
            );
        }

        $capacityMin = $capacityRange->min();
        $capacityMax = $capacityRange->max();
        $requestedMin = $requestedRange->min();
        $requestedMax = $requestedRange->max();

        if ($capacityMax->decimal()->isZero()) {
            $zero = Money::zero($requestedRange->currency(), $scale);

            if ($capacityMin->greaterThan($zero)) {
                return null;
            }

            if ($requestedMin->compare($zero) > 0 || $requestedMax->compare($zero) < 0) {
                return null;
            }

            return SpendRange::fromBounds($zero, $zero);
        }

        if ($requestedMax->lessThan($capacityMin) || $requestedMin->greaterThan($capacityMax)) {
            return null;
        }

        $lowerBound = $requestedMin->greaterThan($capacityMin) ? $requestedMin : $capacityMin;
        $upperBound = $requestedMax->lessThan($capacityMax) ? $requestedMax : $capacityMax;

        return SpendRange::fromBounds($lowerBound, $upperBound);
    }

    /**
     * Converts a spend range to the target currency of an edge.
     *
     * This propagates spend constraints forward through the path by converting
     * both the minimum and maximum bounds using the edge's conversion rate.
     *
     * ## Conversion
     *
     * - BUY orders: `targetAmount = sourceAmount * rate`
     * - SELL orders: `targetAmount = sourceAmount / rate`
     *
     * Amounts are clamped to edge capacity during conversion to ensure bounds
     * remain within available liquidity.
     *
     * @param GraphEdge  $edge  The edge to traverse
     * @param SpendRange $range The spend range to convert
     *
     * @return SpendRange The converted range in the target currency
     */
    private function calculateNextRange(GraphEdge $edge, SpendRange $range): SpendRange
    {
        $minimum = $this->convertEdgeAmount($edge, $range->min());
        $maximum = $this->convertEdgeAmount($edge, $range->max());

        return SpendRange::fromBounds($minimum, $maximum);
    }

    private function convertEdgeAmount(GraphEdge $edge, Money $current): Money
    {
        $conversionRate = $this->edgeEffectiveConversionRate($edge);
        if (!$conversionRate->isGreaterThan(BigDecimal::zero())) {
            return Money::zero($edge->to(), max($current->scale(), self::SCALE));
        }

        $sourceCapacity = OrderSide::BUY === $edge->orderSide()
            ? $edge->grossBaseCapacity()
            : $edge->quoteCapacity();
        $targetCapacity = OrderSide::BUY === $edge->orderSide()
            ? $edge->quoteCapacity()
            : $edge->baseCapacity();

        $sourceScale = max(
            $sourceCapacity->min()->scale(),
            $sourceCapacity->max()->scale(),
            $current->scale(),
            self::SCALE,
        );
        $targetScale = max(
            $targetCapacity->min()->scale(),
            $targetCapacity->max()->scale(),
            self::SCALE,
        );

        $sourceRange = SpendRange::fromBounds(
            $sourceCapacity->min()->withScale($sourceScale),
            $sourceCapacity->max()->withScale($sourceScale),
        );
        $targetRange = SpendRange::fromBounds(
            $targetCapacity->min()->withScale($targetScale),
            $targetCapacity->max()->withScale($targetScale),
        );

        $clampedCurrent = $this->clampToRange($current->withScale($sourceScale), $sourceRange);

        $ratioScale = max($sourceScale, $targetScale, self::SCALE);
        $sourceMinDecimal = $this->moneyToDecimal($sourceRange->min(), $ratioScale);
        $sourceMaxDecimal = $this->moneyToDecimal($sourceRange->max(), $ratioScale);
        $sourceDeltaDecimal = $sourceMaxDecimal->minus($sourceMinDecimal);
        if ($sourceDeltaDecimal->isZero()) {
            return $targetRange->min()->withScale($targetScale);
        }

        $targetMinDecimal = $this->moneyToDecimal($targetRange->min(), $ratioScale);
        $targetMaxDecimal = $this->moneyToDecimal($targetRange->max(), $ratioScale);
        $targetDeltaDecimal = $targetMaxDecimal->minus($targetMinDecimal);

        $ratio = self::scaleDecimal(
            $targetDeltaDecimal->dividedBy(
                $sourceDeltaDecimal,
                $ratioScale + self::RATIO_EXTRA_SCALE,
                RoundingMode::HALF_UP,
            ),
            $ratioScale + self::RATIO_EXTRA_SCALE,
        );

        $clampedDecimal = $this->moneyToDecimal($clampedCurrent, $ratioScale);
        $offsetDecimal = $clampedDecimal->minus($sourceMinDecimal);
        $increment = self::scaleDecimal(
            $offsetDecimal->multipliedBy($ratio),
            $ratioScale + self::SUM_EXTRA_SCALE,
        );
        $baseDecimal = self::scaleDecimal(
            $targetMinDecimal->plus($increment),
            $ratioScale + self::SUM_EXTRA_SCALE,
        );

        $converted = $this->moneyFromDecimal(
            $edge->to(),
            $baseDecimal,
            $ratioScale + self::SUM_EXTRA_SCALE,
        )->withScale($targetScale);

        return $this->clampToRange($converted, $targetRange);
    }

    /**
     * @throws InvalidInput when currencies mismatch or clamping fails
     */
    private function clampToRange(Money $value, SpendRange $range): Money
    {
        return $range->clamp($value);
    }

    /**
     * Computes the effective conversion rate for an edge.
     *
     * @throws InvalidInput when the edge ratio cannot be computed
     */
    private function edgeEffectiveConversionRate(GraphEdge $edge): BigDecimal
    {
        $baseToQuote = $this->edgeBaseToQuoteRatio($edge);
        if (!$baseToQuote->isGreaterThan(BigDecimal::zero())) {
            return $baseToQuote;
        }

        if (OrderSide::SELL === $edge->orderSide()) {
            // dividedBy already produces a value at self::SCALE, no need to scale again
            return BigDecimal::one()->dividedBy($baseToQuote, self::SCALE, RoundingMode::HALF_UP);
        }

        return $baseToQuote;
    }

    /**
     * Computes the base-to-quote ratio from edge capacities.
     *
     * @throws InvalidInput when the capacity ratios cannot be computed
     */
    private function edgeBaseToQuoteRatio(GraphEdge $edge): BigDecimal
    {
        $baseCapacity = OrderSide::BUY === $edge->orderSide()
            ? $edge->grossBaseCapacity()
            : $edge->baseCapacity();

        $baseScale = max($baseCapacity->min()->scale(), $baseCapacity->max()->scale());
        $quoteCapacity = $edge->quoteCapacity();
        $quoteScale = max($quoteCapacity->min()->scale(), $quoteCapacity->max()->scale());

        $baseMax = $this->moneyToDecimal($baseCapacity->max(), $baseScale);
        if ($baseMax->isZero()) {
            return self::scaleDecimal(BigDecimal::zero(), self::SCALE);
        }

        $quoteMax = $this->moneyToDecimal($quoteCapacity->max(), $quoteScale);

        // dividedBy already produces a value at self::SCALE, no need to scale again
        return $quoteMax->dividedBy($baseMax, self::SCALE, RoundingMode::HALF_UP);
    }

    /**
     * Normalizes and validates a tolerance value.
     *
     * @throws InvalidInput when the tolerance value is malformed or out of range
     */
    private function normalizeTolerance(string $tolerance): BigDecimal
    {
        $decimal = self::decimalFromString($tolerance);

        if ($decimal->isNegative()) {
            throw new InvalidInput('Tolerance must be non-negative.');
        }

        if ($decimal->isGreaterThanOrEqualTo($this->unitValue)) {
            throw new InvalidInput('Tolerance must be less than one.');
        }

        $normalized = self::scaleDecimal($decimal, self::SCALE);
        if ($normalized->isGreaterThan($this->toleranceUpperBound)) {
            return $this->toleranceUpperBound;
        }

        return $normalized;
    }

    /**
     * Calculates the tolerance amplifier used for pruning during search.
     *
     * The amplifier determines how much worse a path's cost can be compared to the
     * best known cost while still being explored.
     *
     * **Formula**: `amplifier = 1 / (1 - tolerance)`
     *
     * **Special Cases**:
     * - If tolerance = 0: amplifier = 1.0 (no amplification, exact matching)
     * - If tolerance approaches 1: amplifier approaches infinity (unlimited exploration)
     *
     * **Mathematical Derivation**:
     *
     * Given a best cost `C_best` and tolerance `t`, we want to allow paths with cost
     * up to `C_max = C_best × amplifier`.
     *
     * The tolerance represents acceptable degradation: `(C_max - C_best) / C_best ≤ t`
     *
     * Solving for amplifier:
     * ```
     * (C_max - C_best) / C_best ≤ t
     * C_max / C_best - 1 ≤ t
     * C_max / C_best ≤ 1 + t
     * amplifier ≤ 1 + t
     * ```
     *
     * However, the actual formula used is `1 / (1 - t)` which provides tighter bounds
     * and better numerical properties, particularly for high tolerance values.
     *
     * @param BigDecimal $tolerance Normalized tolerance value (0 ≤ tolerance < 1)
     *
     * @return BigDecimal Amplifier value at scale 18
     */
    private function calculateToleranceAmplifier(BigDecimal $tolerance): BigDecimal
    {
        // Special case: zero tolerance means no amplification (exact cost matching)
        if ($tolerance->isZero()) {
            return $this->unitValue;
        }

        // Calculate complement: (1 - tolerance)
        // This represents the "strictness" of the tolerance (closer to 0 = more lenient)
        $complement = $this->unitValue->minus($tolerance);

        // Amplifier = 1 / (1 - tolerance)
        // dividedBy already produces a value at self::SCALE, no need to scale again
        return $this->unitValue->dividedBy($complement, self::SCALE, RoundingMode::HALF_UP);
    }

    private function hasTolerance(): bool
    {
        return $this->tolerance->isGreaterThan(BigDecimal::zero());
    }

    private function moneyToDecimal(Money $amount, int $scale): BigDecimal
    {
        return $amount->withScale($scale)->decimal();
    }

    private function moneyFromDecimal(string $currency, BigDecimal $amount, int $scale): Money
    {
        return Money::fromString($currency, self::decimalToString($amount, $scale), $scale);
    }
}
