<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder;

use InvalidArgumentException;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SplPriorityQueue;

use function array_key_exists;
use function array_values;
use function is_finite;
use function log;
use function strtoupper;

use const INF;

/**
 * Implementation of a tolerance-aware best-path search through the trading graph.
 */
final class PathFinder
{
    /**
     * @param int   $maxHops   maximum number of edges a path may contain
     * @param float $tolerance value in the [0, 1) range representing the acceptable degradation of the best cost
     */
    public function __construct(
        private readonly int $maxHops = 4,
        private readonly float $tolerance = 0.0,
    ) {
        if ($maxHops < 1) {
            throw new InvalidArgumentException('Maximum hops must be at least one.');
        }

        if ($tolerance < 0.0 || $tolerance >= 1.0) {
            throw new InvalidArgumentException('Tolerance must be in the [0, 1) range.');
        }
    }

    /**
     * @param array<string, array{currency: string, edges: list<array{from: string, to: string, orderSide: OrderSide, rate: ExchangeRate, order: Order}>}> $graph
     *
     * @return array{
     *     cost: float,
     *     product: float,
     *     hops: int,
     *     edges: list<array{
     *         from: string,
     *         to: string,
     *         order: Order,
     *         rate: ExchangeRate,
     *         orderSide: OrderSide,
     *         conversionRate: float,
     *     }>,
     * }|null
     */
    public function findBestPath(array $graph, string $source, string $target): ?array
    {
        $source = strtoupper($source);
        $target = strtoupper($target);

        if (!array_key_exists($source, $graph) || !array_key_exists($target, $graph)) {
            return null;
        }

        $queue = new SplPriorityQueue();
        $queue->setExtractFlags(SplPriorityQueue::EXTR_DATA);

        $queue->insert([
            'node' => $source,
            'cost' => 0.0,
            'product' => 1.0,
            'hops' => 0,
            'path' => [],
        ], 0.0);

        /**
         * @var array<string, list<array{cost: float, hops: int}>> $bestPerNode
         */
        $bestPerNode = [$source => [['cost' => 0.0, 'hops' => 0]]];

        $bestTargetState = null;
        $bestTargetCost = INF;

        while (!$queue->isEmpty()) {
            /** @var array{node: string, cost: float, product: float, hops: int, path: list<array<string, mixed>>} $state */
            $state = $queue->extract();

            if ($state['node'] === $target) {
                if ($state['cost'] < $bestTargetCost) {
                    $bestTargetCost = $state['cost'];
                    $bestTargetState = $state;
                }

                continue;
            }

            if ($state['hops'] >= $this->maxHops) {
                continue;
            }

            if (!array_key_exists($state['node'], $graph)) {
                continue;
            }

            foreach ($graph[$state['node']]['edges'] as $edge) {
                $nextNode = $edge['to'];
                if (!array_key_exists($nextNode, $graph)) {
                    continue;
                }

                $conversionRate = $this->edgeConversionRate($edge);
                if ($conversionRate <= 0.0) {
                    continue;
                }

                $nextCost = $state['cost'] - log($conversionRate);
                $nextHops = $state['hops'] + 1;

                if ($this->isDominated($bestPerNode[$nextNode] ?? [], $nextCost, $nextHops)) {
                    continue;
                }

                if (is_finite($bestTargetCost)) {
                    $maxAllowedCost = $bestTargetCost - log(1.0 - $this->tolerance);
                    if ($nextCost > $maxAllowedCost) {
                        continue;
                    }
                }

                $nextPath = $state['path'];
                $nextPath[] = [
                    'from' => $edge['from'],
                    'to' => $nextNode,
                    'order' => $edge['order'],
                    'rate' => $edge['rate'],
                    'orderSide' => $edge['orderSide'],
                    'conversionRate' => $conversionRate,
                ];

                $nextState = [
                    'node' => $nextNode,
                    'cost' => $nextCost,
                    'product' => $state['product'] * $conversionRate,
                    'hops' => $nextHops,
                    'path' => $nextPath,
                ];

                $this->recordState($bestPerNode, $nextNode, $nextCost, $nextHops);

                $priority = -($nextCost + $this->heuristic($nextNode, $target));
                $queue->insert($nextState, $priority);
            }
        }

        if (null === $bestTargetState) {
            return null;
        }

        return [
            'cost' => $bestTargetState['cost'],
            'product' => $bestTargetState['product'],
            'hops' => $bestTargetState['hops'],
            'edges' => $bestTargetState['path'],
        ];
    }

    /**
     * @param list<array{cost: float, hops: int}> $existing
     */
    private function isDominated(array $existing, float $cost, int $hops): bool
    {
        foreach ($existing as $state) {
            if ($state['cost'] <= $cost && $state['hops'] <= $hops) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, list<array{cost: float, hops: int}>> $registry
     */
    private function recordState(array &$registry, string $node, float $cost, int $hops): void
    {
        $existing = $registry[$node] ?? [];

        foreach ($existing as $index => $state) {
            if ($cost <= $state['cost'] && $hops <= $state['hops']) {
                unset($existing[$index]);
            }
        }

        $existing[] = ['cost' => $cost, 'hops' => $hops];
        $registry[$node] = array_values($existing);
    }

    /**
     * @param array{orderSide: OrderSide, rate: ExchangeRate} $edge
     */
    private function edgeConversionRate(array $edge): float
    {
        $rate = (float) $edge['rate']->rate();
        if ($rate <= 0.0) {
            return 0.0;
        }

        return match ($edge['orderSide']) {
            OrderSide::BUY => $rate,
            OrderSide::SELL => 1.0 / $rate,
        };
    }

    private function heuristic(string $node, string $target): float
    {
        if ($node === $target) {
            return 0.0;
        }

        return 0.0;
    }
}
