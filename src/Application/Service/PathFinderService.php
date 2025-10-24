<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Service;

use Closure;
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\Graph\Graph;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Application\PathFinder\PathFinder;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\CostHopsSignatureOrderingStrategy;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathOrderKey;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathOrderStrategy;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\PathResultSet;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\PathResultSetEntry;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\SearchGuardReport;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\SearchOutcome;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\CandidatePath;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\PathEdgeSequence;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\SpendConstraints;
use SomeWork\P2PPathFinder\Application\Result\PathResult;
use SomeWork\P2PPathFinder\Application\Support\OrderFillEvaluator;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Exception\GuardLimitExceeded;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

use function implode;
use function sprintf;
use function strtoupper;

/**
 * High level facade orchestrating order filtering, graph building and path search.
 */
final class PathFinderService
{
    private const COST_SCALE = 18;

    private readonly OrderSpendAnalyzer $orderSpendAnalyzer;
    private readonly LegMaterializer $legMaterializer;
    private readonly ToleranceEvaluator $toleranceEvaluator;
    private readonly PathOrderStrategy $orderingStrategy;
    /**
     * @var Closure(PathSearchConfig):Closure(Graph, string, string, ?SpendConstraints, callable(CandidatePath):bool):SearchOutcome<CandidatePath>
     */
    private readonly Closure $pathFinderFactory;

    public function __construct(
        private readonly GraphBuilder $graphBuilder,
        ?OrderSpendAnalyzer $orderSpendAnalyzer = null,
        ?LegMaterializer $legMaterializer = null,
        ?ToleranceEvaluator $toleranceEvaluator = null,
        ?OrderFillEvaluator $fillEvaluator = null,
        ?PathOrderStrategy $orderingStrategy = null,
        ?callable $pathFinderFactory = null,
    ) {
        $fillEvaluator ??= new OrderFillEvaluator();

        if (null === $legMaterializer) {
            $legMaterializer = new LegMaterializer($fillEvaluator);
        }

        $this->legMaterializer = $legMaterializer;
        $this->orderSpendAnalyzer = $orderSpendAnalyzer ?? new OrderSpendAnalyzer($fillEvaluator, $this->legMaterializer);
        $this->toleranceEvaluator = $toleranceEvaluator ?? new ToleranceEvaluator();
        $this->orderingStrategy = $orderingStrategy ?? new CostHopsSignatureOrderingStrategy(self::COST_SCALE);
        $strategy = $this->orderingStrategy;
        $factory = $pathFinderFactory ?? static function (PathSearchConfig $config) use ($strategy): Closure {
            /**
             * @param Graph                        $graph
             * @param SpendConstraints|null        $constraints
             * @param callable(CandidatePath):bool $callback
             *
             * @return SearchOutcome<CandidatePath>
             */
            $runner = static function (
                Graph $graph,
                string $source,
                string $target,
                ?SpendConstraints $constraints,
                callable $callback,
            ) use ($config, $strategy): SearchOutcome {
                $pathFinder = new PathFinder(
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

                return $pathFinder->findBestPaths($graph, $source, $target, $constraints, $callback);
            };

            return $runner;
        };

        $factory = $factory instanceof Closure ? $factory : Closure::fromCallable($factory);

        /** @var Closure(PathSearchConfig):Closure(Graph, string, string, ?SpendConstraints, callable(CandidatePath):bool):SearchOutcome<CandidatePath> $typedFactory */
        $typedFactory = $factory;

        $this->pathFinderFactory = $typedFactory;
    }

    /**
     * Searches for the best conversion paths from the configured spend asset to the target asset.
     *
     * Guard limit breaches are reported through the returned {@see SearchOutcome::guardLimits()}
     * metadata. Inspect the {@see SearchGuardReport} via helpers like
     * {@see SearchGuardReport::anyLimitReached()} to determine whether the search exhausted its
     * configured protections.
     *
     * @throws InvalidInput       when the requested target asset identifier is empty
     * @throws PrecisionViolation when arbitrary precision operations required for cost ordering cannot be performed
     *
     * @return SearchOutcome<PathResult>
     */
    public function findBestPaths(OrderBook $orderBook, PathSearchConfig $config, string $targetAsset): SearchOutcome
    {
        if ('' === $targetAsset) {
            throw new InvalidInput('Target asset cannot be empty.');
        }

        $sourceCurrency = $config->spendAmount()->currency();
        $targetCurrency = strtoupper($targetAsset);
        $requestedSpend = $config->spendAmount();

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
        /**
         * @var Closure(Graph, string, string, ?SpendConstraints, callable(CandidatePath):bool):SearchOutcome<CandidatePath> $runner
         */
        $runner = $runnerFactory($config);

        /**
         * @var list<MaterializedResult> $materializedResults
         */
        $materializedResults = [];
        $resultOrder = 0;
        $searchResult = $runner(
            $graph,
            $sourceCurrency,
            $targetCurrency,
            SpendConstraints::from(
                $config->minimumSpendAmount(),
                $config->maximumSpendAmount(),
                $requestedSpend,
            ),
            function (CandidatePath $candidate) use (&$materializedResults, &$resultOrder, $config, $sourceCurrency, $targetCurrency, $requestedSpend) {
                if ($candidate->hops() < $config->minimumHops() || $candidate->hops() > $config->maximumHops()) {
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
                    $candidate->cost(),
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

        /** @var list<PathResultSetEntry<PathResult>> $resultEntries */
        $resultEntries = [];
        foreach ($materializedResults as $entry) {
            /** @var PathResultSetEntry<PathResult> $resultEntry */
            $resultEntry = new PathResultSetEntry($entry->result(), $entry->orderKey());

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

    private function routeSignature(PathEdgeSequence $edges): string
    {
        if ($edges->isEmpty()) {
            return '';
        }

        $first = $edges->first();
        if (null === $first) {
            return '';
        }

        $nodes = [$first->from()];

        foreach ($edges as $edge) {
            $nodes[] = $edge->to();
        }

        return implode('->', $nodes);
    }

    /**
     * @deprecated use {@see PathFinderService::findBestPaths()} instead
     */
    public function findBestPath(OrderBook $orderBook, PathSearchConfig $config, string $targetAsset): ?PathResult
    {
        $results = $this->findBestPaths($orderBook, $config, $targetAsset);

        return $results->paths()->first();
    }
}
