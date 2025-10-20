<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder;

use SomeWork\P2PPathFinder\Application\PathFinder\Result\GuardLimitStatus;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\CostHopsSignatureOrderingStrategy;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathOrderKey;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathOrderStrategy;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\SearchOutcome;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;
use SplPriorityQueue;

use function array_key_exists;
use function array_map;
use function array_values;
use function implode;
use function microtime;
use function sprintf;
use function str_repeat;
use function strtoupper;
use function usort;

/**
 * Implementation of a tolerance-aware best-path search through the trading graph.
 *
 * @psalm-type GraphEdge = array{
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
 *
 * @psalm-type Graph = array<string, array{currency: string, edges: list<GraphEdge>}>
 *
 * @phpstan-type Graph array<string, array{currency: string, edges: list<GraphEdge>}>
 *
 * @psalm-type SpendRange = array{min: Money, max: Money}
 *
 * @phpstan-type SpendRange array{min: Money, max: Money}
 *
 * @psalm-type SpendConstraints = array{min?: Money, max?: Money, desired?: Money|null}
 *
 * @phpstan-type SpendConstraints array{min?: Money, max?: Money, desired?: Money|null}
 *
 * @psalm-type PathEdge = array{
 *     from: string,
 *     to: string,
 *     order: Order,
 *     rate: ExchangeRate,
 *     orderSide: OrderSide,
 *     conversionRate: numeric-string,
 * }
 *
 * @phpstan-type PathEdge array{
 *     from: string,
 *     to: string,
 *     order: Order,
 *     rate: ExchangeRate,
 *     orderSide: OrderSide,
 *     conversionRate: string,
 * }
 *
 * @psalm-type Candidate = array{
 *     cost: numeric-string,
 *     product: numeric-string,
 *     hops: int,
 *     edges: list<PathEdge>,
 *     amountRange: SpendRange|null,
 *     desiredAmount: Money|null,
 * }
 *
 * @phpstan-type Candidate array{
 *     cost: string,
 *     product: string,
 *     hops: int,
 *     edges: list<PathEdge>,
 *     amountRange: SpendRange|null,
 *     desiredAmount: Money|null,
 * }
 *
 * @psalm-type CandidateHeapEntry = array{
 *     candidate: Candidate,
 *     order: int,
 *     cost: numeric-string,
 * }
 *
 * @phpstan-type CandidateHeapEntry array{
 *     candidate: Candidate,
 *     order: int,
 *     cost: numeric-string,
 * }
 *
 * @psalm-type CandidateResultEntry = array{
 *     candidate: Candidate,
 *     order: int,
 *     cost: numeric-string,
 *     routeSignature: string,
 *     orderKey: PathOrderKey,
 * }
 *
 * @phpstan-type CandidateResultEntry array{
 *     candidate: Candidate,
 *     order: int,
 *     cost: string,
 *     routeSignature: string,
 *     orderKey: PathOrderKey,
 * }
 *
 * @psalm-type SearchQueueEntry = array{
 *     state: SearchState,
 *     priority: array{cost: numeric-string, order: int},
 * }
 *
 * @phpstan-type SearchQueueEntry array{
 *     state: SearchState,
 *     priority: array{cost: numeric-string, order: int},
 * }
 *
 * @psalm-type SearchState = array{
 *     node: string,
 *     cost: numeric-string,
 *     product: numeric-string,
 *     hops: int,
 *     path: list<PathEdge>,
 *     amountRange: SpendRange|null,
 *     desiredAmount: Money|null,
 *     visited: array<string, bool>,
 * }
 *
 * @phpstan-type SearchState array{
 *     node: string,
 *     cost: numeric-string,
 *     product: numeric-string,
 *     hops: int,
 *     path: list<PathEdge>,
 *     amountRange: SpendRange|null,
 *     desiredAmount: Money|null,
 *     visited: array<string, bool>,
 * }
 */
final class PathFinder
{
    private const SCALE = 18;
    /**
     * Extra precision used when converting target and source deltas into a ratio to avoid premature rounding.
     */
    private const RATIO_EXTRA_SCALE = 4;
    /**
     * Extra precision used when applying the ratio to offsets before normalizing to the target scale.
     */
    private const SUM_EXTRA_SCALE = 2;

    public const DEFAULT_MAX_EXPANSIONS = 250000;

    public const DEFAULT_MAX_VISITED_STATES = 250000;

    /** @var numeric-string */
    private readonly string $unitValue;

    /** @var numeric-string */
    private readonly string $toleranceAmplifier;
    private readonly bool $hasTolerance;
    private readonly PathOrderStrategy $orderingStrategy;

    /**
     * @param int    $maxHops   maximum number of edges a path may contain
     * @param string $tolerance value in the [0, 1) range representing the acceptable degradation of the best product
     */
    public function __construct(
        private readonly int $maxHops = 4,
        string $tolerance = '0',
        private readonly int $topK = 1,
        private readonly int $maxExpansions = self::DEFAULT_MAX_EXPANSIONS,
        private readonly int $maxVisitedStates = self::DEFAULT_MAX_VISITED_STATES,
        ?PathOrderStrategy $orderingStrategy = null,
        private readonly ?int $timeBudgetMs = null,
    ) {
        if ($maxHops < 1) {
            throw new InvalidInput('Maximum hops must be at least one.');
        }

        if ($this->topK < 1) {
            throw new InvalidInput('Result limit must be at least one.');
        }

        if ($this->maxExpansions < 1) {
            throw new InvalidInput('Maximum expansions must be at least one.');
        }

        if ($this->maxVisitedStates < 1) {
            throw new InvalidInput('Maximum visited states must be at least one.');
        }

        if (null !== $this->timeBudgetMs && $this->timeBudgetMs < 1) {
            throw new InvalidInput('Time budget must be at least one millisecond.');
        }

        /** @var numeric-string $unit */
        $unit = BcMath::normalize('1', self::SCALE);
        $this->unitValue = $unit;
        $normalizedTolerance = $this->normalizeTolerance($tolerance);
        /** @var numeric-string $amplifier */
        $amplifier = $this->calculateToleranceAmplifier($normalizedTolerance);
        $this->toleranceAmplifier = $amplifier;
        $this->hasTolerance = 1 === BcMath::comp($normalizedTolerance, '0', self::SCALE);
        $this->orderingStrategy = $orderingStrategy ?? new CostHopsSignatureOrderingStrategy(self::SCALE);
    }

    /**
     * @param Graph                         $graph
     * @param SpendConstraints|null         $spendConstraints
     * @param callable(Candidate):bool|null $acceptCandidate
     *
     * @return SearchOutcome<Candidate>
     *
     * @phpstan-return SearchOutcome<Candidate>
     *
     * @psalm-return SearchOutcome<Candidate>
     */
    public function findBestPaths(
        array $graph,
        string $source,
        string $target,
        ?array $spendConstraints = null,
        ?callable $acceptCandidate = null
    ): SearchOutcome {
        $source = strtoupper($source);
        $target = strtoupper($target);

        if (!array_key_exists($source, $graph) || !array_key_exists($target, $graph)) {
            /** @var SearchOutcome<Candidate> $empty */
            $empty = SearchOutcome::empty(GuardLimitStatus::none());

            return $empty;
        }

        $range = null;
        $desiredSpend = null;
        if (null !== $spendConstraints) {
            if (!isset($spendConstraints['min'], $spendConstraints['max'])) {
                throw new InvalidInput('Spend constraints must include both minimum and maximum bounds.');
            }

            $range = [
                'min' => $spendConstraints['min'],
                'max' => $spendConstraints['max'],
            ];
            $desiredSpend = $spendConstraints['desired'] ?? null;
        }

        [
            $queue,
            $results,
            $bestPerNode,
            $insertionOrder,
            $resultInsertionOrder,
            $visitedStates,
        ] = $this->initializeSearchStructures($source, $range, $desiredSpend);

        $bestTargetCost = null;
        $expansions = 0;

        $expansionGuardReached = false;
        $visitedGuardReached = false;
        $timeGuardReached = false;

        $searchStartedAt = microtime(true);

        while (!$queue->isEmpty()) {
            if (null !== $this->timeBudgetMs && (microtime(true) - $searchStartedAt) * 1000 >= $this->timeBudgetMs) {
                $timeGuardReached = true;
                break;
            }

            if ($expansions >= $this->maxExpansions) {
                $expansionGuardReached = true;
                break;
            }

            /** @var SearchState $state */
            $state = $queue->extract();
            ++$expansions;

            if ($state['node'] === $target) {
                /** @var numeric-string $candidateCost */
                $candidateCost = $state['cost'];
                /** @var numeric-string $candidateProduct */
                $candidateProduct = $state['product'];

                BcMath::ensureNumeric($candidateCost, $candidateProduct);

                $candidate = [
                    'cost' => $candidateCost,
                    'product' => $candidateProduct,
                    'hops' => $state['hops'],
                    'edges' => $state['path'],
                    'amountRange' => $state['amountRange'],
                    'desiredAmount' => $state['desiredAmount'],
                ];

                if (null === $acceptCandidate || $acceptCandidate($candidate)) {
                    if (
                        null === $bestTargetCost
                        || -1 === BcMath::comp($candidate['cost'], $bestTargetCost, self::SCALE)
                    ) {
                        $bestTargetCost = $candidate['cost'];
                    }

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
                /** @var numeric-string $conversionRate */
                $conversionRate = $conversionRate;
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
                    $visitedGuardReached = true;
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

        $guardLimits = new GuardLimitStatus($expansionGuardReached, $visitedGuardReached, $timeGuardReached);

        if (0 === $results->count()) {
            /** @var SearchOutcome<Candidate> $empty */
            $empty = SearchOutcome::empty($guardLimits);

            return $empty;
        }

        /** @var list<Candidate> $finalized */
        $finalized = $this->finalizeResults($results);

        return new SearchOutcome($finalized, $guardLimits);
    }

    /**
     * @param list<array{cost: numeric-string, hops: int, signature: string}> $existing
     * @param numeric-string                                                  $cost
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
     * @param array<string, list<array{cost: numeric-string, hops: int, signature: string}>> $registry
     * @param array{min: Money, max: Money}|null                                             $range
     * @param numeric-string                                                                 $cost
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
     * @param list<array{cost: numeric-string, hops: int, signature: string}> $existing
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

    private function createQueue(): SearchStateQueue
    {
        return new SearchStateQueue(self::SCALE);
    }

    private function createResultHeap(): CandidateResultHeap
    {
        return new CandidateResultHeap(self::SCALE);
    }

    /**
     * @phpstan-param Candidate $candidate
     *
     * @psalm-param Candidate $candidate
     */
    private function recordResult(CandidateResultHeap $results, array $candidate, int $order): void
    {
        /** @var numeric-string $candidateCost */
        $candidateCost = $candidate['cost'];
        $entry = [
            'candidate' => $candidate,
            'order' => $order,
            'cost' => $candidateCost,
        ];

        /* @var CandidateHeapEntry $entry */
        $results->insert($entry, $entry);

        if ($results->count() > $this->topK) {
            $results->extract();
        }
    }

    /**
     * @param array{min: Money, max: Money}|null $range
     *
     * @return array{SearchStateQueue, CandidateResultHeap, array<string, list<array{cost: numeric-string, hops: int, signature: string}>>, int, int, int}
     */
    private function initializeSearchStructures(string $source, ?array $range, ?Money $desiredSpend): array
    {
        /** @var array{min: Money, max: Money}|null $range */
        $range = $range;
        $queue = $this->createQueue();
        $results = $this->createResultHeap();
        $insertionOrder = 0;
        $resultInsertionOrder = 0;

        $initialState = [
            'node' => $source,
            'cost' => $this->unitValue,
            'product' => $this->unitValue,
            'hops' => 0,
            'path' => [],
            'amountRange' => $range,
            'desiredAmount' => $desiredSpend,
            'visited' => [$source => true],
        ];

        $queue->insert($initialState, ['cost' => $this->unitValue, 'order' => $insertionOrder++]);

        /**
         * @var array<string, list<array{cost: numeric-string, hops: int, signature: string}>> $bestPerNode
         */
        $bestPerNode = [
            $source => [[
                'cost' => $this->unitValue,
                'hops' => 0,
                'signature' => $this->stateSignature($range, $desiredSpend),
            ]],
        ];

        return [$queue, $results, $bestPerNode, $insertionOrder, $resultInsertionOrder, 1];
    }

    /**
     * @return list<Candidate>
     */
    private function finalizeResults(CandidateResultHeap $results): array
    {
        /** @var list<CandidateResultEntry> $entries */
        $entries = $this->collectResultEntries($results);
        $this->sortResultEntries($entries);

        /** @var list<Candidate> $finalized */
        $finalized = array_map(
            /**
             * @param CandidateResultEntry $entry
             */
            static fn (array $entry): array => $entry['candidate'],
            $entries,
        );

        return $finalized;
    }

    /**
     * @return list<CandidateResultEntry>
     */
    private function collectResultEntries(CandidateResultHeap $results): array
    {
        /**
         * @var list<CandidateResultEntry> $collected
         */
        $collected = [];
        $clone = clone $results;

        while (!$clone->isEmpty()) {
            /** @var CandidateHeapEntry $entry */
            $entry = $clone->extract();
            $entry['routeSignature'] = $this->routeSignature($entry['candidate']['edges']);
            $entry['orderKey'] = new PathOrderKey(
                $entry['cost'],
                $entry['candidate']['hops'],
                $entry['routeSignature'],
                $entry['order'],
                ['candidate' => $entry['candidate']],
            );
            $collected[] = $entry;
        }

        return $collected;
    }

    /**
     * @param list<CandidateResultEntry> $entries
     */
    private function sortResultEntries(array &$entries): void
    {
        usort($entries, [$this, 'compareCandidateEntries']);
    }

    /**
     * @param CandidateResultEntry $left
     * @param CandidateResultEntry $right
     */
    private function compareCandidateEntries(array $left, array $right): int
    {
        return $this->orderingStrategy->compare($left['orderKey'], $right['orderKey']);
    }

    /**
     * @param list<PathEdge> $edges
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
     * @param GraphEdge  $edge
     * @param SpendRange $range
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
     * @param GraphEdge  $edge
     * @param SpendRange $range
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
     * @param GraphEdge $edge
     */
    private function convertEdgeAmount(array $edge, Money $current): Money
    {
        $conversionRate = $this->edgeEffectiveConversionRate($edge);
        if (1 !== BcMath::comp($conversionRate, '0', self::SCALE)) {
            return Money::zero($edge['to'], max($current->scale(), self::SCALE));
        }

        $sourceCapacity = OrderSide::BUY === $edge['orderSide']
            ? $edge['grossBaseCapacity']
            : $edge['quoteCapacity'];
        $targetCapacity = OrderSide::BUY === $edge['orderSide']
            ? $edge['quoteCapacity']
            : $edge['baseCapacity'];

        $sourceScale = max(
            $sourceCapacity['min']->scale(),
            $sourceCapacity['max']->scale(),
            $current->scale(),
            self::SCALE,
        );
        $targetScale = max(
            $targetCapacity['min']->scale(),
            $targetCapacity['max']->scale(),
            self::SCALE,
        );

        $sourceMin = $sourceCapacity['min']->withScale($sourceScale);
        $sourceMax = $sourceCapacity['max']->withScale($sourceScale);
        $clampedCurrent = $current->withScale($sourceScale);

        if ($clampedCurrent->lessThan($sourceMin)) {
            $clampedCurrent = $sourceMin;
        }

        if ($clampedCurrent->greaterThan($sourceMax)) {
            $clampedCurrent = $sourceMax;
        }

        $sourceDelta = $sourceMax->subtract($sourceMin, $sourceScale);
        $targetMin = $targetCapacity['min']->withScale($targetScale);
        $targetMax = $targetCapacity['max']->withScale($targetScale);
        $targetDelta = $targetMax->subtract($targetMin, $targetScale);

        $ratioScale = max($sourceScale, $targetScale, self::SCALE);
        $sourceDeltaAmount = $sourceDelta->withScale($ratioScale)->amount();
        if (0 === BcMath::comp($sourceDeltaAmount, '0', $ratioScale)) {
            return $targetMin->withScale($targetScale);
        }
        $targetDeltaAmount = $targetDelta->withScale($ratioScale)->amount();
        $ratio = BcMath::div(
            $targetDeltaAmount,
            $sourceDeltaAmount,
            $ratioScale + self::RATIO_EXTRA_SCALE,
        );

        $offset = $clampedCurrent->subtract($sourceMin, $sourceScale);
        $offsetAmount = $offset->withScale($ratioScale)->amount();
        $incrementAmount = BcMath::mul(
            $offsetAmount,
            $ratio,
            $ratioScale + self::SUM_EXTRA_SCALE,
        );
        $baseAmount = BcMath::add(
            $targetMin->withScale($ratioScale)->amount(),
            $incrementAmount,
            $ratioScale + self::SUM_EXTRA_SCALE,
        );

        $normalized = BcMath::normalize($baseAmount, $ratioScale + self::SUM_EXTRA_SCALE);
        $result = Money::fromString(
            $edge['to'],
            $normalized,
            $ratioScale + self::SUM_EXTRA_SCALE,
        );

        $converted = $result->withScale($targetScale);

        return $this->clampToRange($converted, ['min' => $targetMin, 'max' => $targetMax]);
    }

    /**
     * @param SpendRange $range
     *
     * @throws PrecisionViolation when normalization fails due to missing BCMath support
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
     * @param GraphEdge $edge
     *
     * @throws PrecisionViolation when the edge ratio cannot be evaluated using BCMath
     *
     * @return numeric-string
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
     * @param GraphEdge $edge
     *
     * @throws PrecisionViolation when the edge capacity ratios cannot be evaluated using BCMath
     *
     * @return numeric-string
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

    /**
     * @throws InvalidInput|PrecisionViolation when the tolerance value is malformed
     *
     * @return numeric-string
     */
    private function normalizeTolerance(string $tolerance): string
    {
        if (!BcMath::isNumeric($tolerance)) {
            throw new InvalidInput('Tolerance must be numeric.');
        }

        /** @var numeric-string $numericTolerance */
        $numericTolerance = $tolerance;

        if (-1 === BcMath::comp($numericTolerance, '0', self::SCALE)) {
            throw new InvalidInput('Tolerance must be non-negative.');
        }

        if (BcMath::comp($numericTolerance, '1', self::SCALE) >= 0) {
            throw new InvalidInput('Tolerance must be less than one.');
        }

        $normalized = BcMath::normalize($numericTolerance, self::SCALE);

        /** @var numeric-string $upperBound */
        $upperBound = '0.'.str_repeat('9', self::SCALE);
        if (1 === BcMath::comp($normalized, $upperBound, self::SCALE)) {
            return $upperBound;
        }

        return $normalized;
    }

    /**
     * @param numeric-string $tolerance
     *
     * @throws PrecisionViolation when BCMath operations required for amplification cannot be performed
     *
     * @return numeric-string
     */
    private function calculateToleranceAmplifier(string $tolerance): string
    {
        if (0 === BcMath::comp($tolerance, '0', self::SCALE)) {
            return BcMath::normalize('1', self::SCALE);
        }

        $normalizedTolerance = BcMath::normalize($tolerance, self::SCALE);
        $complement = BcMath::sub('1', $normalizedTolerance, self::SCALE);

        return BcMath::div('1', $complement, self::SCALE);
    }
}

/**
 * @internal
 *
 * @psalm-import-type SearchState from PathFinder
 * @psalm-import-type SearchQueueEntry from PathFinder
 *
 * @phpstan-import-type SearchState from PathFinder
 * @phpstan-import-type SearchQueueEntry from PathFinder
 *
 * @extends SplPriorityQueue<SearchQueueEntry, mixed>
 */
final class SearchStateQueue extends SplPriorityQueue
{
    public function __construct(private readonly int $scale)
    {
        $this->setExtractFlags(self::EXTR_DATA);
    }

    /**
     * @phpstan-param SearchState                                            $value
     * @phpstan-param array{cost: numeric-string, order: int}|SearchQueueEntry $priority
     *
     * @psalm-param SearchState                                               $value
     * @psalm-param array{cost: numeric-string, order: int}|SearchQueueEntry $priority
     */
    #[\Override]
    public function insert($value, $priority): true
    {
        if (isset($priority['state'], $priority['priority'])) {
            /** @var SearchQueueEntry $entry */
            $entry = $priority;
            $entry['state'] = $value;
        } else {
            /** @var SearchQueueEntry $entry */
            $entry = [
                'state' => $value,
                'priority' => $priority,
            ];
        }

        /* @var SearchQueueEntry $entry */
        parent::insert($entry, $entry);

        return true;
    }

    /**
     * @psalm-return SearchState
     */
    #[\Override]
    public function extract(): array
    {
        /** @var SearchQueueEntry $entry */
        $entry = parent::extract();

        /** @var SearchState $state */
        $state = $entry['state'];

        return $state;
    }

    /**
     * @phpstan-param SearchQueueEntry $priority1
     * @phpstan-param SearchQueueEntry $priority2
     *
     * @psalm-param SearchQueueEntry $priority1
     * @psalm-param SearchQueueEntry $priority2
     */
    #[\Override]
    public function compare($priority1, $priority2): int
    {
        $comparison = BcMath::comp($priority1['priority']['cost'], $priority2['priority']['cost'], $this->scale);
        if (0 !== $comparison) {
            return -$comparison;
        }

        return $priority2['priority']['order'] <=> $priority1['priority']['order'];
    }
}

/**
 * @internal
 *
 * @phpstan-import-type CandidateHeapEntry from PathFinder
 *
 * @extends SplPriorityQueue<CandidateHeapEntry, CandidateHeapEntry>
 */
final class CandidateResultHeap extends SplPriorityQueue
{
    public function __construct(private readonly int $scale)
    {
        $this->setExtractFlags(self::EXTR_DATA);
    }

    /**
     * @phpstan-param CandidateHeapEntry $priority1
     * @phpstan-param CandidateHeapEntry $priority2
     *
     * @psalm-param CandidateHeapEntry $priority1
     * @psalm-param CandidateHeapEntry $priority2
     */
    #[\Override]
    public function compare($priority1, $priority2): int
    {
        $leftCost = $priority1['cost'];
        /** @var numeric-string $leftCost */
        $leftCost = $leftCost;
        $rightCost = $priority2['cost'];
        /** @var numeric-string $rightCost */
        $rightCost = $rightCost;

        $comparison = BcMath::comp($leftCost, $rightCost, $this->scale);
        if (0 !== $comparison) {
            return $comparison;
        }

        return $priority1['order'] <=> $priority2['order'];
    }
}
