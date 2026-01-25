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
use SomeWork\P2PPathFinder\Application\PathSearch\Result\ExecutionPlan;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathResultSet;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathResultSetEntry;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\SearchGuardReport;
use SomeWork\P2PPathFinder\Application\PathSearch\Support\OrderFillEvaluator;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\ValueObject\DecimalHelperTrait;
use SomeWork\P2PPathFinder\Exception\GuardLimitExceeded;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

use function implode;
use function sprintf;
use function strtoupper;
use function trim;

/**
 * Service for finding optimal execution plans that may include split/merge routes.
 *
 * Unlike {@see PathSearchService} which returns linear paths only, this service can find
 * execution plans that:
 * - Use multiple orders for the same currency direction
 * - Split input across parallel routes
 * - Merge multiple routes at the target currency
 *
 * This service is the recommended public API for complex trading scenarios where
 * a single linear path may not optimally utilize available liquidity.
 *
 * @see PathSearchRequest For request structure
 * @see PathSearchConfig For configuration options
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

    /**
     * @api
     */
    public function __construct(
        private readonly GraphBuilder $graphBuilder,
        ?PathOrderStrategy $orderingStrategy = null,
    ) {
        $strategy = $orderingStrategy ?? new CostHopsSignatureOrderingStrategy(self::COST_SCALE);
        $fillEvaluator = new OrderFillEvaluator();
        $legMaterializer = new LegMaterializer($fillEvaluator);
        $this->orderSpendAnalyzer = new OrderSpendAnalyzer($fillEvaluator, $legMaterializer);
        $this->toleranceEvaluator = new ToleranceEvaluator();
        $this->orderingStrategy = $strategy;
    }

    /**
     * Searches for the best execution plans from the configured spend asset to the target asset.
     *
     * Guard limit breaches are reported through the returned {@see SearchOutcome::guardLimits()}
     * metadata. Inspect the {@see SearchGuardReport} via helpers like
     * {@see SearchGuardReport::anyLimitReached()} to determine whether the search exhausted its
     * configured protections.
     *
     * @api
     *
     * @throws GuardLimitExceeded when the search guard aborts the exploration before exhausting the configured constraints
     * @throws InvalidInput       when the requested target asset identifier is empty
     * @throws PrecisionViolation when arbitrary precision operations required for cost ordering cannot be performed
     *
     * @return SearchOutcome<ExecutionPlan>
     *
     * @phpstan-return SearchOutcome<ExecutionPlan>
     *
     * @psalm-return SearchOutcome<ExecutionPlan>
     *
     * @example
     * ```php
     * // Create configuration
     * $config = PathSearchConfig::builder()
     *     ->withSpendAmount(Money::fromString('USD', '100.00', 2))
     *     ->withToleranceBounds('0.0', '0.10')  // 0-10% tolerance
     *     ->withHopLimits(1, 3)  // Allow 1-3 hop paths
     *     ->build();
     *
     * // Create request and execute search
     * $request = new PathSearchRequest($orderBook, $config, 'BTC');
     * $outcome = $service->findBestPlans($request);
     *
     * // Process results
     * $bestPlan = $outcome->bestPath();
     * if (null !== $bestPlan) {
     *     echo "Best plan spends {$bestPlan->totalSpent()->amount()} {$bestPlan->totalSpent()->currency()}\n";
     *     echo "Best plan receives {$bestPlan->totalReceived()->amount()} {$bestPlan->totalReceived()->currency()}\n";
     *
     *     foreach ($bestPlan->steps() as $step) {
     *         printf(
     *             "Step %d: spend %s %s to receive %s %s\n",
     *             $step->sequenceNumber(),
     *             $step->spent()->amount(),
     *             $step->from(),
     *             $step->received()->amount(),
     *             $step->to(),
     *         );
     *     }
     * }
     *
     * // Check guard limits
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

        // Build graph from filtered orders
        $graph = $this->graphBuilder->build($orders);
        if (!$graph->hasNode($sourceCurrency) || !$graph->hasNode($targetCurrency)) {
            /** @var SearchOutcome<ExecutionPlan> $empty */
            $empty = SearchOutcome::empty($this->idleGuardReport($config));

            return $empty;
        }

        // Create and run the search engine
        $engine = new ExecutionPlanSearchEngine(
            $config->pathFinderMaxExpansions(),
            $config->pathFinderTimeBudgetMs(),
            $config->pathFinderMaxVisitedStates(),
        );

        $searchOutcome = $engine->search(
            $graph,
            $sourceCurrency,
            $targetCurrency,
            $requestedSpend,
        );

        $guardLimits = $searchOutcome->guardReport();
        $this->assertGuardLimits($config, $guardLimits);

        // Process the result
        if (!$searchOutcome->hasPlan()) {
            /** @var SearchOutcome<ExecutionPlan> $empty */
            $empty = SearchOutcome::empty($guardLimits);

            return $empty;
        }

        /** @var ExecutionPlan $plan */
        $plan = $searchOutcome->plan();

        // Evaluate tolerance
        $toleranceResult = $this->toleranceEvaluator->evaluate(
            $config,
            $requestedSpend,
            $plan->totalSpent(),
        );

        if (null === $toleranceResult) {
            /** @var SearchOutcome<ExecutionPlan> $empty */
            $empty = SearchOutcome::empty($guardLimits);

            return $empty;
        }

        // Create ordering key for the result
        $orderKey = $this->createOrderKey($plan, 0);

        /** @var PathResultSetEntry<ExecutionPlan> $entry */
        $entry = new PathResultSetEntry($plan, $orderKey);

        /** @var PathResultSet<ExecutionPlan> $resultSet */
        $resultSet = PathResultSet::fromEntries($this->orderingStrategy, [$entry]);

        /** @var SearchOutcome<ExecutionPlan> $outcome */
        $outcome = new SearchOutcome($resultSet, $guardLimits);

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
            $spent->decimal()->dividedBy($received->decimal(), self::COST_SCALE, RoundingMode::HALF_UP),
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
