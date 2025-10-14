<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Service;

use InvalidArgumentException;
use SomeWork\P2PPathFinder\Application\Config\PathSearchConfig;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\OrderBook\OrderBook;
use SomeWork\P2PPathFinder\Application\PathFinder\PathFinder;
use SomeWork\P2PPathFinder\Application\Result\PathResult;
use SomeWork\P2PPathFinder\Application\Support\OrderFillEvaluator;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;

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

    public function __construct(
        private readonly GraphBuilder $graphBuilder,
        ?OrderSpendAnalyzer $orderSpendAnalyzer = null,
        ?LegMaterializer $legMaterializer = null,
        ?ToleranceEvaluator $toleranceEvaluator = null,
        ?OrderFillEvaluator $fillEvaluator = null,
    ) {
        $fillEvaluator ??= new OrderFillEvaluator();

        if (null === $legMaterializer) {
            $legMaterializer = new LegMaterializer($fillEvaluator);
        }

        $this->legMaterializer = $legMaterializer;
        $this->orderSpendAnalyzer = $orderSpendAnalyzer ?? new OrderSpendAnalyzer($fillEvaluator, $this->legMaterializer);
        $this->toleranceEvaluator = $toleranceEvaluator ?? new ToleranceEvaluator();
    }

    /**
     * Searches for the best conversion path from the configured spend asset to the target asset.
     */
    public function findBestPath(OrderBook $orderBook, PathSearchConfig $config, string $targetAsset): ?PathResult
    {
        if ('' === $targetAsset) {
            throw new InvalidArgumentException('Target asset cannot be empty.');
        }

        $sourceCurrency = $config->spendAmount()->currency();
        $targetCurrency = strtoupper($targetAsset);
        $requestedSpend = $config->spendAmount();

        $orders = $this->orderSpendAnalyzer->filterOrders($orderBook, $config);
        if ([] === $orders) {
            return null;
        }

        $graph = $this->graphBuilder->build($orders);
        if (!isset($graph[$sourceCurrency], $graph[$targetCurrency])) {
            return null;
        }

        $pathFinder = new PathFinder($config->maximumHops(), $config->pathFinderTolerance());

        $materializedResult = null;
        $materializedCost = null;
        $pathFinder->findBestPath(
            $graph,
            $sourceCurrency,
            $targetCurrency,
            [
                'min' => $config->minimumSpendAmount(),
                'max' => $config->maximumSpendAmount(),
                'desired' => $requestedSpend,
            ],
            function (array $candidate) use (&$materializedResult, &$materializedCost, $config, $sourceCurrency, $targetCurrency, $requestedSpend) {
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

                $result = new PathResult(
                    $materialized['totalSpent'],
                    $materialized['totalReceived'],
                    $residual,
                    $materialized['legs'],
                    $materialized['feeBreakdown'],
                );

                if (null === $materializedCost || -1 === BcMath::comp($candidate['cost'], $materializedCost, self::COST_SCALE)) {
                    $materializedCost = $candidate['cost'];
                    $materializedResult = $result;
                }

                return true;
            }
        );

        if (null === $materializedResult) {
            return null;
        }

        return $materializedResult;
    }
}
