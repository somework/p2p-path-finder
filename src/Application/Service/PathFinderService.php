<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Service;

use Closure;
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Application\PathFinder\PathFinder;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\GuardLimitStatus;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\CostHopsSignatureOrderingStrategy;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathOrderKey;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathOrderStrategy;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\SearchOutcome;
use SomeWork\P2PPathFinder\Application\Result\PathResult;
use SomeWork\P2PPathFinder\Application\Support\OrderFillEvaluator;
use SomeWork\P2PPathFinder\Exception\GuardLimitExceeded;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

use function array_map;
use function implode;
use function sprintf;
use function strtoupper;
use function usort;

/**
 * High level facade orchestrating order filtering, graph building and path search.
 *
 * @phpstan-import-type Candidate from PathFinder
 * @phpstan-import-type Graph from PathFinder
 * @phpstan-import-type SpendConstraints from PathFinder
 *
 * @psalm-import-type Candidate from PathFinder
 * @psalm-import-type Graph from PathFinder
 * @psalm-import-type SpendConstraints from PathFinder
 */
final class PathFinderService
{
    private const COST_SCALE = 18;

    private readonly OrderSpendAnalyzer $orderSpendAnalyzer;
    private readonly LegMaterializer $legMaterializer;
    private readonly ToleranceEvaluator $toleranceEvaluator;
    private readonly PathOrderStrategy $orderingStrategy;
    /**
     * @var Closure(PathSearchConfig):Closure(array, string, string, array, callable):SearchOutcome
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
             * @param Graph                    $graph
             * @param SpendConstraints         $range
             * @param callable(Candidate):bool $callback
             *
             * @phpstan-param Graph                    $graph
             * @phpstan-param SpendConstraints         $range
             * @phpstan-param callable(Candidate):bool $callback
             *
             * @psalm-param Graph                    $graph
             * @psalm-param SpendConstraints         $range
             * @psalm-param callable(Candidate):bool $callback
             *
             * @return SearchOutcome<Candidate>
             *
             * @phpstan-return SearchOutcome<Candidate>
             *
             * @psalm-return SearchOutcome<Candidate>
             */
            $runner = static function (
                array $graph,
                string $source,
                string $target,
                array $range,
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

                /** @var Graph $graph */
                $graph = $graph;

                /** @var SpendConstraints $range */
                $range = $range;

                /** @var callable(Candidate):bool $callback */
                $callback = $callback;

                return $pathFinder->findBestPaths($graph, $source, $target, $range, $callback);
            };

            return $runner;
        };

        $factory = $factory instanceof Closure ? $factory : Closure::fromCallable($factory);

        /** @var Closure(PathSearchConfig):Closure(array, string, string, array, callable):SearchOutcome $typedFactory */
        $typedFactory = $factory;

        $this->pathFinderFactory = $typedFactory;
    }

    /**
     * Searches for the best conversion paths from the configured spend asset to the target asset.
     *
     * Guard limit breaches are reported through the returned {@see SearchOutcome::guardLimits()}
     * metadata. Inspect the {@see GuardLimitStatus} via helpers like
     * {@see GuardLimitStatus::anyLimitReached()} to determine whether the search exhausted its
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
            $empty = SearchOutcome::empty(GuardLimitStatus::none());

            return $empty;
        }

        /** @var Graph $graph */
        $graph = $this->graphBuilder->build($orders);
        if (!isset($graph[$sourceCurrency], $graph[$targetCurrency])) {
            /** @var SearchOutcome<PathResult> $empty */
            $empty = SearchOutcome::empty(GuardLimitStatus::none());

            return $empty;
        }

        $runnerFactory = $this->pathFinderFactory;
        /**
         * @var Closure(Graph, string, string, SpendConstraints, callable(Candidate):bool):SearchOutcome<Candidate> $runner
         *
         * @phpstan-var Closure(Graph, string, string, SpendConstraints, callable(Candidate):bool):SearchOutcome<Candidate> $runner
         *
         * @psalm-var Closure(Graph, string, string, SpendConstraints, callable(Candidate):bool):SearchOutcome<Candidate> $runner
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
            [
                'min' => $config->minimumSpendAmount(),
                'max' => $config->maximumSpendAmount(),
                'desired' => $requestedSpend,
            ],
            /**
             * @phpstan-param Candidate $candidate
             *
             * @psalm-param Candidate $candidate
             */
            function (array $candidate) use (&$materializedResults, &$resultOrder, $config, $sourceCurrency, $targetCurrency, $requestedSpend) {
                if ($candidate['hops'] < $config->minimumHops() || $candidate['hops'] > $config->maximumHops()) {
                    return false;
                }

                if ([] === $candidate['edges']) {
                    return false;
                }

                $firstEdge = $candidate['edges'][0];
                if ($firstEdge['from'] !== $sourceCurrency) {
                    return false;
                }

                $initialSeed = $this->orderSpendAnalyzer->determineInitialSpendAmount($config, $firstEdge);
                if (null === $initialSeed) {
                    return false;
                }

                $materialized = $this->legMaterializer->materialize(
                    $candidate['edges'],
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

                $routeSignature = $this->routeSignature($candidate['edges']);
                $result = new PathResult(
                    $materialized['totalSpent'],
                    $materialized['totalReceived'],
                    $residual,
                    $materialized['legs'],
                    $materialized['feeBreakdown'],
                );

                $orderKey = new PathOrderKey(
                    $candidate['cost'],
                    $candidate['hops'],
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

        $this->sortMaterializedResults($materializedResults);

        return new SearchOutcome(
            array_map(
                static fn (MaterializedResult $entry): PathResult => $entry->result(),
                $materializedResults,
            ),
            $guardLimits,
        );
    }

    private function assertGuardLimits(PathSearchConfig $config, GuardLimitStatus $guardLimits): void
    {
        if (!$config->throwOnGuardLimit() || !$guardLimits->anyLimitReached()) {
            return;
        }

        throw new GuardLimitExceeded($this->formatGuardLimitMessage($config, $guardLimits));
    }

    private function formatGuardLimitMessage(PathSearchConfig $config, GuardLimitStatus $guardLimits): string
    {
        $breaches = [];

        if ($guardLimits->expansionsReached()) {
            $breaches[] = sprintf('expansions limit of %d', $config->pathFinderMaxExpansions());
        }

        if ($guardLimits->visitedStatesReached()) {
            $breaches[] = sprintf('visited states limit of %d', $config->pathFinderMaxVisitedStates());
        }

        if ($guardLimits->timeBudgetReached()) {
            $timeBudget = $config->pathFinderTimeBudgetMs();
            $breaches[] = null === $timeBudget
                ? 'time budget'
                : sprintf('time budget of %dms', $timeBudget);
        }

        if ([] === $breaches) {
            return 'Search guard limit exceeded.';
        }

        return 'Search guard limit exceeded: '.implode(' and ', $breaches).'.';
    }

    /**
     * @phpstan-param list<array{from: string, to: string}> $edges
     *
     * @psalm-param list<array{from: string, to: string, ...}> $edges
     */
    private function routeSignature(array $edges): string
    {
        if ([] === $edges) {
            return '';
        }

        $nodes = [$edges[0]['from']];

        foreach ($edges as $edge) {
            $nodes[] = $edge['to'];
        }

        return implode('->', $nodes);
    }

    /**
     * @param list<MaterializedResult> $materializedResults
     */
    private function sortMaterializedResults(array &$materializedResults): void
    {
        usort(
            $materializedResults,
            fn (MaterializedResult $left, MaterializedResult $right): int => $this->orderingStrategy->compare(
                $left->orderKey(),
                $right->orderKey(),
            ),
        );
    }

    /**
     * @deprecated use {@see PathFinderService::findBestPaths()} instead
     */
    public function findBestPath(OrderBook $orderBook, PathSearchConfig $config, string $targetAsset): ?PathResult
    {
        $results = $this->findBestPaths($orderBook, $config, $targetAsset);

        return $results->paths()[0] ?? null;
    }
}
