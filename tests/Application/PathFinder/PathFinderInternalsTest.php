<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use SomeWork\P2PPathFinder\Application\Graph\EdgeCapacity;
use SomeWork\P2PPathFinder\Application\Graph\EdgeSegment;
use SomeWork\P2PPathFinder\Application\Graph\Graph;
use SomeWork\P2PPathFinder\Application\Graph\GraphEdge;
use SomeWork\P2PPathFinder\Application\Graph\GraphNode;
use SomeWork\P2PPathFinder\Application\PathFinder\CandidateResultHeap;
use SomeWork\P2PPathFinder\Application\PathFinder\PathFinder;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Heap\CandidateHeapEntry;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Heap\CandidatePriority;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\InsertionOrderCounter;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchBootstrap;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchQueueEntry;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchState;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchStatePriority;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchStateRecord;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchStateRegistry;
use SomeWork\P2PPathFinder\Application\PathFinder\SearchStateQueue;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\CandidatePath;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\PathEdge;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\PathEdgeSequence;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\SpendConstraints;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Tests\Fixture\CurrencyScenarioFactory;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

use function array_is_list;
use function array_map;
use function chr;
use function is_array;
use function sprintf;
use function str_repeat;

final class PathFinderInternalsTest extends TestCase
{
    private const SCALE = 18;

    public function test_state_signature_normalizes_range_and_desired(): void
    {
        $finder = new PathFinder(maxHops: 2, tolerance: '0.0');
        $range = [
            'min' => CurrencyScenarioFactory::money('USD', '1.5', 1),
            'max' => CurrencyScenarioFactory::money('USD', '3.00', 2),
        ];
        $desired = CurrencyScenarioFactory::money('USD', '2.250', 3);

        $signature = $this->invokeFinderMethod($finder, 'stateSignature', [$range, $desired]);

        self::assertSame('range:USD:1.500:3.000:3|desired:USD:2.250:3', $signature);
    }

    public function test_state_signature_handles_null_range(): void
    {
        $finder = new PathFinder(maxHops: 1, tolerance: '0.0');

        $withDesired = $this->invokeFinderMethod(
            $finder,
            'stateSignature',
            [null, CurrencyScenarioFactory::money('EUR', '5', 0)],
        );
        $withoutDesired = $this->invokeFinderMethod($finder, 'stateSignature', [null, null]);

        self::assertSame('range:null|desired:EUR:5:0', $withDesired);
        self::assertSame('range:null|desired:null', $withoutDesired);
    }

    public function test_record_state_replaces_dominated_entries(): void
    {
        $finder = new PathFinder(maxHops: 2, tolerance: '0.0');
        $range = [
            'min' => CurrencyScenarioFactory::money('USD', '1.00', 2),
            'max' => CurrencyScenarioFactory::money('USD', '2.00', 2),
        ];
        $signature = $this->invokeFinderMethod($finder, 'stateSignature', [$range, null]);
        $registry = SearchStateRegistry::withInitial(
            'USD',
            new SearchStateRecord(BcMath::normalize('1.2', self::SCALE), 3, $signature),
        );

        $delta = $registry->register(
            'USD',
            new SearchStateRecord(BcMath::normalize('1.0', self::SCALE), 2, $signature),
            self::SCALE,
        );

        self::assertSame(0, $delta);
        $records = $registry->recordsFor('USD');
        self::assertCount(1, $records);
        self::assertSame($signature, $records[0]->signature());
        self::assertSame(BcMath::normalize('1.0', self::SCALE), $records[0]->cost());
        self::assertSame(2, $records[0]->hops());
    }

    public function test_record_state_preserves_existing_when_new_state_has_higher_hops(): void
    {
        $finder = new PathFinder(maxHops: 3, tolerance: '0.0');
        $range = [
            'min' => CurrencyScenarioFactory::money('USD', '0.50', 2),
            'max' => CurrencyScenarioFactory::money('USD', '3.00', 2),
        ];
        $signature = $this->invokeFinderMethod($finder, 'stateSignature', [$range, null]);
        $registry = SearchStateRegistry::withInitial(
            'USD',
            new SearchStateRecord(BcMath::normalize('1.2', self::SCALE), 2, $signature),
        );

        $delta = $registry->register(
            'USD',
            new SearchStateRecord(BcMath::normalize('1.0', self::SCALE), 4, $signature),
            self::SCALE,
        );

        self::assertSame(1, $delta);
        $records = $registry->recordsFor('USD');
        self::assertCount(2, $records);
        $costsByHops = [];
        foreach ($records as $record) {
            $costsByHops[$record->hops()] = $record->cost();
        }
        self::assertArrayHasKey(2, $costsByHops);
        self::assertArrayHasKey(4, $costsByHops);
    }

    public function test_record_state_preserves_existing_when_new_state_has_higher_cost(): void
    {
        $finder = new PathFinder(maxHops: 3, tolerance: '0.0');
        $range = [
            'min' => CurrencyScenarioFactory::money('USD', '0.50', 2),
            'max' => CurrencyScenarioFactory::money('USD', '3.00', 2),
        ];
        $signature = $this->invokeFinderMethod($finder, 'stateSignature', [$range, null]);
        $registry = SearchStateRegistry::withInitial(
            'USD',
            new SearchStateRecord(BcMath::normalize('1.0', self::SCALE), 4, $signature),
        );

        $delta = $registry->register(
            'USD',
            new SearchStateRecord(BcMath::normalize('1.5', self::SCALE), 2, $signature),
            self::SCALE,
        );

        self::assertSame(1, $delta);
        $records = $registry->recordsFor('USD');
        self::assertCount(2, $records);
        $costsByHops = [];
        foreach ($records as $record) {
            $costsByHops[$record->hops()] = $record->cost();
        }
        self::assertSame(BcMath::normalize('1.0', self::SCALE), $costsByHops[4]);
        self::assertSame(BcMath::normalize('1.5', self::SCALE), $costsByHops[2]);
    }

    public function test_initialize_search_structures_sets_expected_defaults(): void
    {
        $finder = new PathFinder(maxHops: 3, tolerance: '0.0');
        $range = [
            'min' => CurrencyScenarioFactory::money('USD', '5.00', 2),
            'max' => CurrencyScenarioFactory::money('USD', '10.00', 2),
        ];
        $desired = CurrencyScenarioFactory::money('USD', '7.50', 2);

        $bootstrap = $this->invokeFinderMethod($finder, 'initializeSearchStructures', ['SRC', $range, $desired]);

        self::assertInstanceOf(SearchBootstrap::class, $bootstrap);

        $queue = $bootstrap->queue();
        $results = $bootstrap->results();
        $bestPerNode = $bootstrap->registry();
        $insertionOrder = $bootstrap->insertionOrder();
        $resultInsertionOrder = $bootstrap->resultInsertionOrder();
        $visitedStates = $bootstrap->visitedStates();

        self::assertInstanceOf(SearchStateQueue::class, $queue);
        self::assertInstanceOf(CandidateResultHeap::class, $results);
        self::assertInstanceOf(SearchStateRegistry::class, $bestPerNode);
        self::assertInstanceOf(InsertionOrderCounter::class, $insertionOrder);
        self::assertInstanceOf(InsertionOrderCounter::class, $resultInsertionOrder);
        self::assertSame(1, $visitedStates);

        self::assertSame(1, $insertionOrder->next());
        self::assertSame(0, $resultInsertionOrder->next());

        self::assertFalse($queue->isEmpty());
        $state = $queue->extract();
        self::assertSame('SRC', $state->node());
        self::assertSame(['SRC' => true], $state->visited());

        $signature = $this->invokeFinderMethod($finder, 'stateSignature', [$range, $desired]);
        $unitValueProperty = new ReflectionProperty(PathFinder::class, 'unitValue');
        $unitValueProperty->setAccessible(true);
        /** @var numeric-string $unit */
        $unit = $unitValueProperty->getValue($finder);

        self::assertSame($unit, $state->cost());
        self::assertSame($unit, $state->product());
        self::assertInstanceOf(PathEdgeSequence::class, $state->path());
        self::assertTrue($state->path()->isEmpty());
        self::assertSame($range, $state->amountRange());
        self::assertSame($desired, $state->desiredAmount());

        self::assertTrue($bestPerNode->hasSignature('SRC', $signature));
        $records = $bestPerNode->recordsFor('SRC');
        self::assertCount(1, $records);
        self::assertSame($unit, $records[0]->cost());
        self::assertSame(0, $records[0]->hops());
        self::assertSame($signature, $records[0]->signature());
    }

    public function test_record_state_replaces_state_with_equal_hops_and_lower_cost(): void
    {
        $finder = new PathFinder(maxHops: 2, tolerance: '0.0');
        $range = [
            'min' => CurrencyScenarioFactory::money('USD', '1.00', 2),
            'max' => CurrencyScenarioFactory::money('USD', '2.00', 2),
        ];
        $signature = $this->invokeFinderMethod($finder, 'stateSignature', [$range, null]);
        $registry = SearchStateRegistry::withInitial(
            'USD',
            new SearchStateRecord(BcMath::normalize('1.5', self::SCALE), 2, $signature),
        );

        $delta = $registry->register(
            'USD',
            new SearchStateRecord(BcMath::normalize('1.0', self::SCALE), 2, $signature),
            self::SCALE,
        );

        self::assertSame(0, $delta);
        $records = $registry->recordsFor('USD');
        self::assertCount(1, $records);
        self::assertSame(BcMath::normalize('1.0', self::SCALE), $records[0]->cost());
        self::assertSame(2, $records[0]->hops());
    }

    public function test_record_state_respects_explicit_signature_override(): void
    {
        $finder = new PathFinder(maxHops: 2, tolerance: '0.0');
        $range = [
            'min' => CurrencyScenarioFactory::money('USD', '1.00', 2),
            'max' => CurrencyScenarioFactory::money('USD', '3.00', 2),
        ];
        $providedSignature = 'provided-signature';
        $registry = SearchStateRegistry::withInitial(
            'USD',
            new SearchStateRecord(BcMath::normalize('1.8', self::SCALE), 3, $providedSignature),
        );

        $delta = $registry->register(
            'USD',
            new SearchStateRecord(BcMath::normalize('1.2', self::SCALE), 2, $providedSignature),
            self::SCALE,
        );

        self::assertSame(0, $delta);
        $records = $registry->recordsFor('USD');
        self::assertCount(1, $records);
        self::assertSame($providedSignature, $records[0]->signature());
        self::assertSame(BcMath::normalize('1.2', self::SCALE), $records[0]->cost());
        self::assertSame(2, $records[0]->hops());
    }

    public function test_record_state_replaces_equal_cost_with_fewer_hops(): void
    {
        $finder = new PathFinder(maxHops: 2, tolerance: '0.0');
        $range = [
            'min' => CurrencyScenarioFactory::money('USD', '1.00', 2),
            'max' => CurrencyScenarioFactory::money('USD', '3.00', 2),
        ];
        $signature = $this->invokeFinderMethod($finder, 'stateSignature', [$range, null]);
        $cost = BcMath::normalize('1.750', self::SCALE);
        $registry = SearchStateRegistry::withInitial(
            'USD',
            new SearchStateRecord($cost, 4, $signature),
        );

        $delta = $registry->register(
            'USD',
            new SearchStateRecord($cost, 2, $signature),
            self::SCALE,
        );

        self::assertSame(0, $delta);
        $records = $registry->recordsFor('USD');
        self::assertCount(1, $records);
        self::assertSame($cost, $records[0]->cost());
        self::assertSame(2, $records[0]->hops());
    }

    public function test_is_dominated_detects_matching_signature(): void
    {
        $registry = SearchStateRegistry::withInitial(
            'USD',
            new SearchStateRecord(BcMath::normalize('1.0', self::SCALE), 2, 'sig'),
        );

        $dominated = $registry->isDominated(
            'USD',
            new SearchStateRecord(BcMath::normalize('1.2', self::SCALE), 3, 'sig'),
            self::SCALE,
        );

        self::assertTrue($dominated);
        self::assertFalse(
            $registry->isDominated(
                'USD',
                new SearchStateRecord(BcMath::normalize('1.2', self::SCALE), 3, 'other'),
                self::SCALE,
            ),
        );
    }

    public function test_has_state_with_signature_detects_existing(): void
    {
        $registry = SearchStateRegistry::withInitial(
            'USD',
            new SearchStateRecord('1', 1, 'alpha'),
        );
        $registry->register('USD', new SearchStateRecord('2', 2, 'beta'), self::SCALE);

        self::assertTrue($registry->hasSignature('USD', 'beta'));
        self::assertFalse($registry->hasSignature('USD', 'gamma'));
    }

    public function test_record_result_enforces_top_k_limit(): void
    {
        $finder = new PathFinder(maxHops: 2, tolerance: '0.0', topK: 2);
        /** @var CandidateResultHeap $heap */
        $heap = $this->invokeFinderMethod($finder, 'createResultHeap');
        $first = $this->buildCandidate('1.00', '1.00');
        $second = $this->buildCandidate('0.50', '2.00');
        $third = $this->buildCandidate('2.00', '0.50');

        $this->invokeFinderMethod($finder, 'recordResult', [$heap, $first, 0]);
        $this->invokeFinderMethod($finder, 'recordResult', [$heap, $second, 1]);
        $this->invokeFinderMethod($finder, 'recordResult', [$heap, $third, 2]);

        self::assertSame(2, $heap->count());
        $collected = [];
        $clone = clone $heap;
        while (!$clone->isEmpty()) {
            $collected[] = $clone->extract();
        }

        $costs = array_map(static fn (CandidateHeapEntry $entry): string => $entry->candidate()->cost(), $collected);
        sort($costs);

        self::assertSame([
            BcMath::normalize('0.50', self::SCALE),
            BcMath::normalize('1.00', self::SCALE),
        ], $costs);
    }

    public function test_record_result_prefers_fewer_hops_when_costs_equal(): void
    {
        $finder = new PathFinder(maxHops: 3, tolerance: '0.0', topK: 1);
        /** @var CandidateResultHeap $heap */
        $heap = $this->invokeFinderMethod($finder, 'createResultHeap');
        $fewer = $this->buildCandidate('1.00', '1.00');
        $more = $this->buildCandidateWithHops('1.00', '1.00', 2);

        $this->invokeFinderMethod($finder, 'recordResult', [$heap, $fewer, 0]);
        $this->invokeFinderMethod($finder, 'recordResult', [$heap, $more, 1]);

        self::assertSame(1, $heap->count());
        $clone = clone $heap;
        $remaining = $clone->extract();

        self::assertSame($fewer->hops(), $remaining->candidate()->hops());
    }

    public function test_record_result_prefers_smaller_signature_when_costs_and_hops_equal(): void
    {
        $finder = new PathFinder(maxHops: 2, tolerance: '0.0', topK: 1);
        /** @var CandidateResultHeap $heap */
        $heap = $this->invokeFinderMethod($finder, 'createResultHeap');
        $alpha = $this->buildCandidate('1.00', '1.00');
        $beta = $this->buildCandidate('1.00', '1.00');

        $this->invokeFinderMethod($finder, 'recordResult', [$heap, $alpha, 0]);
        $this->invokeFinderMethod($finder, 'recordResult', [$heap, $beta, 1]);

        self::assertSame(1, $heap->count());
        $clone = clone $heap;
        $remaining = $clone->extract();

        self::assertSame(
            $this->routeSignatureFromCandidate($alpha),
            $this->routeSignatureFromCandidate($remaining->candidate()),
        );
    }

    public function test_finalize_results_orders_by_cost_then_insertion(): void
    {
        $finder = new PathFinder(maxHops: 2, tolerance: '0.0', topK: 3);
        /** @var CandidateResultHeap $heap */
        $heap = $this->invokeFinderMethod($finder, 'createResultHeap');

        $this->invokeFinderMethod($finder, 'recordResult', [$heap, $this->buildCandidate('2.00', '0.50'), 2]);
        $this->invokeFinderMethod($finder, 'recordResult', [$heap, $this->buildCandidate('1.00', '1.00'), 0]);
        $this->invokeFinderMethod($finder, 'recordResult', [$heap, $this->buildCandidate('1.00', '1.50'), 1]);

        $finalizedCandidates = $this->invokeFinderMethod($finder, 'finalizeResults', [$heap]);
        $finalizedArray = $finalizedCandidates->toArray();
        self::assertSame([
            BcMath::normalize('1.00', self::SCALE),
            BcMath::normalize('1.00', self::SCALE),
            BcMath::normalize('2.00', self::SCALE),
        ], array_map(static fn (CandidatePath $candidate): string => $candidate->cost(), $finalizedArray));

        self::assertSame(
            BcMath::normalize('1.00', self::SCALE),
            $finalizedArray[0]->product(),
        );
        self::assertSame(
            BcMath::normalize('1.50', self::SCALE),
            $finalizedArray[1]->product(),
        );
    }

    public function test_edge_supports_amount_returns_null_for_out_of_bounds(): void
    {
        $finder = new PathFinder(maxHops: 3, tolerance: '0.0');
        $edge = $this->createSellEdge();
        $range = [
            'min' => CurrencyScenarioFactory::money('EUR', '100.00', 2),
            'max' => CurrencyScenarioFactory::money('EUR', '120.00', 2),
        ];

        self::assertNull($this->invokeFinderMethod($finder, 'edgeSupportsAmount', [$edge, $range]));
    }

    public function test_edge_supports_amount_trims_to_segment_bounds(): void
    {
        $finder = new PathFinder(maxHops: 3, tolerance: '0.0');
        $edge = $this->createSellEdge();
        $range = [
            'min' => CurrencyScenarioFactory::money('EUR', '0.00', 2),
            'max' => CurrencyScenarioFactory::money('EUR', '60.00', 2),
        ];

        $supported = $this->invokeFinderMethod($finder, 'edgeSupportsAmount', [$edge, $range]);

        self::assertNotNull($supported);
        self::assertSame('10.00', $supported['min']->amount());
        self::assertSame('55.00', $supported['max']->amount());
    }

    public function test_calculate_next_range_respects_conversion_direction(): void
    {
        $finder = new PathFinder(maxHops: 3, tolerance: '0.0');
        $edge = $this->createSellEdge();
        $range = [
            'min' => CurrencyScenarioFactory::money('EUR', '10.00', 2),
            'max' => CurrencyScenarioFactory::money('EUR', '55.00', 2),
        ];

        $nextRange = $this->invokeFinderMethod($finder, 'calculateNextRange', [$edge, $range]);

        self::assertSame(BcMath::normalize('1.00', self::SCALE), $nextRange['min']->amount());
        self::assertSame(BcMath::normalize('6.00', self::SCALE), $nextRange['max']->amount());
    }

    public function test_calculate_next_range_sorts_swapped_bounds(): void
    {
        $finder = new PathFinder(maxHops: 3, tolerance: '0.0');
        $edge = $this->createSellEdge();
        $range = [
            'min' => CurrencyScenarioFactory::money('EUR', '55.00', 2),
            'max' => CurrencyScenarioFactory::money('EUR', '10.00', 2),
        ];

        $nextRange = $this->invokeFinderMethod($finder, 'calculateNextRange', [$edge, $range]);

        self::assertSame(BcMath::normalize('1.00', self::SCALE), $nextRange['min']->amount());
        self::assertSame(BcMath::normalize('6.00', self::SCALE), $nextRange['max']->amount());
    }

    public function test_convert_edge_amount_clamps_and_converts(): void
    {
        $finder = new PathFinder(maxHops: 3, tolerance: '0.0');
        $edge = $this->createSellEdge();
        $current = CurrencyScenarioFactory::money('EUR', '200.00', 2);

        /** @var Money $converted */
        $converted = $this->invokeFinderMethod($finder, 'convertEdgeAmount', [$edge, $current]);

        self::assertSame('USD', $converted->currency());
        self::assertSame(BcMath::normalize('6.00', self::SCALE), $converted->amount());
        self::assertSame(self::SCALE, $converted->scale());
    }

    public function test_convert_edge_amount_returns_zero_for_non_positive_rate(): void
    {
        $finder = new PathFinder(maxHops: 3, tolerance: '0.0');
        $edge = $this->createSellEdge([
            'baseCapacity' => [
                'min' => Money::zero('USD', 2),
                'max' => Money::zero('USD', 2),
            ],
            'quoteCapacity' => [
                'min' => Money::zero('EUR', 2),
                'max' => Money::zero('EUR', 2),
            ],
            'segments' => [[
                'isMandatory' => false,
                'base' => ['min' => Money::zero('USD', 2), 'max' => Money::zero('USD', 2)],
                'quote' => ['min' => Money::zero('EUR', 2), 'max' => Money::zero('EUR', 2)],
                'grossBase' => ['min' => Money::zero('USD', 2), 'max' => Money::zero('USD', 2)],
            ]],
        ]);
        $current = CurrencyScenarioFactory::money('EUR', '5.00', 2);

        /** @var Money $converted */
        $converted = $this->invokeFinderMethod($finder, 'convertEdgeAmount', [$edge, $current]);

        self::assertSame('USD', $converted->currency());
        self::assertSame(BcMath::normalize('0', self::SCALE), $converted->amount());
        self::assertSame(self::SCALE, $converted->scale());
    }

    public function test_clamp_to_range_returns_bounds(): void
    {
        $finder = new PathFinder(maxHops: 1, tolerance: '0.0');
        $range = [
            'min' => CurrencyScenarioFactory::money('JPY', '100', 0),
            'max' => CurrencyScenarioFactory::money('JPY', '200', 0),
        ];

        $below = CurrencyScenarioFactory::money('JPY', '50', 0);
        $above = CurrencyScenarioFactory::money('JPY', '500', 0);
        $inside = CurrencyScenarioFactory::money('JPY', '150', 0);

        self::assertSame('100', $this->invokeFinderMethod($finder, 'clampToRange', [$below, $range])->amount());
        self::assertSame('200', $this->invokeFinderMethod($finder, 'clampToRange', [$above, $range])->amount());
        self::assertSame('150', $this->invokeFinderMethod($finder, 'clampToRange', [$inside, $range])->amount());
    }

    public function test_edge_effective_conversion_rate_handles_sell_side(): void
    {
        $finder = new PathFinder(maxHops: 3, tolerance: '0.0');
        $edge = $this->createSellEdge();

        $rate = $this->invokeFinderMethod($finder, 'edgeEffectiveConversionRate', [$edge]);

        $expected = BcMath::div('1', BcMath::div('55.00', '6.00', self::SCALE), self::SCALE);
        self::assertSame($expected, $rate);
    }

    public function test_edge_base_to_quote_ratio_returns_zero_when_base_capacity_zero(): void
    {
        $finder = new PathFinder(maxHops: 3, tolerance: '0.0');
        $edge = $this->createSellEdge([
            'baseCapacity' => [
                'min' => Money::zero('USD', 2),
                'max' => Money::zero('USD', 2),
            ],
            'quoteCapacity' => [
                'min' => Money::zero('EUR', 2),
                'max' => Money::zero('EUR', 2),
            ],
        ]);

        $ratio = $this->invokeFinderMethod($finder, 'edgeBaseToQuoteRatio', [$edge]);

        self::assertSame(BcMath::normalize('0', self::SCALE), $ratio);
    }

    public function test_normalize_tolerance_clamps_to_upper_bound(): void
    {
        $finder = new PathFinder(maxHops: 1, tolerance: '0.0');
        $raw = '0.'.str_repeat('9', 36);

        $normalized = $this->invokeFinderMethod($finder, 'normalizeTolerance', [$raw]);

        self::assertSame('0.'.str_repeat('9', self::SCALE), $normalized);
    }

    public function test_calculate_tolerance_amplifier_inverts_complement(): void
    {
        $finder = new PathFinder(maxHops: 1, tolerance: '0.0');
        $tolerance = BcMath::normalize('0.500', self::SCALE);

        $amplifier = $this->invokeFinderMethod($finder, 'calculateToleranceAmplifier', [$tolerance]);

        self::assertSame(BcMath::normalize('2', self::SCALE), $amplifier);
    }

    public function test_create_queue_orders_by_cost_and_insertion(): void
    {
        $finder = new PathFinder(maxHops: 2, tolerance: '0.0');
        /** @var SearchStateQueue $queue */
        $queue = $this->invokeFinderMethod($finder, 'createQueue');

        $queue->push(new SearchQueueEntry(
            $this->buildState('A'),
            new SearchStatePriority(BcMath::normalize('0.8', self::SCALE), 0, '', 1),
        ));
        $queue->push(new SearchQueueEntry(
            $this->buildState('B'),
            new SearchStatePriority(BcMath::normalize('0.5', self::SCALE), 0, '', 2),
        ));
        $queue->push(new SearchQueueEntry(
            $this->buildState('C'),
            new SearchStatePriority(BcMath::normalize('0.5', self::SCALE), 0, '', 0),
        ));

        $first = $queue->extract();
        $second = $queue->extract();
        $third = $queue->extract();

        self::assertSame('C', $first->node());
        self::assertSame('B', $second->node());
        self::assertSame('A', $third->node());
    }

    public function test_create_result_heap_orders_by_cost_and_insertion(): void
    {
        $finder = new PathFinder(maxHops: 2, tolerance: '0.0', topK: 3);
        /** @var CandidateResultHeap $heap */
        $heap = $this->invokeFinderMethod($finder, 'createResultHeap');

        $first = $this->buildCandidate('1.00', '1.00');
        $second = $this->buildCandidate('2.00', '0.50');
        $third = $this->buildCandidate('1.00', '1.50');

        $heap->push(new CandidateHeapEntry(
            $first,
            new CandidatePriority(
                $first->cost(),
                $first->hops(),
                $this->routeSignatureFromCandidate($first),
                0,
            ),
        ));
        $heap->push(new CandidateHeapEntry(
            $second,
            new CandidatePriority(
                $second->cost(),
                $second->hops(),
                $this->routeSignatureFromCandidate($second),
                2,
            ),
        ));
        $heap->push(new CandidateHeapEntry(
            $third,
            new CandidatePriority(
                $third->cost(),
                $third->hops(),
                $this->routeSignatureFromCandidate($third),
                1,
            ),
        ));

        $first = $heap->extract();
        $second = $heap->extract();
        $third = $heap->extract();

        self::assertSame(BcMath::normalize('2.00', self::SCALE), $first->candidate()->cost());
        self::assertSame(BcMath::normalize('1.00', self::SCALE), $second->candidate()->cost());
        self::assertSame(BcMath::normalize('1.00', self::SCALE), $third->candidate()->cost());
        self::assertSame(BcMath::normalize('1.50', self::SCALE), $second->candidate()->product());
    }

    public function test_create_result_heap_prefers_fewer_hops_on_equal_cost(): void
    {
        $finder = new PathFinder(maxHops: 3, tolerance: '0.0', topK: 3);
        /** @var CandidateResultHeap $heap */
        $heap = $this->invokeFinderMethod($finder, 'createResultHeap');

        $fewerHops = $this->buildCandidate('1.00', '1.00');
        $moreHops = $this->buildCandidateWithHops('1.00', '1.00', 2);

        $heap->push(new CandidateHeapEntry(
            $fewerHops,
            new CandidatePriority(
                $fewerHops->cost(),
                $fewerHops->hops(),
                $this->routeSignatureFromCandidate($fewerHops),
                0,
            ),
        ));
        $heap->push(new CandidateHeapEntry(
            $moreHops,
            new CandidatePriority(
                $moreHops->cost(),
                $moreHops->hops(),
                $this->routeSignatureFromCandidate($moreHops),
                1,
            ),
        ));

        $extracted = $heap->extract();

        self::assertSame($moreHops->hops(), $extracted->candidate()->hops());
    }

    public function test_create_result_heap_prefers_lexicographically_smaller_signature_on_equal_cost_and_hops(): void
    {
        $finder = new PathFinder(maxHops: 2, tolerance: '0.0', topK: 3);
        /** @var CandidateResultHeap $heap */
        $heap = $this->invokeFinderMethod($finder, 'createResultHeap');

        $alpha = $this->buildCandidate('1.00', '1.00');
        $beta = $this->buildCandidate('1.00', '1.00');

        $heap->push(new CandidateHeapEntry(
            $alpha,
            new CandidatePriority(
                $alpha->cost(),
                $alpha->hops(),
                $this->routeSignatureFromCandidate($alpha),
                0,
            ),
        ));
        $heap->push(new CandidateHeapEntry(
            $beta,
            new CandidatePriority(
                $beta->cost(),
                $beta->hops(),
                $this->routeSignatureFromCandidate($beta),
                1,
            ),
        ));

        $extracted = $heap->extract();

        self::assertSame(
            $this->routeSignatureFromCandidate($beta),
            $this->routeSignatureFromCandidate($extracted->candidate()),
        );
    }

    public function test_find_best_paths_skips_edges_with_unknown_target_nodes(): void
    {
        $finder = new PathFinder(maxHops: 2, tolerance: '0.0');
        $edge = $this->createSellEdge([
            'from' => 'EUR',
            'to' => 'GAP',
        ]);

        $graph = new Graph([
            'EUR' => new GraphNode('EUR', [$edge]),
            'USD' => new GraphNode('USD'),
        ]);

        $constraints = SpendConstraints::from(
            CurrencyScenarioFactory::money('EUR', '5.00', 2),
            CurrencyScenarioFactory::money('EUR', '10.00', 2),
        );

        $outcome = $finder->findBestPaths(
            $graph,
            'EUR',
            'USD',
            $constraints,
        );

        self::assertTrue($outcome->paths()->isEmpty());
        self::assertFalse($outcome->guardLimits()->expansionsReached());
        self::assertFalse($outcome->guardLimits()->visitedStatesReached());
    }

    public function test_edge_supports_amount_swaps_inverted_bounds_before_clamping(): void
    {
        $finder = new PathFinder(maxHops: 1, tolerance: '0.0');
        $edge = $this->createSellEdge();
        $range = [
            'min' => CurrencyScenarioFactory::money('EUR', '20.00', 2),
            'max' => CurrencyScenarioFactory::money('EUR', '10.00', 2),
        ];

        $feasible = $this->invokeFinderMethod($finder, 'edgeSupportsAmount', [$edge, $range]);

        self::assertNotNull($feasible);
        self::assertSame('EUR', $feasible['min']->currency());
        self::assertSame('10.00', $feasible['min']->amount());
        self::assertSame('EUR', $feasible['max']->currency());
        self::assertSame('20.00', $feasible['max']->amount());
    }

    public function test_edge_supports_amount_rejects_positive_minimum_when_capacity_zero(): void
    {
        $finder = new PathFinder(maxHops: 1, tolerance: '0.0');
        $edge = $this->createSellEdge([
            'segments' => [
                [
                    'isMandatory' => true,
                    'base' => [
                        'min' => Money::zero('USD', 2),
                        'max' => Money::zero('USD', 2),
                    ],
                    'quote' => [
                        'min' => Money::zero('EUR', 2),
                        'max' => Money::zero('EUR', 2),
                    ],
                    'grossBase' => [
                        'min' => Money::zero('USD', 2),
                        'max' => Money::zero('USD', 2),
                    ],
                ],
            ],
        ]);
        $range = [
            'min' => CurrencyScenarioFactory::money('EUR', '2.00', 2),
            'max' => CurrencyScenarioFactory::money('EUR', '4.00', 2),
        ];

        self::assertNull($this->invokeFinderMethod($finder, 'edgeSupportsAmount', [$edge, $range]));
    }

    public function test_edge_supports_amount_returns_zero_range_when_capacity_exhausted(): void
    {
        $finder = new PathFinder(maxHops: 1, tolerance: '0.0');
        $edge = $this->createSellEdge([
            'segments' => [
                [
                    'isMandatory' => false,
                    'base' => [
                        'min' => Money::zero('USD', 2),
                        'max' => Money::zero('USD', 2),
                    ],
                    'quote' => [
                        'min' => Money::zero('EUR', 2),
                        'max' => Money::zero('EUR', 2),
                    ],
                    'grossBase' => [
                        'min' => Money::zero('USD', 2),
                        'max' => Money::zero('USD', 2),
                    ],
                ],
            ],
            'baseCapacity' => [
                'min' => Money::zero('USD', 2),
                'max' => Money::zero('USD', 2),
            ],
            'quoteCapacity' => [
                'min' => Money::zero('EUR', 2),
                'max' => Money::zero('EUR', 2),
            ],
            'grossBaseCapacity' => [
                'min' => Money::zero('USD', 2),
                'max' => Money::zero('USD', 2),
            ],
        ]);
        $range = [
            'min' => Money::zero('EUR', 2),
            'max' => Money::zero('EUR', 2),
        ];

        $feasible = $this->invokeFinderMethod($finder, 'edgeSupportsAmount', [$edge, $range]);

        self::assertNotNull($feasible);
        self::assertTrue($feasible['min']->isZero());
        self::assertTrue($feasible['max']->isZero());
    }

    public function test_convert_edge_amount_clamps_values_below_source_minimum(): void
    {
        $finder = new PathFinder(maxHops: 1, tolerance: '0.0');
        $edge = $this->createSellEdge();

        $converted = $this->invokeFinderMethod(
            $finder,
            'convertEdgeAmount',
            [$edge, CurrencyScenarioFactory::money('EUR', '5.00', 2)],
        );

        self::assertSame('USD', $converted->currency());
        self::assertSame('1.000000000000000000', $converted->amount());
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return GraphEdge fixture representing a sell edge for test scenarios
     */
    private function createSellEdge(array $overrides = []): GraphEdge
    {
        $order = OrderFactory::createOrder(
            OrderSide::SELL,
            'USD',
            'EUR',
            '1.00',
            '6.00',
            '0.90',
            2,
            2,
        );

        $edge = [
            'from' => 'EUR',
            'to' => 'USD',
            'orderSide' => OrderSide::SELL,
            'order' => $order,
            'rate' => $order->effectiveRate(),
            'baseCapacity' => [
                'min' => CurrencyScenarioFactory::money('USD', '1.00', 2),
                'max' => CurrencyScenarioFactory::money('USD', '6.00', 2),
            ],
            'quoteCapacity' => [
                'min' => CurrencyScenarioFactory::money('EUR', '10.00', 2),
                'max' => CurrencyScenarioFactory::money('EUR', '55.00', 2),
            ],
            'grossBaseCapacity' => [
                'min' => CurrencyScenarioFactory::money('USD', '1.10', 2),
                'max' => CurrencyScenarioFactory::money('USD', '6.60', 2),
            ],
            'segments' => [
                [
                    'isMandatory' => true,
                    'base' => [
                        'min' => CurrencyScenarioFactory::money('USD', '1.00', 2),
                        'max' => CurrencyScenarioFactory::money('USD', '1.00', 2),
                    ],
                    'quote' => [
                        'min' => CurrencyScenarioFactory::money('EUR', '10.00', 2),
                        'max' => CurrencyScenarioFactory::money('EUR', '10.00', 2),
                    ],
                    'grossBase' => [
                        'min' => CurrencyScenarioFactory::money('USD', '1.10', 2),
                        'max' => CurrencyScenarioFactory::money('USD', '1.10', 2),
                    ],
                ],
                [
                    'isMandatory' => false,
                    'base' => [
                        'min' => Money::zero('USD', 2),
                        'max' => CurrencyScenarioFactory::money('USD', '5.00', 2),
                    ],
                    'quote' => [
                        'min' => Money::zero('EUR', 2),
                        'max' => CurrencyScenarioFactory::money('EUR', '45.00', 2),
                    ],
                    'grossBase' => [
                        'min' => Money::zero('USD', 2),
                        'max' => CurrencyScenarioFactory::money('USD', '5.50', 2),
                    ],
                ],
            ],
        ];

        $edge = $this->arrayReplaceRecursive($edge, $overrides);

        return $this->hydrateEdge($edge);
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function arrayReplaceRecursive(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (
                is_array($value)
                && isset($base[$key])
                && is_array($base[$key])
                && !array_is_list($value)
            ) {
                $base[$key] = $this->arrayReplaceRecursive($base[$key], $value);

                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * @param array{from:string,to:string,orderSide:OrderSide,order:mixed,rate:mixed,baseCapacity:array{min:Money,max:Money},quoteCapacity:array{min:Money,max:Money},grossBaseCapacity:array{min:Money,max:Money},segments:list<array{isMandatory:bool,base:array{min:Money,max:Money},quote:array{min:Money,max:Money},grossBase:array{min:Money,max:Money}>}> $edge
     */
    private function hydrateEdge(array $edge): GraphEdge
    {
        return new GraphEdge(
            $edge['from'],
            $edge['to'],
            $edge['orderSide'],
            $edge['order'],
            $edge['rate'],
            new EdgeCapacity($edge['baseCapacity']['min'], $edge['baseCapacity']['max']),
            new EdgeCapacity($edge['quoteCapacity']['min'], $edge['quoteCapacity']['max']),
            new EdgeCapacity($edge['grossBaseCapacity']['min'], $edge['grossBaseCapacity']['max']),
            array_map(
                static fn (array $segment): EdgeSegment => new EdgeSegment(
                    $segment['isMandatory'],
                    new EdgeCapacity($segment['base']['min'], $segment['base']['max']),
                    new EdgeCapacity($segment['quote']['min'], $segment['quote']['max']),
                    new EdgeCapacity($segment['grossBase']['min'], $segment['grossBase']['max']),
                ),
                $edge['segments'],
            ),
        );
    }

    /**
     * @param array<int, mixed> $arguments
     */
    private function invokeFinderMethod(PathFinder $finder, string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionMethod(PathFinder::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($finder, $arguments);
    }

    private function buildCandidate(string $cost, string $product): CandidatePath
    {
        static $identifier = 0;
        ++$identifier;

        $to = sprintf('DST%s', chr(65 + (($identifier - 1) % 26)));
        $order = OrderFactory::buy('SRC', $to, '1.000', '1.000', '1.000', 3, 3);
        $edge = PathEdge::create(
            'SRC',
            $to,
            $order,
            $order->effectiveRate(),
            OrderSide::BUY,
            BcMath::normalize('1.000000000000000000', self::SCALE),
        );

        return CandidatePath::from(
            BcMath::normalize($cost, self::SCALE),
            BcMath::normalize($product, self::SCALE),
            1,
            PathEdgeSequence::fromList([$edge]),
        );
    }

    private function buildCandidateWithHops(string $cost, string $product, int $hops): CandidatePath
    {
        return CandidatePath::from(
            BcMath::normalize($cost, self::SCALE),
            BcMath::normalize($product, self::SCALE),
            $hops,
            $this->dummyEdges($hops),
        );
    }

    private function routeSignatureFromCandidate(CandidatePath $candidate): string
    {
        $edges = $candidate->edges();
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

    private function dummyEdges(int $count): PathEdgeSequence
    {
        if (0 === $count) {
            return PathEdgeSequence::empty();
        }

        $edges = [];
        $from = 'SRC';

        for ($index = 0; $index < $count; ++$index) {
            $to = sprintf('CUR%s', chr(65 + $index));
            $order = OrderFactory::buy($from, $to, '1.000', '1.000', '1.000', 3, 3);

            $edges[] = PathEdge::create(
                $from,
                $to,
                $order,
                $order->effectiveRate(),
                OrderSide::BUY,
                BcMath::normalize('1.000000000000000000', self::SCALE),
            );

            $from = $to;
        }

        return PathEdgeSequence::fromList($edges);
    }

    private function buildState(string $node): SearchState
    {
        $unit = BcMath::normalize('1', self::SCALE);

        return SearchState::fromComponents(
            $node,
            $unit,
            $unit,
            0,
            PathEdgeSequence::empty(),
            null,
            null,
            [$node => true],
        );
    }
}
