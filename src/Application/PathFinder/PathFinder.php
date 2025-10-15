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
use function is_string;
use function rtrim;
use function sprintf;
use function str_repeat;
use function strtoupper;
use function usort;

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
 * @phpstan-type SpendRange array{min: Money, max: Money}
 * @phpstan-type SpendConstraints array{min?: Money, max?: Money, desired?: Money|null}
 */
final class PathFinder
{
    private const SCALE = 18;

    public const DEFAULT_MAX_EXPANSIONS = 250000;

    public const DEFAULT_MAX_VISITED_STATES = 250000;

    private readonly string $unitValue;
    private readonly string $toleranceAmplifier;
    private readonly bool $hasTolerance;

    /**
     * @param int          $maxHops   maximum number of edges a path may contain
     * @param float|string $tolerance value in the [0, 1) range representing the acceptable degradation of the best product
     */
    public function __construct(
        private readonly int $maxHops = 4,
        float|string $tolerance = 0.0,
        private readonly int $topK = 1,
        private readonly int $maxExpansions = self::DEFAULT_MAX_EXPANSIONS,
        private readonly int $maxVisitedStates = self::DEFAULT_MAX_VISITED_STATES,
    ) {
        if ($maxHops < 1) {
            throw new InvalidArgumentException('Maximum hops must be at least one.');
        }

        if ($this->topK < 1) {
            throw new InvalidArgumentException('Result limit must be at least one.');
        }

        if ($this->maxExpansions < 1) {
            throw new InvalidArgumentException('Maximum expansions must be at least one.');
        }

        if ($this->maxVisitedStates < 1) {
            throw new InvalidArgumentException('Maximum visited states must be at least one.');
        }

        $this->unitValue = BcMath::normalize('1', self::SCALE);
        $normalizedTolerance = $this->normalizeTolerance($tolerance);
        $this->toleranceAmplifier = $this->calculateToleranceAmplifier($normalizedTolerance);
        $this->hasTolerance = 1 === BcMath::comp($normalizedTolerance, '0', self::SCALE);
    }

    /**
     * @param Graph                                    $graph
     * @param SpendConstraints|null                    $spendConstraints
     * @param callable(array<string, mixed>):bool|null $acceptCandidate
     *
     * @return list<array{
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
     *     amountRange: SpendRange|null,
     *     desiredAmount: Money|null,
     * }>
     */
    public function findBestPaths(
        array $graph,
        string $source,
        string $target,
        ?array $spendConstraints = null,
        ?callable $acceptCandidate = null
    ): array {
        $source = strtoupper($source);
        $target = strtoupper($target);

        if (!array_key_exists($source, $graph) || !array_key_exists($target, $graph)) {
            return [];
        }

        $range = null;
        $desiredSpend = null;
        if (null !== $spendConstraints) {
            if (!isset($spendConstraints['min'], $spendConstraints['max'])) {
                throw new InvalidArgumentException('Spend constraints must include both minimum and maximum bounds.');
            }

            $range = [
                'min' => $spendConstraints['min'],
                'max' => $spendConstraints['max'],
            ];
            $desiredSpend = $spendConstraints['desired'] ?? null;
        }

        $queue = $this->createQueue();
        $results = $this->createResultHeap();
        $insertionOrder = 0;
        $resultInsertionOrder = 0;

        $queue->insert([
            'node' => $source,
            'cost' => $this->unitValue,
            'product' => $this->unitValue,
            'hops' => 0,
            'path' => [],
            'amountRange' => $range,
            'desiredAmount' => $desiredSpend,
            'visited' => [$source => true],
        ], ['cost' => $this->unitValue, 'order' => $insertionOrder++]);

        /**
         * @var array<string, list<array{cost: string, hops: int, signature: string}>> $bestPerNode
         */
        $bestPerNode = [
            $source => [[
                'cost' => $this->unitValue,
                'hops' => 0,
                'signature' => $this->stateSignature($range, $desiredSpend),
            ]],
        ];

        $bestTargetCost = null;
        $expansions = 0;
        $visitedStates = 1;

        while (!$queue->isEmpty()) {
            if ($expansions >= $this->maxExpansions) {
                break;
            }

            /** @var array{node: string, cost: string, product: string, hops: int, path: list<array<string, mixed>>, amountRange: SpendRange|null, desiredAmount: Money|null, visited: array<string, bool>} $state */
            $state = $queue->extract();
            ++$expansions;

            if ($state['node'] === $target) {
                if (null === $bestTargetCost || -1 === BcMath::comp($state['cost'], $bestTargetCost, self::SCALE)) {
                    $bestTargetCost = $state['cost'];
                }

                $candidate = [
                    'cost' => $state['cost'],
                    'product' => $state['product'],
                    'hops' => $state['hops'],
                    'edges' => $state['path'],
                    'amountRange' => $state['amountRange'],
                    'desiredAmount' => $state['desiredAmount'],
                ];

                if (null === $acceptCandidate || $acceptCandidate($candidate)) {
                    $this->recordResult($results, $candidate, $resultInsertionOrder++);
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

                if (isset($state['visited'][$nextNode])) {
                    continue;
                }

                $conversionRate = $this->edgeEffectiveConversionRate($edge);
                if (1 !== BcMath::comp($conversionRate, '0', self::SCALE)) {
                    continue;
                }

                $currentRange = $state['amountRange'];
                $currentDesired = $state['desiredAmount'];
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

                $nextCost = BcMath::div($state['cost'], $conversionRate, self::SCALE);
                $nextProduct = BcMath::mul($state['product'], $conversionRate, self::SCALE);
                $nextHops = $state['hops'] + 1;

                $signature = $this->stateSignature($nextRange, $nextDesired);

                if ($this->isDominated($bestPerNode[$nextNode] ?? [], $nextCost, $nextHops, $signature)) {
                    continue;
                }

                if (
                    $visitedStates >= $this->maxVisitedStates
                    && !$this->hasStateWithSignature($bestPerNode[$nextNode] ?? [], $signature)
                ) {
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

                $nextVisited = $state['visited'];
                $nextVisited[$nextNode] = true;

                $nextState = [
                    'node' => $nextNode,
                    'cost' => $nextCost,
                    'product' => $nextProduct,
                    'hops' => $nextHops,
                    'path' => $nextPath,
                    'amountRange' => $nextRange,
                    'desiredAmount' => $nextDesired,
                    'visited' => $nextVisited,
                ];

                $visitedStates = max(
                    0,
                    $visitedStates + $this->recordState(
                        $bestPerNode,
                        $nextNode,
                        $nextCost,
                        $nextHops,
                        $nextRange,
                        $nextDesired,
                        $signature,
                    ),
                );

                $queue->insert($nextState, ['cost' => $nextCost, 'order' => $insertionOrder++]);
            }
        }

        if (0 === $results->count()) {
            return [];
        }

        return $this->finalizeResults($results);
    }

    /**
     * @param list<array{cost: string, hops: int, signature: string}> $existing
     */
    private function isDominated(array $existing, string $cost, int $hops, string $signature): bool
    {
        foreach ($existing as $state) {
            if ($state['signature'] !== $signature) {
                continue;
            }

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
     * @param array<string, list<array{cost: string, hops: int, signature: string}>> $registry
     * @param array{min: Money, max: Money}|null                                     $range
     *
     * @return int net change in the number of tracked states
     */
    private function recordState(
        array &$registry,
        string $node,
        string $cost,
        int $hops,
        ?array $range,
        ?Money $desired,
        ?string $signature = null
    ): int {
        $signature ??= $this->stateSignature($range, $desired);
        $existing = $registry[$node] ?? [];
        $removed = 0;

        foreach ($existing as $index => $state) {
            if ($state['signature'] !== $signature) {
                continue;
            }

            if (
                BcMath::comp($cost, $state['cost'], self::SCALE) <= 0
                && $hops <= $state['hops']
            ) {
                unset($existing[$index]);
                ++$removed;
            }
        }

        $existing[] = ['cost' => $cost, 'hops' => $hops, 'signature' => $signature];
        $registry[$node] = array_values($existing);

        return 1 - $removed;
    }

    /**
     * @param array{min: Money, max: Money}|null $range
     */
    private function stateSignature(?array $range, ?Money $desired): string
    {
        if (null === $range) {
            return 'range:null|desired:'.$this->moneySignature($desired);
        }

        $scale = max($range['min']->scale(), $range['max']->scale());
        if ($desired instanceof Money) {
            $scale = max($scale, $desired->scale());
        }

        $minimum = $range['min']->withScale($scale);
        $maximum = $range['max']->withScale($scale);

        $rangeSignature = sprintf(
            '%s:%s:%s:%d',
            $minimum->currency(),
            $minimum->amount(),
            $maximum->amount(),
            $scale,
        );

        return 'range:'.$rangeSignature.'|desired:'.$this->moneySignature($desired, $scale);
    }

    private function moneySignature(?Money $amount, ?int $scale = null): string
    {
        if (null === $amount) {
            return 'null';
        }

        $scale ??= $amount->scale();
        $normalized = $amount->withScale($scale);

        return sprintf('%s:%s:%d', $normalized->currency(), $normalized->amount(), $scale);
    }

    /**
     * @param list<array{cost: string, hops: int, signature: string}> $existing
     */
    private function hasStateWithSignature(array $existing, string $signature): bool
    {
        foreach ($existing as $state) {
            if ($state['signature'] === $signature) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return SplPriorityQueue<array{cost: string, order: int}, array{node: string, cost: string, product: string, hops: int, path: list<array<string, mixed>>, amountRange: SpendRange|null, desiredAmount: Money|null}>
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
     * @return SplPriorityQueue<array{candidate: array<string, mixed>, order: int, cost: string}, array{candidate: array<string, mixed>, order: int, cost: string}>
     */
    private function createResultHeap(): SplPriorityQueue
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
                    return $comparison;
                }

                return $priority1['order'] <=> $priority2['order'];
            }
        };

        return $queue;
    }

    /**
     * @param SplPriorityQueue<array{candidate: array<string, mixed>, order: int, cost: string}, array{candidate: array<string, mixed>, order: int, cost: string}> $results
     * @param array{
     *     cost: string,
     *     product: string,
     *     hops: int,
     *     edges: list<array<string, mixed>>,
     *     amountRange: SpendRange|null,
     *     desiredAmount: Money|null,
     * } $candidate
     */
    private function recordResult(SplPriorityQueue $results, array $candidate, int $order): void
    {
        $entry = [
            'candidate' => $candidate,
            'order' => $order,
            'cost' => $candidate['cost'],
        ];

        $results->insert($entry, $entry);

        if ($results->count() > $this->topK) {
            $results->extract();
        }
    }

    /**
     * @param SplPriorityQueue<array{candidate: array<string, mixed>, order: int, cost: string}, array{candidate: array<string, mixed>, order: int, cost: string}> $results
     *
     * @return list<array{
     *     cost: string,
     *     product: string,
     *     hops: int,
     *     edges: list<array<string, mixed>>,
     *     amountRange: SpendRange|null,
     *     desiredAmount: Money|null,
     * }>
     */
    private function finalizeResults(SplPriorityQueue $results): array
    {
        $collected = [];
        $clone = clone $results;

        while (!$clone->isEmpty()) {
            $collected[] = $clone->extract();
        }

        usort(
            $collected,
            function (array $left, array $right): int {
                $comparison = BcMath::comp($left['cost'], $right['cost'], self::SCALE);
                if (0 !== $comparison) {
                    return $comparison;
                }

                return $left['order'] <=> $right['order'];
            },
        );

        return array_map(
            static fn (array $entry): array => $entry['candidate'],
            $collected,
        );
    }

    /**
     * @param array{orderSide: OrderSide, segments: list<array{isMandatory: bool, base: array{min: Money, max: Money}, quote: array{min: Money, max: Money}, grossBase: array{min: Money, max: Money}}>} $edge
     * @param SpendRange                                                                                                                                                                                 $range
     *
     * @return SpendRange|null
     */
    private function edgeSupportsAmount(array $edge, array $range): ?array
    {
        $key = OrderSide::BUY === $edge['orderSide'] ? 'grossBase' : 'quote';

        $scale = max($range['min']->scale(), $range['max']->scale());
        foreach ($edge['segments'] as $segment) {
            $scale = max(
                $scale,
                $segment[$key]['min']->scale(),
                $segment[$key]['max']->scale(),
            );
        }

        $requestedMin = $range['min']->withScale($scale);
        $requestedMax = $range['max']->withScale($scale);

        if ($requestedMin->greaterThan($requestedMax)) {
            [$requestedMin, $requestedMax] = [$requestedMax, $requestedMin];
        }

        $minimum = Money::zero($requestedMin->currency(), $scale);
        $maximum = Money::zero($requestedMin->currency(), $scale);

        foreach ($edge['segments'] as $segment) {
            if ($segment['isMandatory']) {
                $minimum = $minimum->add($segment[$key]['min']->withScale($scale));
            }

            $maximum = $maximum->add($segment[$key]['max']->withScale($scale));
        }

        if ($maximum->isZero()) {
            $zero = Money::zero($requestedMin->currency(), $scale);

            if ($minimum->greaterThan($zero)) {
                return null;
            }

            if ($requestedMin->compare($zero) > 0 || $requestedMax->compare($zero) < 0) {
                return null;
            }

            return ['min' => $zero, 'max' => $zero];
        }

        if ($requestedMax->lessThan($minimum) || $requestedMin->greaterThan($maximum)) {
            return null;
        }

        $lowerBound = $requestedMin->greaterThan($minimum) ? $requestedMin : $minimum;
        $upperBound = $requestedMax->lessThan($maximum) ? $requestedMax : $maximum;

        return ['min' => $lowerBound, 'max' => $upperBound];
    }

    /**
     * @param array{to: string, orderSide: OrderSide, baseCapacity: array{min: Money, max: Money}, quoteCapacity: array{min: Money, max: Money}, grossBaseCapacity: array{min: Money, max: Money}} $edge
     * @param SpendRange                                                                                                                                                                           $range
     *
     * @return SpendRange
     */
    private function calculateNextRange(array $edge, array $range): array
    {
        $minimum = $this->convertEdgeAmount($edge, $range['min']);
        $maximum = $this->convertEdgeAmount($edge, $range['max']);

        if ($minimum->greaterThan($maximum)) {
            [$minimum, $maximum] = [$maximum, $minimum];
        }

        return ['min' => $minimum, 'max' => $maximum];
    }

    /**
     * @param array{to: string, orderSide: OrderSide, baseCapacity: array{min: Money, max: Money}, quoteCapacity: array{min: Money, max: Money}, grossBaseCapacity: array{min: Money, max: Money}} $edge
     */
    private function convertEdgeAmount(array $edge, Money $current): Money
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
     * @param SpendRange $range
     */
    private function clampToRange(Money $value, array $range): Money
    {
        $scale = max($value->scale(), $range['min']->scale(), $range['max']->scale());

        $normalizedValue = $value->withScale($scale);
        $minimum = $range['min']->withScale($scale);
        $maximum = $range['max']->withScale($scale);

        if ($normalizedValue->lessThan($minimum)) {
            return $minimum;
        }

        if ($normalizedValue->greaterThan($maximum)) {
            return $maximum;
        }

        return $normalizedValue;
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

    private function normalizeTolerance(float|string $tolerance): string
    {
        if (is_string($tolerance)) {
            if (!BcMath::isNumeric($tolerance)) {
                throw new InvalidArgumentException('Tolerance must be numeric.');
            }

            if (-1 === BcMath::comp($tolerance, '0', self::SCALE)) {
                throw new InvalidArgumentException('Tolerance must be non-negative.');
            }

            if (BcMath::comp($tolerance, '1', self::SCALE) >= 0) {
                throw new InvalidArgumentException('Tolerance must be less than one.');
            }

            $normalized = BcMath::normalize($tolerance, self::SCALE);
        } else {
            if ($tolerance < 0.0 || $tolerance >= 1.0) {
                throw new InvalidArgumentException('Tolerance must be in the [0, 1) range.');
            }

            $normalized = BcMath::normalize($this->formatFloat($tolerance), self::SCALE);
        }

        $upperBound = '0.'.str_repeat('9', self::SCALE);
        if (1 === BcMath::comp($normalized, $upperBound, self::SCALE)) {
            return $upperBound;
        }

        return $normalized;
    }

    private function calculateToleranceAmplifier(string $tolerance): string
    {
        if (0 === BcMath::comp($tolerance, '0', self::SCALE)) {
            return BcMath::normalize('1', self::SCALE);
        }

        $normalizedTolerance = BcMath::normalize($tolerance, self::SCALE);
        $complement = BcMath::sub('1', $normalizedTolerance, self::SCALE);

        return BcMath::div('1', $complement, self::SCALE);
    }

    private function formatFloat(float $value): string
    {
        return rtrim(rtrim(sprintf('%.'.self::SCALE.'F', $value), '0'), '.');
    }
}
