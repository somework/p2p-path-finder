<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder;

use SomeWork\P2PPathFinder\Application\Graph\Graph;
use SomeWork\P2PPathFinder\Application\Graph\GraphEdge;
use SomeWork\P2PPathFinder\Application\PathFinder\Guard\SearchGuards;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\CostHopsSignatureOrderingStrategy;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathOrderKey;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathOrderStrategy;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\SearchGuardReport;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\SearchOutcome;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\CandidatePath;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\PathEdge;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\PathEdgeSequence;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\SpendConstraints;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;
use SplPriorityQueue;

use function array_map;
use function array_values;
use function implode;
use function sprintf;
use function str_repeat;
use function strtoupper;
use function usort;

/**
 * Implementation of a tolerance-aware best-path search through the trading graph.
 *
 * @psalm-type SpendRange = array{min: Money, max: Money}
 *
 * @phpstan-type SpendRange array{min: Money, max: Money}
 *
 * @psalm-type CandidateHeapEntry = array{
 *     candidate: CandidatePath,
 *     order: int,
 *     cost: numeric-string,
 * }
 *
 * @phpstan-type CandidateHeapEntry array{
 *     candidate: CandidatePath,
 *     order: int,
 *     cost: numeric-string,
 * }
 *
 * @psalm-type CandidateResultEntry = array{
 *     candidate: CandidatePath,
 *     order: int,
 *     cost: numeric-string,
 *     routeSignature: string,
 *     orderKey: PathOrderKey,
 * }
 *
 * @phpstan-type CandidateResultEntry array{
 *     candidate: CandidatePath,
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
 *     path: PathEdgeSequence,
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
 *     path: PathEdgeSequence,
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
     * @param callable(CandidatePath):bool|null $acceptCandidate
     *
     * @return SearchOutcome<CandidatePath>
     */
    public function findBestPaths(
        Graph $graph,
        string $source,
        string $target,
        ?SpendConstraints $spendConstraints = null,
        ?callable $acceptCandidate = null
    ): SearchOutcome {
        $source = strtoupper($source);
        $target = strtoupper($target);

        if (!$graph->hasNode($source) || !$graph->hasNode($target)) {
            /** @var SearchOutcome<CandidatePath> $empty */
            $empty = SearchOutcome::empty(SearchGuardReport::idle($this->maxVisitedStates, $this->maxExpansions, $this->timeBudgetMs));

            return $empty;
        }

        $range = null;
        $desiredSpend = null;
        if (null !== $spendConstraints) {
            $range = $spendConstraints->toRange();
            $desiredSpend = $spendConstraints->desired();
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

        $guards = new SearchGuards($this->maxExpansions, $this->timeBudgetMs);
        $visitedGuardReached = false;

        while (!$queue->isEmpty()) {
            if (!$guards->canExpand()) {
                break;
            }

            /** @var SearchState $state */
            $state = $queue->extract();
            $guards->recordExpansion();

            if ($state['node'] === $target) {
                /** @var numeric-string $candidateCost */
                $candidateCost = $state['cost'];
                /** @var numeric-string $candidateProduct */
                $candidateProduct = $state['product'];

                BcMath::ensureNumeric($candidateCost, $candidateProduct);

                $candidateRange = null;
                if (null !== $state['amountRange']) {
                    $candidateRange = SpendConstraints::from(
                        $state['amountRange']['min'],
                        $state['amountRange']['max'],
                        $state['desiredAmount'],
                    );
                }

                $candidate = CandidatePath::from(
                    $candidateCost,
                    $candidateProduct,
                    $state['path']->count(),
                    $state['path'],
                    $candidateRange,
                );

                if (null === $acceptCandidate || $acceptCandidate($candidate)) {
                    if (
                        null === $bestTargetCost
                        || -1 === BcMath::comp($candidate->cost(), $bestTargetCost, self::SCALE)
                    ) {
                        $bestTargetCost = $candidate->cost();
                    }

                    $this->recordResult($results, $candidate, $resultInsertionOrder++);
                }

                continue;
            }

            if ($state['hops'] >= $this->maxHops) {
                continue;
            }

            $currentNode = $graph->node($state['node']);
            if (null === $currentNode) {
                continue;
            }

            foreach ($currentNode->edges() as $edge) {
                $nextNode = $edge->to();
                if (!$graph->hasNode($nextNode)) {
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

                $nextPath = $state['path']->append(PathEdge::fromGraphEdge($edge, $conversionRate));

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

        $guardLimits = $guards->finalize($visitedStates, $this->maxVisitedStates, $visitedGuardReached);

        if (0 === $results->count()) {
            /** @var SearchOutcome<CandidatePath> $empty */
            $empty = SearchOutcome::empty($guardLimits);

            return $empty;
        }

        /** @var list<CandidatePath> $finalized */
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

    private function recordResult(CandidateResultHeap $results, CandidatePath $candidate, int $order): void
    {
        /** @var numeric-string $candidateCost */
        $candidateCost = $candidate->cost();
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
            'path' => PathEdgeSequence::empty(),
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
     * @return list<CandidatePath>
     */
    private function finalizeResults(CandidateResultHeap $results): array
    {
        /** @var list<CandidateResultEntry> $entries */
        $entries = $this->collectResultEntries($results);
        $this->sortResultEntries($entries);

        /** @var list<CandidatePath> $finalized */
        $finalized = array_map(
            /**
             * @param CandidateResultEntry $entry
             */
            static fn (array $entry): CandidatePath => $entry['candidate'],
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
            $entry['routeSignature'] = $this->routeSignature($entry['candidate']->edges());
            $entry['orderKey'] = new PathOrderKey(
                $entry['cost'],
                $entry['candidate']->hops(),
                $entry['routeSignature'],
                $entry['order'],
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
     * @param SpendRange $range
     *
     * @return SpendRange|null
     */
    private function edgeSupportsAmount(GraphEdge $edge, array $range): ?array
    {
        $isBuy = OrderSide::BUY === $edge->orderSide();
        $capacity = $isBuy ? $edge->grossBaseCapacity() : $edge->quoteCapacity();

        $scale = max(
            $range['min']->scale(),
            $range['max']->scale(),
            $capacity->min()->scale(),
            $capacity->max()->scale(),
        );

        foreach ($edge->segments() as $segment) {
            $segmentCapacity = $isBuy ? $segment->grossBase() : $segment->quote();
            $scale = max(
                $scale,
                $segmentCapacity->min()->scale(),
                $segmentCapacity->max()->scale(),
            );
        }

        $requestedMin = $range['min']->withScale($scale);
        $requestedMax = $range['max']->withScale($scale);

        if ($requestedMin->greaterThan($requestedMax)) {
            [$requestedMin, $requestedMax] = [$requestedMax, $requestedMin];
        }

        if ([] === $edge->segments()) {
            $minimum = $capacity->min()->withScale($scale);
            $maximum = $capacity->max()->withScale($scale);
        } else {
            $minimum = Money::zero($requestedMin->currency(), $scale);
            $maximum = Money::zero($requestedMin->currency(), $scale);

            foreach ($edge->segments() as $segment) {
                $segmentCapacity = $isBuy ? $segment->grossBase() : $segment->quote();
                if ($segment->isMandatory()) {
                    $minimum = $minimum->add($segmentCapacity->min()->withScale($scale));
                }

                $maximum = $maximum->add($segmentCapacity->max()->withScale($scale));
            }
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
     * @param SpendRange $range
     *
     * @return SpendRange
     */
    private function calculateNextRange(GraphEdge $edge, array $range): array
    {
        $minimum = $this->convertEdgeAmount($edge, $range['min']);
        $maximum = $this->convertEdgeAmount($edge, $range['max']);

        if ($minimum->greaterThan($maximum)) {
            [$minimum, $maximum] = [$maximum, $minimum];
        }

        return ['min' => $minimum, 'max' => $maximum];
    }

    private function convertEdgeAmount(GraphEdge $edge, Money $current): Money
    {
        $conversionRate = $this->edgeEffectiveConversionRate($edge);
        if (1 !== BcMath::comp($conversionRate, '0', self::SCALE)) {
            return Money::zero($edge->to(), max($current->scale(), self::SCALE));
        }

        $sourceCapacity = OrderSide::BUY === $edge->orderSide()
            ? $edge->grossBaseCapacity()
            : $edge->quoteCapacity();
        $targetCapacity = OrderSide::BUY === $edge->orderSide()
            ? $edge->quoteCapacity()
            : $edge->baseCapacity();

        $sourceScale = max(
            $sourceCapacity->min()->scale(),
            $sourceCapacity->max()->scale(),
            $current->scale(),
            self::SCALE,
        );
        $targetScale = max(
            $targetCapacity->min()->scale(),
            $targetCapacity->max()->scale(),
            self::SCALE,
        );

        $sourceMin = $sourceCapacity->min()->withScale($sourceScale);
        $sourceMax = $sourceCapacity->max()->withScale($sourceScale);
        $clampedCurrent = $current->withScale($sourceScale);

        if ($clampedCurrent->lessThan($sourceMin)) {
            $clampedCurrent = $sourceMin;
        }

        if ($clampedCurrent->greaterThan($sourceMax)) {
            $clampedCurrent = $sourceMax;
        }

        $sourceDelta = $sourceMax->subtract($sourceMin, $sourceScale);
        $targetMin = $targetCapacity->min()->withScale($targetScale);
        $targetMax = $targetCapacity->max()->withScale($targetScale);
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
            $edge->to(),
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
     * @throws PrecisionViolation when the edge ratio cannot be evaluated using BCMath
     *
     * @return numeric-string
     */
    private function edgeEffectiveConversionRate(GraphEdge $edge): string
    {
        $baseToQuote = $this->edgeBaseToQuoteRatio($edge);
        if (1 !== BcMath::comp($baseToQuote, '0', self::SCALE)) {
            return $baseToQuote;
        }

        if (OrderSide::SELL === $edge->orderSide()) {
            return BcMath::div('1', $baseToQuote, self::SCALE);
        }

        return $baseToQuote;
    }

    /**
     * @throws PrecisionViolation when the edge capacity ratios cannot be evaluated using BCMath
     *
     * @return numeric-string
     */
    private function edgeBaseToQuoteRatio(GraphEdge $edge): string
    {
        $baseCapacity = OrderSide::BUY === $edge->orderSide()
            ? $edge->grossBaseCapacity()
            : $edge->baseCapacity();

        $baseScale = max($baseCapacity->min()->scale(), $baseCapacity->max()->scale());
        $quoteCapacity = $edge->quoteCapacity();
        $quoteScale = max($quoteCapacity->min()->scale(), $quoteCapacity->max()->scale());

        $baseMax = $baseCapacity->max()->withScale($baseScale)->amount();
        if (0 === BcMath::comp($baseMax, '0', $baseScale)) {
            return BcMath::normalize('0', self::SCALE);
        }

        $quoteMax = $quoteCapacity->max()->withScale($quoteScale)->amount();

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
