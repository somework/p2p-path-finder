<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Service;

use Closure;
use SomeWork\P2PPathFinder\Application\PathSearch\Api\Request\PathSearchRequest;
use SomeWork\P2PPathFinder\Application\PathSearch\Api\Response\SearchOutcome;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\CostHopsSignatureOrderingStrategy;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathCost;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathOrderKey;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathOrderStrategy;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\RouteSignature;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\PathSearchEngine;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\CandidatePath;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\Graph;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\PathEdgeSequence;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\MaterializedResult;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathResult;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathResultSet;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathResultSetEntry;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\SearchGuardReport;
use SomeWork\P2PPathFinder\Application\PathSearch\Support\OrderFillEvaluator;
use SomeWork\P2PPathFinder\Domain\ValueObject\DecimalHelperTrait;
use SomeWork\P2PPathFinder\Exception\GuardLimitExceeded;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

use function sprintf;
use function strtoupper;
use function trim;

/**
 * High level facade orchestrating order filtering, graph building and path search.
 *
 * @see PathSearchRequest For request structure
 * @see PathSearchConfig For configuration options
 * @see SearchOutcome For result structure
 * @see docs/getting-started.md For complete usage examples
 *
 * @api
 */
final class PathSearchService
{
    /**
     * @see DecimalHelperTrait::CANONICAL_SCALE
     */
    private const COST_SCALE = 18;

    private readonly OrderSpendAnalyzer $orderSpendAnalyzer;
    private readonly LegMaterializer $legMaterializer;
    private readonly ToleranceEvaluator $toleranceEvaluator;
    private readonly PathOrderStrategy $orderingStrategy;
    /**
     * @var Closure(PathSearchRequest): (Closure(Graph, (callable(CandidatePath): bool)): SearchOutcome<CandidatePath>)
     */
    private Closure $pathFinderFactory;

    /**
     * @api
     */
    public function __construct(
        private readonly GraphBuilder $graphBuilder,
        ?PathOrderStrategy $orderingStrategy = null,
    ) {
        $strategy = $orderingStrategy ?? new CostHopsSignatureOrderingStrategy(self::COST_SCALE);
        $fillEvaluator = new OrderFillEvaluator();
        $this->legMaterializer = new LegMaterializer($fillEvaluator);
        $this->orderSpendAnalyzer = new OrderSpendAnalyzer($fillEvaluator, $this->legMaterializer);
        $this->toleranceEvaluator = new ToleranceEvaluator();
        $this->orderingStrategy = $strategy;
        $this->pathFinderFactory = self::createDefaultRunnerFactory($strategy);
    }

    /**
     * Factory hook for testing. NOT PART OF PUBLIC API.
     *
     * This method exists solely to enable dependency injection of mock PathFinder
     * implementations during testing. Production code should NEVER use this method.
     * Use the standard constructor instead.
     *
     * @internal This is for testing only and may change without notice
     *
     * @param Closure(PathSearchRequest): (Closure(Graph, (callable(CandidatePath): bool)): SearchOutcome<CandidatePath>) $pathFinderFactory Factory that creates PathFinder runner instances
     *
     * @return self Service instance with injected factory (for testing only)
     */
    public static function withRunnerFactory(
        GraphBuilder $graphBuilder,
        ?PathOrderStrategy $orderingStrategy,
        Closure $pathFinderFactory,
    ): self {
        $service = new self($graphBuilder, $orderingStrategy);

        /** @var Closure(PathSearchRequest): (Closure(Graph, (callable(CandidatePath): bool)): SearchOutcome<CandidatePath>) $typedFactory */
        $typedFactory = $pathFinderFactory;
        $service->pathFinderFactory = $typedFactory;

        return $service;
    }

    /**
     * @return Closure(PathSearchRequest): (Closure(Graph, (callable(CandidatePath): bool)): SearchOutcome<CandidatePath>)
     */
    private static function createDefaultRunnerFactory(PathOrderStrategy $strategy): Closure
    {
        return static function (PathSearchRequest $request) use ($strategy): Closure {
            $config = $request->config();

            /**
             * @param Graph                        $graph
             * @param callable(CandidatePath):bool $callback
             *
             * @return SearchOutcome<CandidatePath>
             */
            $runner = static function (
                Graph $graph,
                callable $callback,
            ) use ($config, $strategy, $request): SearchOutcome {
                $pathFinder = new PathSearchEngine(
                    $config->maximumHops(),
                    $config->pathFinderTolerance(),
                    $config->resultLimit(),
                    $config->pathFinderMaxExpansions(),
                    $config->pathFinderMaxVisitedStates(),
                    $strategy,
                    $config->pathFinderTimeBudgetMs(),
                );

                /** @var callable(CandidatePath):bool $callback */
                $callback = $callback;

                return $pathFinder->findBestPaths(
                    $graph,
                    $request->sourceAsset(),
                    $request->targetAsset(),
                    $request->spendConstraints(),
                    $callback,
                );
            };

            return $runner;
        };
    }

    /**
     * Searches for the best conversion paths from the configured spend asset to the target asset.
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
     * @return SearchOutcome<PathResult>
     *
     * @phpstan-return SearchOutcome<PathResult>
     *
     * @psalm-return SearchOutcome<PathResult>
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
     * $outcome = $service->findBestPaths($request);
     *
     * // Process results
     * foreach ($outcome->paths() as $path) {
     *     echo "Route: {$path->route()}\n";
     *     echo "Receive: {$path->totalReceived()->amount()} BTC\n";
     * }
     *
     * // Check guard limits
     * if ($outcome->guardLimits()->anyLimitReached()) {
     *     echo "Search was limited by guard rails\n";
     * }
     * ```
     */
    public function findBestPaths(PathSearchRequest $request): SearchOutcome
    {
        $config = $request->config();
        $targetAsset = $request->targetAsset();
        $orderBook = $request->orderBook();
        $sourceCurrency = strtoupper(trim($request->sourceAsset()));
        $targetCurrency = $targetAsset;
        $requestedSpend = $request->spendAmount();

        $orders = $this->orderSpendAnalyzer->filterOrders($orderBook, $config);
        if ([] === $orders) {
            /** @var SearchOutcome<PathResult> $empty */
            $empty = SearchOutcome::empty(SearchGuardReport::idle(
                $config->pathFinderMaxVisitedStates(),
                $config->pathFinderMaxExpansions(),
                $config->pathFinderTimeBudgetMs(),
            ));

            return $empty;
        }

        $graph = $this->graphBuilder->build($orders);
        if (!$graph->hasNode($sourceCurrency) || !$graph->hasNode($targetCurrency)) {
            /** @var SearchOutcome<PathResult> $empty */
            $empty = SearchOutcome::empty(SearchGuardReport::idle(
                $config->pathFinderMaxVisitedStates(),
                $config->pathFinderMaxExpansions(),
                $config->pathFinderTimeBudgetMs(),
            ));

            return $empty;
        }

        $runnerFactory = $this->pathFinderFactory;
        /** @var Closure(Graph, callable(CandidatePath):bool):SearchOutcome<CandidatePath> $runner */
        $runner = $runnerFactory($request);

        /**
         * @var list<MaterializedResult> $materializedResults
         */
        $materializedResults = [];
        $resultOrder = 0;
        $searchResult = $runner(
            $graph,
            function (CandidatePath $candidate) use (&$materializedResults, &$resultOrder, $request, $requestedSpend, $sourceCurrency, $targetCurrency, $config) {
                if ($candidate->hops() < $request->minimumHops() || $candidate->hops() > $request->maximumHops()) {
                    return false;
                }

                $edges = $candidate->edges();
                if ($edges->isEmpty()) {
                    return false;
                }

                $firstEdge = $edges->first();
                if (null === $firstEdge || $firstEdge->from() !== $sourceCurrency) {
                    return false;
                }

                $initialSeed = $this->orderSpendAnalyzer->determineInitialSpendAmount($config, $firstEdge);
                if (null === $initialSeed) {
                    return false;
                }

                $materialized = $this->legMaterializer->materialize(
                    $edges,
                    $requestedSpend,
                    $initialSeed,
                    $targetCurrency,
                );

                if (null === $materialized) {
                    return false;
                }

                $residual = $this->toleranceEvaluator->evaluate(
                    $config,
                    $requestedSpend,
                    $materialized['toleranceSpent'],
                );

                if (null === $residual) {
                    return false;
                }

                $routeSignature = $this->routeSignature($edges);
                $result = new PathResult(
                    $materialized['totalSpent'],
                    $materialized['totalReceived'],
                    $residual,
                    $materialized['legs'],
                    $materialized['feeBreakdown'],
                );

                $orderKey = new PathOrderKey(
                    new PathCost($candidate->costDecimal()),
                    $candidate->hops(),
                    $routeSignature,
                    $resultOrder,
                );

                $materializedResults[] = new MaterializedResult($result, $orderKey);

                ++$resultOrder;

                return true;
            }
        );

        $guardLimits = $searchResult->guardLimits();
        $this->assertGuardLimits($config, $guardLimits);

        if ([] === $materializedResults) {
            /** @var SearchOutcome<PathResult> $empty */
            $empty = SearchOutcome::empty($guardLimits);

            return $empty;
        }

        /** @var array<PathResultSetEntry<PathResult>> $resultEntries */
        $resultEntries = [];
        foreach ($materializedResults as $entry) {
            /** @var PathResultSetEntry<PathResult> $resultEntry */
            $resultEntry = $entry->toEntry();

            $resultEntries[] = $resultEntry;
        }

        /** @var PathResultSet<PathResult> $resultSet */
        $resultSet = PathResultSet::fromEntries($this->orderingStrategy, $resultEntries);

        /** @var SearchOutcome<PathResult> $outcome */
        $outcome = new SearchOutcome($resultSet, $guardLimits);

        return $outcome;
    }

    private function assertGuardLimits(PathSearchConfig $config, SearchGuardReport $guardLimits): void
    {
        if (!$config->throwOnGuardLimit() || !$guardLimits->anyLimitReached()) {
            return;
        }

        throw new GuardLimitExceeded($this->formatGuardLimitMessage($config, $guardLimits));
    }

    private function formatGuardLimitMessage(PathSearchConfig $config, SearchGuardReport $guardLimits): string
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

    private function routeSignature(PathEdgeSequence $edges): RouteSignature
    {
        return RouteSignature::fromPathEdgeSequence($edges);
    }
}
