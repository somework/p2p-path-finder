<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Service;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use SomeWork\P2PPathFinder\Application\PathSearch\Api\Request\PathSearchRequest;
use SomeWork\P2PPathFinder\Application\PathSearch\Api\Response\SearchOutcome;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\ExecutionPlanSearchEngine;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\CostHopsSignatureOrderingStrategy;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathCost;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathOrderKey;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathOrderStrategy;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\RouteSignature;
use SomeWork\P2PPathFinder\Application\PathSearch\Model;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\ExecutionPlan;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathResultSet;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathResultSetEntry;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\SearchGuardReport;
use SomeWork\P2PPathFinder\Application\PathSearch\Support\OrderFillEvaluator;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Tolerance\DecimalTolerance;
use SomeWork\P2PPathFinder\Domain\ValueObject\DecimalHelperTrait;
use SomeWork\P2PPathFinder\Exception\GuardLimitExceeded;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

use function count;
use function implode;
use function sprintf;
use function strtoupper;
use function trim;

/**
 * Service for finding optimal execution plans that may include split/merge routes.
 *
 * This service supports **Top-K execution plan discovery**, returning up to K distinct,
 * ranked execution plans ordered by cost (best first). Use `PathSearchConfig::resultLimit()`
 * to configure K (defaults to 1 for backward compatibility).
 *
 * ## Top-K Modes
 *
 * The service supports two Top-K modes, controlled by `PathSearchConfig::disjointPlans()`:
 *
 * ### Disjoint Mode (default, `disjointPlans=true`)
 *
 * Uses **Iterative Exclusion**:
 * 1. Find the optimal execution plan
 * 2. For each subsequent plan (up to K-1 more):
 *    - Exclude all orders used in previously found plans
 *    - Search for the next best plan using the filtered order set
 *    - Stop if no more plans can be found
 * 3. Return all found plans, ordered by cost (best first)
 *
 * Each returned plan uses a **completely disjoint set of orders** - no order appears
 * in multiple plans. This ensures true alternatives for fallback scenarios.
 *
 * ### Reusable Mode (`disjointPlans=false`)
 *
 * Uses **Penalty-Based Diversification**:
 * 1. Find the optimal execution plan
 * 2. For each subsequent plan:
 *    - Apply capacity penalties to previously used orders
 *    - Search for the next best plan (orders can be reused)
 *    - Skip duplicate plans (same signature or cost)
 *    - Stop if no more unique plans can be found
 * 3. Return all unique plans, ordered by cost (best first)
 *
 * Plans CAN share orders since only one plan will actually execute. This mode is
 * useful for rate comparison scenarios where users want to see more alternatives.
 *
 * ## Capabilities
 *
 * This service can find execution plans that:
 * - Use multiple orders for the same currency direction
 * - Split input across parallel routes
 * - Merge multiple routes at the target currency
 *
 * This service is the recommended public API for complex trading scenarios where
 * a single linear path may not optimally utilize available liquidity.
 *
 * @see PathSearchRequest For request structure
 * @see PathSearchConfig For configuration options (including resultLimit for Top-K)
 * @see SearchOutcome For result structure
 * @see ExecutionPlan For the result type containing execution steps
 * @see docs/getting-started.md For complete usage examples
 *
 * @api
 */
final class ExecutionPlanService
{
    use DecimalHelperTrait;

    /**
     * Scale for cost calculations, matches trait's canonical scale.
     */
    private const COST_SCALE = self::CANONICAL_SCALE;

    private readonly OrderSpendAnalyzer $orderSpendAnalyzer;
    private readonly ToleranceEvaluator $toleranceEvaluator;
    private readonly PathOrderStrategy $orderingStrategy;
    private readonly ExecutionPlanMaterializer $materializer;

    /**
     * @api
     */
    public function __construct(
        private readonly GraphBuilder $graphBuilder,
        ?PathOrderStrategy $orderingStrategy = null,
        ?ExecutionPlanMaterializer $materializer = null,
    ) {
        $strategy = $orderingStrategy ?? new CostHopsSignatureOrderingStrategy(self::COST_SCALE);
        $fillEvaluator = new OrderFillEvaluator();
        $legMaterializer = new LegMaterializer($fillEvaluator);
        $this->orderSpendAnalyzer = new OrderSpendAnalyzer($fillEvaluator, $legMaterializer);
        $this->toleranceEvaluator = new ToleranceEvaluator();
        $this->orderingStrategy = $strategy;
        $this->materializer = $materializer ?? new ExecutionPlanMaterializer($fillEvaluator, $legMaterializer);
    }

    /**
     * Searches for up to K best execution plans from the configured spend asset to the target asset.
     *
     * Returns up to `config->resultLimit()` distinct execution plans, each using a completely
     * disjoint set of orders. Plans are ordered by cost (best/cheapest first).
     *
     * The returned `SearchOutcome::paths()` collection will contain 0 to K entries:
     * - 0 entries: No valid execution plan could be found
     * - 1 to K entries: Distinct execution plans ordered by cost
     *
     * Use `bestPath()` to get the optimal (first) plan, or iterate `paths()` for alternatives.
     *
     * Guard limit breaches are reported through the returned {@see SearchOutcome::guardLimits()}
     * metadata, aggregated across all K search iterations. Inspect the {@see SearchGuardReport}
     * via helpers like {@see SearchGuardReport::anyLimitReached()} to determine whether any
     * search iteration exhausted its configured protections.
     *
     * @api
     *
     * @throws GuardLimitExceeded when the search guard aborts the exploration before exhausting the configured constraints
     * @throws InvalidInput       when the requested target asset identifier is empty
     * @throws PrecisionViolation when arbitrary precision operations required for cost ordering cannot be performed
     *
     * @return SearchOutcome<ExecutionPlan> Contains 0 to K execution plans. Use `bestPath()` for optimal plan or `paths()` for all.
     *
     * @phpstan-return SearchOutcome<ExecutionPlan>
     *
     * @psalm-return SearchOutcome<ExecutionPlan>
     *
     * @example
     * ```php
     * // Create configuration with Top-K
     * $config = PathSearchConfig::builder()
     *     ->withSpendAmount(Money::fromString('USD', '1000.00', 2))
     *     ->withToleranceBounds('0.0', '0.10')  // 0-10% tolerance
     *     ->withHopLimits(1, 3)  // Allow 1-3 hop paths
     *     ->withResultLimit(5)  // Request top 5 plans
     *     ->build();
     *
     * // Create request and execute search
     * $request = new PathSearchRequest($orderBook, $config, 'BTC');
     * $outcome = $service->findBestPlans($request);
     *
     * // Process all alternative plans
     * echo "Found {$outcome->paths()->count()} alternative plans:\n";
     * foreach ($outcome->paths() as $rank => $plan) {
     *     printf(
     *         "Plan #%d: spend %s %s to receive %s %s\n",
     *         $rank + 1,
     *         $plan->totalSpent()->amount(),
     *         $plan->totalSpent()->currency(),
     *         $plan->totalReceived()->amount(),
     *         $plan->totalReceived()->currency(),
     *     );
     * }
     *
     * // Best plan is always first
     * $bestPlan = $outcome->bestPath();
     *
     * // Check guard limits (aggregated across all K searches)
     * if ($outcome->guardLimits()->anyLimitReached()) {
     *     echo "Search was limited by guard rails\n";
     * }
     * ```
     */
    public function findBestPlans(PathSearchRequest $request): SearchOutcome
    {
        $config = $request->config();
        $targetAsset = $request->targetAsset();
        $orderBook = $request->orderBook();
        $sourceCurrency = strtoupper(trim($request->sourceAsset()));
        $targetCurrency = strtoupper(trim($targetAsset));
        $requestedSpend = $request->spendAmount();

        // Validate inputs
        if ('' === $sourceCurrency) {
            throw new InvalidInput('Source asset cannot be empty.');
        }

        if ('' === $targetCurrency) {
            throw new InvalidInput('Target asset cannot be empty.');
        }

        // Filter orders based on spend constraints
        $orders = $this->orderSpendAnalyzer->filterOrders($orderBook, $config);
        if ([] === $orders) {
            /** @var SearchOutcome<ExecutionPlan> $empty */
            $empty = SearchOutcome::empty($this->idleGuardReport($config));

            return $empty;
        }

        // Build initial graph from filtered orders
        $graph = $this->graphBuilder->build($orders);
        if (!$graph->hasNode($sourceCurrency) || !$graph->hasNode($targetCurrency)) {
            /** @var SearchOutcome<ExecutionPlan> $empty */
            $empty = SearchOutcome::empty($this->idleGuardReport($config));

            return $empty;
        }

        // Create the search engine
        $engine = new ExecutionPlanSearchEngine(
            $config->pathFinderMaxExpansions(),
            $config->pathFinderTimeBudgetMs(),
            $config->pathFinderMaxVisitedStates(),
        );

        // Branch based on Top-K mode
        if ($config->disjointPlans()) {
            return $this->findDisjointTopK(
                $config,
                $engine,
                $graph,
                $sourceCurrency,
                $targetCurrency,
                $requestedSpend,
            );
        }

        return $this->findReusableTopK(
            $config,
            $engine,
            $graph,
            $sourceCurrency,
            $targetCurrency,
            $requestedSpend,
        );
    }

    /**
     * Finds Top-K plans using disjoint order sets (original algorithm).
     *
     * Each subsequent search excludes all orders used in previously found plans,
     * ensuring each plan uses completely different orders.
     *
     * @return SearchOutcome<ExecutionPlan>
     */
    private function findDisjointTopK(
        PathSearchConfig $config,
        ExecutionPlanSearchEngine $engine,
        Model\Graph\Graph $graph,
        string $sourceCurrency,
        string $targetCurrency,
        Money $requestedSpend,
    ): SearchOutcome {
        $resultLimit = $config->resultLimit();

        /** @var list<PathResultSetEntry<ExecutionPlan>> $entries */
        $entries = [];
        /** @var list<SearchGuardReport> $guardReports */
        $guardReports = [];
        /** @var array<int, true> $excludedOrderIds */
        $excludedOrderIds = [];
        $currentGraph = $graph;

        for ($iteration = 0; $iteration < $resultLimit; ++$iteration) {
            // Search for next best plan
            $searchOutcome = $engine->search(
                $currentGraph,
                $sourceCurrency,
                $targetCurrency,
                $requestedSpend,
            );

            $guardReports[] = $searchOutcome->guardReport();

            // Check guard limits after each iteration
            $this->assertGuardLimits($config, $searchOutcome->guardReport());

            // Stop if no path found
            if (!$searchOutcome->hasRawFills()) {
                break;
            }

            // Initial tolerance evaluation
            $toleranceResult = $this->toleranceEvaluator->evaluate(
                $config,
                $requestedSpend,
                $requestedSpend,
            );
            $tolerance = $toleranceResult ?? DecimalTolerance::fromNumericString('0', self::COST_SCALE);

            /** @var list<array{order: \SomeWork\P2PPathFinder\Domain\Order\Order, spend: Money, sequence: int}> $rawFills */
            $rawFills = $searchOutcome->rawFills();

            // Materialize the plan
            $plan = $this->materializer->materialize(
                $rawFills,
                $sourceCurrency,
                $targetCurrency,
                $tolerance,
            );

            if (null === $plan) {
                break;
            }

            // Re-evaluate tolerance with actual spent amount
            $toleranceResult = $this->toleranceEvaluator->evaluate(
                $config,
                $requestedSpend,
                $plan->totalSpent(),
            );

            if (null === $toleranceResult) {
                break;
            }

            // Create ordering key for the result
            $orderKey = $this->createOrderKey($plan, $iteration);

            /** @var PathResultSetEntry<ExecutionPlan> $entry */
            $entry = new PathResultSetEntry($plan, $orderKey);
            $entries[] = $entry;

            // Collect order IDs for exclusion in next iteration
            foreach ($plan->steps() as $step) {
                $excludedOrderIds[spl_object_id($step->order())] = true;
            }

            // Stop if we have enough plans or if guard was breached
            if ($searchOutcome->guardReport()->anyLimitReached()) {
                break;
            }

            // Filter graph for next iteration (if we need more plans)
            if ($iteration + 1 < $resultLimit) {
                $currentGraph = $currentGraph->withoutOrders($excludedOrderIds);
            }
        }

        return $this->buildSearchOutcome($config, $entries, $guardReports);
    }

    /**
     * Finds Top-K plans allowing order reuse with penalty-based diversification.
     *
     * Plans can share orders since only one will actually execute. Uses capacity
     * penalties to encourage diversity while detecting and skipping duplicates.
     *
     * @return SearchOutcome<ExecutionPlan>
     */
    private function findReusableTopK(
        PathSearchConfig $config,
        ExecutionPlanSearchEngine $engine,
        Model\Graph\Graph $graph,
        string $sourceCurrency,
        string $targetCurrency,
        Money $requestedSpend,
    ): SearchOutcome {
        $resultLimit = $config->resultLimit();
        $penaltyFactor = '0.15'; // 15% penalty per reuse

        /** @var list<PathResultSetEntry<ExecutionPlan>> $entries */
        $entries = [];
        /** @var list<SearchGuardReport> $guardReports */
        $guardReports = [];
        /** @var array<string, true> $signatures */
        $signatures = [];
        /** @var array<int, int> $usageCounts Order object ID => usage count */
        $usageCounts = [];
        /** @var list<ExecutionPlan> $acceptedPlans */
        $acceptedPlans = [];

        // Allow extra iterations to handle duplicates (up to 2x resultLimit)
        $maxIterations = $resultLimit * 2;
        $consecutiveDuplicates = 0;
        $maxConsecutiveDuplicates = $resultLimit; // Stop after too many duplicates

        for ($iteration = 0; $iteration < $maxIterations; ++$iteration) {
            // Apply penalties to used orders
            $penalizedGraph = $graph->withOrderPenalties($usageCounts, $penaltyFactor);

            // Search for next best plan
            $searchOutcome = $engine->search(
                $penalizedGraph,
                $sourceCurrency,
                $targetCurrency,
                $requestedSpend,
            );

            $guardReports[] = $searchOutcome->guardReport();

            // Check guard limits after each iteration
            $this->assertGuardLimits($config, $searchOutcome->guardReport());

            // Stop if no path found
            if (!$searchOutcome->hasRawFills()) {
                break;
            }

            // Initial tolerance evaluation
            $toleranceResult = $this->toleranceEvaluator->evaluate(
                $config,
                $requestedSpend,
                $requestedSpend,
            );
            $tolerance = $toleranceResult ?? DecimalTolerance::fromNumericString('0', self::COST_SCALE);

            /** @var list<array{order: \SomeWork\P2PPathFinder\Domain\Order\Order, spend: Money, sequence: int}> $rawFills */
            $rawFills = $searchOutcome->rawFills();

            // Materialize the plan
            $plan = $this->materializer->materialize(
                $rawFills,
                $sourceCurrency,
                $targetCurrency,
                $tolerance,
            );

            if (null === $plan) {
                break;
            }

            // Re-evaluate tolerance with actual spent amount
            $toleranceResult = $this->toleranceEvaluator->evaluate(
                $config,
                $requestedSpend,
                $plan->totalSpent(),
            );

            if (null === $toleranceResult) {
                break;
            }

            // Check for signature duplicate
            $sig = $plan->signature();
            if (isset($signatures[$sig])) {
                // Increment penalties for orders in this duplicate plan
                foreach ($plan->steps() as $step) {
                    $orderId = spl_object_id($step->order());
                    $usageCounts[$orderId] = ($usageCounts[$orderId] ?? 0) + 1;
                }
                ++$consecutiveDuplicates;
                if ($consecutiveDuplicates >= $maxConsecutiveDuplicates) {
                    break; // Too many duplicates, stop iteration
                }
                continue;
            }

            // Check for effective duplicate (same cost)
            $isDuplicate = false;
            foreach ($acceptedPlans as $existingPlan) {
                if ($plan->isDuplicateOf($existingPlan)) {
                    $isDuplicate = true;
                    break;
                }
            }

            if ($isDuplicate) {
                // Increment penalties and continue
                foreach ($plan->steps() as $step) {
                    $orderId = spl_object_id($step->order());
                    $usageCounts[$orderId] = ($usageCounts[$orderId] ?? 0) + 1;
                }
                ++$consecutiveDuplicates;
                if ($consecutiveDuplicates >= $maxConsecutiveDuplicates) {
                    break;
                }
                continue;
            }

            // Accept this unique plan
            $consecutiveDuplicates = 0; // Reset counter
            $signatures[$sig] = true;
            $acceptedPlans[] = $plan;

            // Create ordering key for the result
            $orderKey = $this->createOrderKey($plan, count($entries));

            /** @var PathResultSetEntry<ExecutionPlan> $entry */
            $entry = new PathResultSetEntry($plan, $orderKey);
            $entries[] = $entry;

            // Update usage counts for orders in this plan
            foreach ($plan->steps() as $step) {
                $orderId = spl_object_id($step->order());
                $usageCounts[$orderId] = ($usageCounts[$orderId] ?? 0) + 1;
            }

            // Stop if we have enough plans
            if (count($entries) >= $resultLimit) {
                break;
            }

            // Stop if guard was breached
            if ($searchOutcome->guardReport()->anyLimitReached()) {
                break;
            }
        }

        return $this->buildSearchOutcome($config, $entries, $guardReports);
    }

    /**
     * Builds the final search outcome from collected entries and guard reports.
     *
     * @param list<PathResultSetEntry<ExecutionPlan>> $entries
     * @param list<SearchGuardReport>                 $guardReports
     *
     * @return SearchOutcome<ExecutionPlan>
     */
    private function buildSearchOutcome(
        PathSearchConfig $config,
        array $entries,
        array $guardReports,
    ): SearchOutcome {
        // Aggregate guard reports from all iterations
        $aggregatedGuardReport = SearchGuardReport::aggregate($guardReports);

        // Return empty result if no plans found
        if ([] === $entries) {
            /** @var SearchOutcome<ExecutionPlan> $empty */
            $empty = SearchOutcome::empty(
                [] === $guardReports ? $this->idleGuardReport($config) : $aggregatedGuardReport
            );

            return $empty;
        }

        /** @var PathResultSet<ExecutionPlan> $resultSet */
        $resultSet = PathResultSet::fromEntries($this->orderingStrategy, $entries);

        /** @var SearchOutcome<ExecutionPlan> $outcome */
        $outcome = new SearchOutcome($resultSet, $aggregatedGuardReport);

        return $outcome;
    }

    /**
     * Creates an idle guard report for cases where no search was performed.
     */
    private function idleGuardReport(PathSearchConfig $config): SearchGuardReport
    {
        return SearchGuardReport::idle(
            $config->pathFinderMaxVisitedStates(),
            $config->pathFinderMaxExpansions(),
            $config->pathFinderTimeBudgetMs(),
        );
    }

    /**
     * Creates an order key for sorting/deduplication of results.
     */
    private function createOrderKey(ExecutionPlan $plan, int $resultIndex): PathOrderKey
    {
        // Calculate cost ratio (spent / received)
        $cost = $this->calculateCostRatio($plan->totalSpent(), $plan->totalReceived());

        // Build route signature from steps for deterministic ordering
        $routeSignature = $this->buildRouteSignature($plan);

        return new PathOrderKey(
            new PathCost($cost),
            $plan->stepCount(),
            $routeSignature,
            $resultIndex,
        );
    }

    /**
     * Calculates the cost ratio for ordering results.
     * Lower cost = better (spend less to receive more).
     */
    private function calculateCostRatio(Money $spent, Money $received): BigDecimal
    {
        if ($received->isZero()) {
            return BigDecimal::of('999999999999999999');
        }

        return self::scaleDecimal(
            $spent->decimal()->dividedBy($received->decimal(), self::COST_SCALE, RoundingMode::HalfUp),
            self::COST_SCALE
        );
    }

    /**
     * Builds a route signature from execution plan steps for deterministic ordering.
     *
     * The signature captures the currency flow through the plan, enabling consistent
     * ordering and deduplication of results.
     */
    private function buildRouteSignature(ExecutionPlan $plan): RouteSignature
    {
        $steps = $plan->steps()->all();
        if ([] === $steps) {
            return RouteSignature::fromNodes([]);
        }

        // Build node sequence from steps
        // For each step, include source and target currencies
        $nodes = [];
        foreach ($steps as $step) {
            // Include a unique identifier combining currencies and order side
            $nodes[] = sprintf(
                '%s_%s_%s_%d',
                $step->from(),
                $step->to(),
                $step->order()->side()->value,
                spl_object_id($step->order()),
            );
        }

        return RouteSignature::fromNodes($nodes);
    }

    /**
     * Asserts that guard limits haven't been exceeded when configured to throw.
     *
     * @throws GuardLimitExceeded when configured and limits were reached
     */
    private function assertGuardLimits(PathSearchConfig $config, SearchGuardReport $guardLimits): void
    {
        if (!$config->throwOnGuardLimit() || !$guardLimits->anyLimitReached()) {
            return;
        }

        throw new GuardLimitExceeded($this->formatGuardLimitMessage($guardLimits));
    }

    /**
     * Formats a human-readable guard limit exceeded message.
     */
    private function formatGuardLimitMessage(SearchGuardReport $guardLimits): string
    {
        $breaches = [];

        if ($guardLimits->expansionsReached()) {
            $breaches[] = sprintf(
                'expansions %d/%d',
                $guardLimits->expansions(),
                $guardLimits->expansionLimit(),
            );
        }

        if ($guardLimits->visitedStatesReached()) {
            $breaches[] = sprintf(
                'visited states %d/%d',
                $guardLimits->visitedStates(),
                $guardLimits->visitedStateLimit(),
            );
        }

        if ($guardLimits->timeBudgetReached()) {
            $elapsed = sprintf('%.3fms', $guardLimits->elapsedMilliseconds());
            $limit = $guardLimits->timeBudgetLimit();
            $breaches[] = null === $limit
                ? sprintf('elapsed %s (unbounded)', $elapsed)
                : sprintf('elapsed %s/%dms', $elapsed, $limit);
        }

        if ([] === $breaches) {
            return 'Search guard limit exceeded.';
        }

        return 'Search guard limit exceeded: '.implode(' and ', $breaches).'.';
    }
}
