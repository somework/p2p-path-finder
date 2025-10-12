<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder;

use InvalidArgumentException;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SplPriorityQueue;

use function array_key_exists;
use function array_values;
use function rtrim;
use function sprintf;
use function strtoupper;

/**
 * Implementation of a tolerance-aware best-path search through the trading graph.
 *
 * @phpstan-type GraphEdge array{
 *     from: string,
 *     to: string,
 *     orderSide: OrderSide,
 *     order: Order,
 *     rate: ExchangeRate,
 *     baseCapacity: array{min: Money, max: Money},
 *     quoteCapacity: array{min: Money, max: Money},
 *     grossBaseCapacity: array{min: Money, max: Money},
 *     segments: list<array{
 *         isMandatory: bool,
 *         base: array{min: Money, max: Money},
 *         quote: array{min: Money, max: Money},
 *         grossBase: array{min: Money, max: Money},
 *     }>,
 * }
 * @phpstan-type Graph array<string, array{currency: string, edges: list<GraphEdge>}>
 */
final class PathFinder
{
    private const SCALE = 18;

    private readonly string $unitValue;
    private readonly string $toleranceAmplifier;
    private readonly bool $hasTolerance;

    /**
     * @param int   $maxHops   maximum number of edges a path may contain
     * @param float $tolerance value in the [0, 1) range representing the acceptable degradation of the best product
     */
    public function __construct(
        private readonly int $maxHops = 4,
        float $tolerance = 0.0,
    ) {
        if ($maxHops < 1) {
            throw new InvalidArgumentException('Maximum hops must be at least one.');
        }

        if ($tolerance < 0.0 || $tolerance >= 1.0) {
            throw new InvalidArgumentException('Tolerance must be in the [0, 1) range.');
        }

        $this->unitValue = BcMath::normalize('1', self::SCALE);
        $this->toleranceAmplifier = $this->calculateToleranceAmplifier($tolerance);
        $this->hasTolerance = 0.0 < $tolerance;
    }

    /**
     * @param Graph                                    $graph
     * @param callable(array<string, mixed>):bool|null $acceptCandidate
     *
     * @return array{
     *     cost: string,
     *     product: string,
     *     hops: int,
     *     edges: list<array{
     *         from: string,
     *         to: string,
     *         order: Order,
     *         rate: ExchangeRate,
     *         orderSide: OrderSide,
     *         conversionRate: string,
     *     }>,
     * }|null
     */
    public function findBestPath(
        array $graph,
        string $source,
        string $target,
        ?Money $desiredSpend = null,
        ?callable $acceptCandidate = null
    ): ?array {
        $source = strtoupper($source);
        $target = strtoupper($target);

        if (!array_key_exists($source, $graph) || !array_key_exists($target, $graph)) {
            return null;
        }

        $queue = $this->createQueue();
        $insertionOrder = 0;

        $queue->insert([
            'node' => $source,
            'cost' => $this->unitValue,
            'product' => $this->unitValue,
            'hops' => 0,
            'path' => [],
            'amount' => $desiredSpend,
        ], ['cost' => $this->unitValue, 'order' => $insertionOrder++]);

        /**
         * @var array<string, list<array{cost: string, hops: int}>> $bestPerNode
         */
        $bestPerNode = [$source => [['cost' => $this->unitValue, 'hops' => 0]]];

        $bestTargetState = null;
        $bestTargetCost = null;
        $bestFeasibleCandidate = null;
        $bestFeasibleCost = null;

        while (!$queue->isEmpty()) {
            /** @var array{node: string, cost: string, product: string, hops: int, path: list<array<string, mixed>>, amount: Money|null} $state */
            $state = $queue->extract();

            if ($state['node'] === $target) {
                if (null === $bestTargetCost || -1 === BcMath::comp($state['cost'], $bestTargetCost, self::SCALE)) {
                    $bestTargetCost = $state['cost'];
                }

                $candidate = [
                    'cost' => $state['cost'],
                    'product' => $state['product'],
                    'hops' => $state['hops'],
                    'edges' => $state['path'],
                ];

                if (null !== $acceptCandidate) {
                    if ($acceptCandidate($candidate)) {
                        if (null === $bestFeasibleCost || -1 === BcMath::comp($state['cost'], $bestFeasibleCost, self::SCALE)) {
                            $bestFeasibleCost = $state['cost'];
                            $bestFeasibleCandidate = $candidate;
                        }
                    }

                    if (null === $bestTargetState || -1 === BcMath::comp($state['cost'], $bestTargetState['cost'], self::SCALE)) {
                        $bestTargetState = $candidate;
                    }

                    continue;
                }

                if (null === $bestTargetState || -1 === BcMath::comp($state['cost'], $bestTargetState['cost'], self::SCALE)) {
                    $bestTargetState = $candidate;
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

                $conversionRate = $this->edgeEffectiveConversionRate($edge);
                if (1 !== BcMath::comp($conversionRate, '0', self::SCALE)) {
                    continue;
                }

                $currentAmount = $state['amount'];
                if ($currentAmount instanceof Money) {
                    if (!$this->edgeSupportsAmount($edge, $currentAmount)) {
                        continue;
                    }

                    $nextAmount = $this->calculateNextAmount($edge, $currentAmount);
                } else {
                    $nextAmount = null;
                }

                $nextCost = BcMath::div($state['cost'], $conversionRate, self::SCALE);
                $nextProduct = BcMath::mul($state['product'], $conversionRate, self::SCALE);
                $nextHops = $state['hops'] + 1;

                if ($this->isDominated($bestPerNode[$nextNode] ?? [], $nextCost, $nextHops)) {
                    continue;
                }

                if (null !== $bestTargetCost) {
                    $maxAllowedCost = $this->hasTolerance
                        ? BcMath::mul($bestTargetCost, $this->toleranceAmplifier, self::SCALE)
                        : $bestTargetCost;
                    if (1 === BcMath::comp($nextCost, $maxAllowedCost, self::SCALE)) {
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
                    'product' => $nextProduct,
                    'hops' => $nextHops,
                    'path' => $nextPath,
                    'amount' => $nextAmount,
                ];

                $this->recordState($bestPerNode, $nextNode, $nextCost, $nextHops);

                $queue->insert($nextState, ['cost' => $nextCost, 'order' => $insertionOrder++]);
            }
        }

        if (null !== $acceptCandidate) {
            return $bestFeasibleCandidate;
        }

        if (null === $bestTargetState) {
            return null;
        }

        return $bestTargetState;
    }

    /**
     * @param list<array{cost: string, hops: int}> $existing
     */
    private function isDominated(array $existing, string $cost, int $hops): bool
    {
        foreach ($existing as $state) {
            if (
                BcMath::comp($state['cost'], $cost, self::SCALE) <= 0
                && $state['hops'] <= $hops
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, list<array{cost: string, hops: int}>> $registry
     */
    private function recordState(array &$registry, string $node, string $cost, int $hops): void
    {
        $existing = $registry[$node] ?? [];

        foreach ($existing as $index => $state) {
            if (
                BcMath::comp($cost, $state['cost'], self::SCALE) <= 0
                && $hops <= $state['hops']
            ) {
                unset($existing[$index]);
            }
        }

        $existing[] = ['cost' => $cost, 'hops' => $hops];
        $registry[$node] = array_values($existing);
    }

    /**
     * @return SplPriorityQueue<array{cost: string, order: int}, array{node: string, cost: string, product: string, hops: int, path: list<array<string, mixed>>, amount: Money|null}>
     */
    private function createQueue(): SplPriorityQueue
    {
        $queue = new class(self::SCALE) extends SplPriorityQueue {
            public function __construct(private readonly int $scale)
            {
                $this->setExtractFlags(self::EXTR_DATA);
            }

            public function compare($priority1, $priority2): int
            {
                $comparison = BcMath::comp($priority1['cost'], $priority2['cost'], $this->scale);
                if (0 !== $comparison) {
                    return -$comparison;
                }

                return $priority2['order'] <=> $priority1['order'];
            }
        };

        return $queue;
    }

    /**
     * @param array{orderSide: OrderSide, segments: list<array{isMandatory: bool, base: array{min: Money, max: Money}, quote: array{min: Money, max: Money}, grossBase: array{min: Money, max: Money}}>} $edge
     */
    private function edgeSupportsAmount(array $edge, Money $amount): bool
    {
        $key = OrderSide::BUY === $edge['orderSide'] ? 'grossBase' : 'quote';

        $scale = $amount->scale();
        foreach ($edge['segments'] as $segment) {
            $scale = max(
                $scale,
                $segment[$key]['min']->scale(),
                $segment[$key]['max']->scale(),
            );
        }

        $normalized = $amount->withScale($scale);
        $minimum = Money::zero($normalized->currency(), $scale);
        $maximum = Money::zero($normalized->currency(), $scale);

        foreach ($edge['segments'] as $segment) {
            if ($segment['isMandatory']) {
                $minimum = $minimum->add($segment[$key]['min']->withScale($scale));
            }

            $maximum = $maximum->add($segment[$key]['max']->withScale($scale));
        }

        if ($normalized->compare($minimum) < 0) {
            return false;
        }

        if ($maximum->isZero()) {
            return $normalized->isZero();
        }

        return $normalized->compare($maximum) <= 0;
    }

    /**
     * @param array{to: string, orderSide: OrderSide, baseCapacity: array{min: Money, max: Money}, quoteCapacity: array{min: Money, max: Money}, grossBaseCapacity: array{min: Money, max: Money}} $edge
     */
    private function calculateNextAmount(array $edge, Money $current): Money
    {
        $conversionRate = $this->edgeEffectiveConversionRate($edge);
        if (1 !== BcMath::comp($conversionRate, '0', self::SCALE)) {
            return Money::zero($edge['to'], max($current->scale(), self::SCALE));
        }

        [$sourceScale, $targetScale] = match ($edge['orderSide']) {
            OrderSide::BUY => [
                max(
                    $edge['grossBaseCapacity']['max']->scale(),
                    $edge['grossBaseCapacity']['min']->scale(),
                    $current->scale(),
                    self::SCALE,
                ),
                max(
                    $edge['quoteCapacity']['max']->scale(),
                    $edge['quoteCapacity']['min']->scale(),
                    $current->scale(),
                    self::SCALE,
                ),
            ],
            OrderSide::SELL => [
                max(
                    $edge['quoteCapacity']['max']->scale(),
                    $edge['quoteCapacity']['min']->scale(),
                    $current->scale(),
                    self::SCALE,
                ),
                max(
                    $edge['baseCapacity']['max']->scale(),
                    $edge['baseCapacity']['min']->scale(),
                    $current->scale(),
                    self::SCALE,
                ),
            ],
        };

        $operationScale = max($sourceScale, $targetScale, self::SCALE);
        $normalizedCurrent = $current->withScale($operationScale)->amount();
        $raw = BcMath::mul($normalizedCurrent, $conversionRate, $operationScale + 2);
        $normalized = BcMath::normalize($raw, $targetScale);

        return Money::fromString($edge['to'], $normalized, $targetScale);
    }

    /**
     * @param array{orderSide: OrderSide, baseCapacity: array{min: Money, max: Money}, quoteCapacity: array{min: Money, max: Money}, grossBaseCapacity: array{min: Money, max: Money}} $edge
     */
    private function edgeEffectiveConversionRate(array $edge): string
    {
        $baseToQuote = $this->edgeBaseToQuoteRatio($edge);
        if (1 !== BcMath::comp($baseToQuote, '0', self::SCALE)) {
            return $baseToQuote;
        }

        if (OrderSide::SELL === $edge['orderSide']) {
            return BcMath::div('1', $baseToQuote, self::SCALE);
        }

        return $baseToQuote;
    }

    /**
     * @param array{orderSide: OrderSide, baseCapacity: array{min: Money, max: Money}, quoteCapacity: array{min: Money, max: Money}, grossBaseCapacity: array{min: Money, max: Money}} $edge
     */
    private function edgeBaseToQuoteRatio(array $edge): string
    {
        $baseCapacity = OrderSide::BUY === $edge['orderSide']
            ? $edge['grossBaseCapacity']
            : $edge['baseCapacity'];

        $baseScale = max($baseCapacity['min']->scale(), $baseCapacity['max']->scale());
        $quoteScale = max($edge['quoteCapacity']['min']->scale(), $edge['quoteCapacity']['max']->scale());

        $baseMax = $baseCapacity['max']->withScale($baseScale)->amount();
        if (0 === BcMath::comp($baseMax, '0', $baseScale)) {
            return BcMath::normalize('0', self::SCALE);
        }

        $quoteMax = $edge['quoteCapacity']['max']->withScale($quoteScale)->amount();

        return BcMath::div($quoteMax, $baseMax, self::SCALE);
    }

    private function calculateToleranceAmplifier(float $tolerance): string
    {
        if (0.0 === $tolerance) {
            return BcMath::normalize('1', self::SCALE);
        }

        $upperBound = 1 - 10 ** (-self::SCALE);
        if ($tolerance > $upperBound) {
            $tolerance = $upperBound;
        }

        $toleranceString = $this->formatFloat($tolerance);
        $normalizedTolerance = BcMath::normalize($toleranceString, self::SCALE);
        $complement = BcMath::sub('1', $normalizedTolerance, self::SCALE);

        return BcMath::div('1', $complement, self::SCALE);
    }

    private function formatFloat(float $value): string
    {
        return rtrim(rtrim(sprintf('%.'.self::SCALE.'F', $value), '0'), '.');
    }
}
