<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Service;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use SomeWork\P2PPathFinder\Application\PathSearch\Api\Request\PathSearchRequest;
use SomeWork\P2PPathFinder\Application\PathSearch\Api\Response\SearchOutcome;
use SomeWork\P2PPathFinder\Application\PathSearch\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\CostHopsSignatureOrderingStrategy;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathCost;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathOrderKey;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\PathOrderStrategy;
use SomeWork\P2PPathFinder\Application\PathSearch\Engine\Ordering\RouteSignature;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\ExecutionPlan;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\Path;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathResultSet;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\PathResultSetEntry;
use SomeWork\P2PPathFinder\Application\PathSearch\Result\SearchGuardReport;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\ValueObject\DecimalHelperTrait;
use SomeWork\P2PPathFinder\Exception\GuardLimitExceeded;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

use function sprintf;
use function trigger_error;

use const E_USER_DEPRECATED;

/**
 * High level facade orchestrating order filtering, graph building and path search.
 *
 * @deprecated since 2.0. Use ExecutionPlanService::findBestPlans() instead.
 *             This service only returns linear paths. For split/merge execution
 *             plans, use ExecutionPlanService.
 * @see ExecutionPlanService
 * @see PathSearchRequest For request structure
 * @see PathSearchConfig For configuration options
 * @see SearchOutcome For result structure
 * @see docs/getting-started.md For complete usage examples
 *
 * @api
 */
final class PathSearchService
{
    use DecimalHelperTrait;

    /**
     * Scale for cost calculations, matches trait's canonical scale.
     */
    private const COST_SCALE = self::CANONICAL_SCALE;

    private readonly ExecutionPlanService $executionPlanService;
    private readonly PathOrderStrategy $orderingStrategy;

    /**
     * @api
     */
    public function __construct(
        GraphBuilder $graphBuilder,
        ?PathOrderStrategy $orderingStrategy = null,
    ) {
        $strategy = $orderingStrategy ?? new CostHopsSignatureOrderingStrategy(self::COST_SCALE);
        $this->orderingStrategy = $strategy;
        $this->executionPlanService = new ExecutionPlanService($graphBuilder, $strategy);
    }

    /**
     * Convert an ExecutionPlan to a Path if the plan is linear.
     *
     * This helper method assists in migrating from PathSearchService to ExecutionPlanService
     * by providing a way to convert execution plans back to the legacy Path format.
     *
     * @throws InvalidInput if the plan contains splits/merges (non-linear) or is empty
     */
    public static function planToPath(ExecutionPlan $plan): Path
    {
        if (!$plan->isLinear()) {
            throw InvalidInput::forNonLinearPlan();
        }

        $path = $plan->asLinearPath();

        if (null === $path) {
            throw InvalidInput::forEmptyPlan();
        }

        return $path;
    }

    /**
     * Searches for the best conversion paths from the configured spend asset to the target asset.
     *
     * This method delegates to {@see ExecutionPlanService::findBestPlans()} and filters
     * results to return only linear paths that can be represented as {@see Path} objects.
     *
     * Guard limit breaches are reported through the returned {@see SearchOutcome::guardLimits()}
     * metadata. Inspect the {@see SearchGuardReport} via helpers like
     * {@see SearchGuardReport::anyLimitReached()} to determine whether the search exhausted its
     * configured protections.
     *
     * @deprecated since 2.0. Use ExecutionPlanService::findBestPlans() instead.
     *
     * @api
     *
     * @throws GuardLimitExceeded when the search guard aborts the exploration before exhausting the configured constraints
     * @throws InvalidInput       when the requested target asset identifier is empty
     * @throws PrecisionViolation when arbitrary precision operations required for cost ordering cannot be performed
     *
     * @return SearchOutcome<Path>
     *
     * @phpstan-return SearchOutcome<Path>
     *
     * @psalm-return SearchOutcome<Path>
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
     * $bestPath = $outcome->bestPath();
     * if (null !== $bestPath) {
     *     echo "Best path spends {$bestPath->totalSpent()->amount()} {$bestPath->totalSpent()->currency()}\n";
     *     echo "Best path receives {$bestPath->totalReceived()->amount()} {$bestPath->totalReceived()->currency()}\n";
     *
     *     foreach ($bestPath->hops() as $index => $hop) {
     *         $order = $hop->order();
     *         $pair = $order->assetPair();
     *
     *         printf(
     *             "Hop %d (%s order %s/%s): spend %s %s to receive %s %s\n",
     *             $index + 1,
     *             $order->side()->value,
     *             $pair->base(),
     *             $pair->quote(),
     *             $hop->spent()->amount(),
     *             $hop->spent()->currency(),
     *             $hop->received()->amount(),
     *             $hop->received()->currency(),
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
    public function findBestPaths(PathSearchRequest $request): SearchOutcome
    {
        @trigger_error(
            'PathSearchService::findBestPaths() is deprecated since 2.0, '
            .'use ExecutionPlanService::findBestPlans() instead.',
            E_USER_DEPRECATED,
        );

        // Delegate to ExecutionPlanService
        $planOutcome = $this->executionPlanService->findBestPlans($request);
        $guardLimits = $planOutcome->guardLimits();

        // Get hop constraints from request
        $minimumHops = $request->minimumHops();
        $maximumHops = $request->maximumHops();

        // Filter to linear paths only and convert ExecutionPlan to Path
        /** @var list<PathResultSetEntry<Path>> $linearPathEntries */
        $linearPathEntries = [];

        $resultIndex = 0;
        foreach ($planOutcome->paths() as $plan) {
            if (!$plan->isLinear()) {
                continue;
            }

            $path = $plan->asLinearPath();
            if (null === $path) {
                continue;
            }

            // Enforce hop constraints (ExecutionPlanService doesn't filter by these)
            $hopCount = $path->hops()->count();
            if ($hopCount < $minimumHops || $hopCount > $maximumHops) {
                continue;
            }

            // Create ordering key for the path
            $orderKey = $this->createOrderKey($path, $resultIndex);

            /** @var PathResultSetEntry<Path> $entry */
            $entry = new PathResultSetEntry($path, $orderKey);

            $linearPathEntries[] = $entry;
            ++$resultIndex;
        }

        if ([] === $linearPathEntries) {
            /** @var SearchOutcome<Path> $empty */
            $empty = SearchOutcome::empty($guardLimits);

            return $empty;
        }

        /** @var PathResultSet<Path> $resultSet */
        $resultSet = PathResultSet::fromEntries($this->orderingStrategy, $linearPathEntries);

        /** @var SearchOutcome<Path> $outcome */
        $outcome = new SearchOutcome($resultSet, $guardLimits);

        return $outcome;
    }

    /**
     * Creates an order key for sorting/deduplication of results.
     */
    private function createOrderKey(Path $path, int $resultIndex): PathOrderKey
    {
        // Calculate cost ratio (spent / received)
        $cost = $this->calculateCostRatio($path->totalSpent(), $path->totalReceived());

        // Build route signature from hops
        $routeSignature = $this->buildRouteSignature($path);

        return new PathOrderKey(
            new PathCost($cost),
            $path->hops()->count(),
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
     * Builds a route signature from path hops for deterministic ordering.
     */
    private function buildRouteSignature(Path $path): RouteSignature
    {
        $hops = $path->hops()->all();
        if ([] === $hops) {
            return RouteSignature::fromNodes([]);
        }

        // Build node sequence from hops
        $nodes = [];
        foreach ($hops as $hop) {
            $nodes[] = sprintf(
                '%s_%s_%s_%d',
                $hop->spent()->currency(),
                $hop->received()->currency(),
                $hop->order()->side()->value,
                spl_object_id($hop->order()),
            );
        }

        return RouteSignature::fromNodes($nodes);
    }
}
