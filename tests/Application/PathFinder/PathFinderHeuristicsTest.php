<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\PathFinder\PathFinder;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\PathCost;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\Ordering\RouteSignature;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchQueueEntry;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchState;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchStatePriority;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchStateRecord;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchStateRegistry;
use SomeWork\P2PPathFinder\Application\PathFinder\Search\SearchStateSignature;
use SomeWork\P2PPathFinder\Application\PathFinder\SearchStateQueue;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\CandidatePath;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\PathEdge;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\PathEdgeSequence;
use SomeWork\P2PPathFinder\Application\PathFinder\ValueObject\SpendRange;
use SomeWork\P2PPathFinder\Application\Service\LegMaterializer;
use SomeWork\P2PPathFinder\Domain\Order\FeeBreakdown;
use SomeWork\P2PPathFinder\Domain\Order\FeePolicy;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Fixture\CurrencyScenarioFactory;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

use function chr;
use function sprintf;

/**
 * @covers \SomeWork\P2PPathFinder\Application\PathFinder\PathFinder
 */
final class PathFinderHeuristicsTest extends TestCase
{
    private const SCALE = 18;

    public function test_dominated_state_is_detected(): void
    {
        $registry = SearchStateRegistry::withInitial(
            'USD',
            new SearchStateRecord(
                BcMath::normalize('1.000', 18),
                1,
                SearchStateSignature::fromString('range:null|desired:null'),
            ),
        );

        $candidate = new SearchStateRecord(
            BcMath::normalize('1.250', 18),
            3,
            SearchStateSignature::fromString('range:null|desired:null'),
        );

        self::assertTrue($registry->isDominated('USD', $candidate, 18));
    }

    public function test_registry_ignores_mismatched_signatures_when_evaluating_dominance(): void
    {
        $registry = SearchStateRegistry::withInitial(
            'USD',
            new SearchStateRecord(
                BcMath::normalize('0.750', 18),
                1,
                SearchStateSignature::fromString('signature:alpha'),
            ),
        );

        $registry->register(
            'USD',
            new SearchStateRecord(
                BcMath::normalize('0.800', 18),
                2,
                SearchStateSignature::fromString('signature:beta'),
            ),
            18,
        );

        $candidate = new SearchStateRecord(
            BcMath::normalize('0.900', 18),
            2,
            SearchStateSignature::fromString('signature:beta'),
        );
        $mismatch = new SearchStateRecord(
            BcMath::normalize('0.900', 18),
            2,
            SearchStateSignature::fromString('signature:gamma'),
        );

        self::assertTrue($registry->isDominated('USD', $candidate, 18));
        self::assertFalse($registry->isDominated('USD', $mismatch, 18));
    }

    public function test_record_state_replaces_inferior_entries(): void
    {
        $finder = new PathFinder(maxHops: 2, tolerance: '0.0');

        $signatureMethod = new ReflectionMethod(PathFinder::class, 'stateSignature');
        $signatureMethod->setAccessible(true);
        $signature = $signatureMethod->invoke($finder, null, null);

        $registry = SearchStateRegistry::withInitial(
            'USD',
            new SearchStateRecord(BcMath::normalize('2.000', 18), 3, $signature),
        );
        $registry->register(
            'USD',
            new SearchStateRecord(
                BcMath::normalize('3.000', 18),
                4,
                SearchStateSignature::fromString('other:signature'),
            ),
            18,
        );

        $netChange = $registry->register(
            'USD',
            new SearchStateRecord(BcMath::normalize('1.500', 18), 1, $signature),
            18,
        );

        self::assertSame(0, $netChange);
        $records = $registry->recordsFor('USD');
        self::assertCount(2, $records);
        $recordsBySignature = [];
        foreach ($records as $record) {
            $recordsBySignature[$record->signature()->value()] = $record;
        }

        self::assertArrayHasKey('other:signature', $recordsBySignature);
        self::assertSame(BcMath::normalize('3.000', 18), $recordsBySignature['other:signature']->cost());
        self::assertSame(4, $recordsBySignature['other:signature']->hops());

        $replacement = $recordsBySignature[$signature->value()];
        self::assertSame(BcMath::normalize('1.500', 18), $replacement->cost());
        self::assertSame(1, $replacement->hops());
    }

    public function test_record_state_removes_matching_entry_after_skipping_mismatched_signatures(): void
    {
        $finder = new PathFinder(maxHops: 2, tolerance: '0.0');

        $signatureMethod = new ReflectionMethod(PathFinder::class, 'stateSignature');
        $signatureMethod->setAccessible(true);
        $primarySignature = $signatureMethod->invoke($finder, null, null);
        $alternateSignature = SearchStateSignature::fromString($primarySignature->value().'-alt');

        $registry = SearchStateRegistry::withInitial(
            'USD',
            new SearchStateRecord(BcMath::normalize('4.000', 18), 5, $alternateSignature),
        );
        $registry->register(
            'USD',
            new SearchStateRecord(BcMath::normalize('2.750', 18), 3, $primarySignature),
            18,
        );

        $netChange = $registry->register(
            'USD',
            new SearchStateRecord(BcMath::normalize('1.250', 18), 2, $primarySignature),
            18,
        );

        self::assertSame(0, $netChange);
        $records = $registry->recordsFor('USD');
        self::assertCount(2, $records);
        $recordsBySignature = [];
        foreach ($records as $record) {
            $recordsBySignature[$record->signature()->value()] = $record;
        }

        self::assertArrayHasKey($alternateSignature->value(), $recordsBySignature);
        self::assertSame(
            BcMath::normalize('4.000', 18),
            $recordsBySignature[$alternateSignature->value()]->cost(),
        );
        self::assertSame(5, $recordsBySignature[$alternateSignature->value()]->hops());

        $replacement = $recordsBySignature[$primarySignature->value()];
        self::assertSame(BcMath::normalize('1.250', 18), $replacement->cost());
        self::assertSame(2, $replacement->hops());
    }

    public function test_money_signature_formats_null_and_scaled_amounts(): void
    {
        $finder = new PathFinder(maxHops: 1, tolerance: '0.0');

        $method = new ReflectionMethod(PathFinder::class, 'moneySignature');
        $method->setAccessible(true);

        $amount = CurrencyScenarioFactory::money('USD', '3.210', 3);

        self::assertSame('null', $method->invoke($finder, null));
        self::assertSame('USD:3.210:3', $method->invoke($finder, $amount));
        self::assertSame('USD:3.21000:5', $method->invoke($finder, $amount, 5));
    }

    public function test_has_state_with_signature_detects_matching_entries(): void
    {
        $finder = new PathFinder(maxHops: 1, tolerance: '0.0');

        $signatureMethod = new ReflectionMethod(PathFinder::class, 'stateSignature');
        $signatureMethod->setAccessible(true);

        $desired = CurrencyScenarioFactory::money('EUR', '2.500', 3);
        $signature = $signatureMethod->invoke(
            $finder,
            SpendRange::fromBounds(
                CurrencyScenarioFactory::money('EUR', '1.00', 2),
                CurrencyScenarioFactory::money('EUR', '5.0000', 4),
            ),
            $desired,
        );

        $registry = SearchStateRegistry::withInitial(
            'EUR',
            new SearchStateRecord(BcMath::normalize('1.000', 18), 1, $signature),
        );

        self::assertTrue($registry->hasSignature('EUR', $signature));
        self::assertFalse(
            $registry->hasSignature(
                'EUR',
                SearchStateSignature::fromString($signature->value().'-mismatch'),
            ),
        );
    }

    public function test_state_signature_normalizes_range_and_desired_amounts(): void
    {
        $finder = new PathFinder(maxHops: 1, tolerance: '0.0');

        $method = new ReflectionMethod(PathFinder::class, 'stateSignature');
        $method->setAccessible(true);

        $desired = CurrencyScenarioFactory::money('USD', '2.50', 2);

        /** @var SearchStateSignature $signature */
        $signature = $method->invoke(
            $finder,
            SpendRange::fromBounds(
                CurrencyScenarioFactory::money('USD', '1.0', 1),
                CurrencyScenarioFactory::money('USD', '5.0000', 4),
            ),
            $desired,
        );

        self::assertInstanceOf(SearchStateSignature::class, $signature);
        self::assertSame('range:USD:1.0000:5.0000:4|desired:USD:2.5000:4', $signature->value());
    }

    public function test_edge_supports_amount_rejects_positive_spend_when_edge_only_supports_zero(): void
    {
        $order = OrderFactory::buy(
            base: 'EUR',
            quote: 'USD',
            minAmount: '0',
            maxAmount: '0',
            rate: '1.200',
            amountScale: 3,
            rateScale: 3,
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $graph['EUR']['edges'][0];

        $method = new ReflectionMethod(PathFinder::class, 'edgeSupportsAmount');
        $method->setAccessible(true);

        $finder = new PathFinder(maxHops: 1, tolerance: '0.0');
        $result = $method->invoke($finder, $edge, SpendRange::fromBounds(
            CurrencyScenarioFactory::money('EUR', '1.000', 3),
            CurrencyScenarioFactory::money('EUR', '2.000', 3),
        ));

        self::assertNull($result);
    }

    public function test_edge_supports_amount_returns_zero_range_for_zero_request(): void
    {
        $order = OrderFactory::buy(
            base: 'EUR',
            quote: 'USD',
            minAmount: '0',
            maxAmount: '0',
            rate: '1.200',
            amountScale: 3,
            rateScale: 3,
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $graph['EUR']['edges'][0];

        $method = new ReflectionMethod(PathFinder::class, 'edgeSupportsAmount');
        $method->setAccessible(true);

        $finder = new PathFinder(maxHops: 1, tolerance: '0.0');
        $result = $method->invoke($finder, $edge, SpendRange::fromBounds(
            CurrencyScenarioFactory::money('EUR', '0', 3),
            CurrencyScenarioFactory::money('EUR', '0', 3),
        ));

        self::assertInstanceOf(SpendRange::class, $result);
        self::assertSame('0.000', $result->min()->amount());
        self::assertSame('0.000', $result->max()->amount());
    }

    public function test_calculate_next_range_normalizes_descending_bounds(): void
    {
        $order = OrderFactory::buy(
            base: 'EUR',
            quote: 'USD',
            minAmount: '1.000',
            maxAmount: '5.000',
            rate: '1.200',
            amountScale: 3,
            rateScale: 3,
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $graph['EUR']['edges'][0];

        $method = new ReflectionMethod(PathFinder::class, 'calculateNextRange');
        $method->setAccessible(true);

        $range = SpendRange::fromBounds(
            CurrencyScenarioFactory::money('EUR', '5.000', 3),
            CurrencyScenarioFactory::money('EUR', '1.000', 3),
        );

        $finder = new PathFinder(maxHops: 1, tolerance: '0.0');
        $result = $method->invoke($finder, $edge, $range);

        $convertMethod = new ReflectionMethod(PathFinder::class, 'convertEdgeAmount');
        $convertMethod->setAccessible(true);

        $convertedMin = $convertMethod->invoke($finder, $edge, $range->min());
        $convertedMax = $convertMethod->invoke($finder, $edge, $range->max());

        if ($convertedMin->greaterThan($convertedMax)) {
            [$convertedMin, $convertedMax] = [$convertedMax, $convertedMin];
        }

        self::assertSame($convertedMin->amount(), $result->min()->amount());
        self::assertSame($convertedMax->amount(), $result->max()->amount());
        self::assertSame($convertedMin->currency(), $result->min()->currency());
        self::assertSame($convertedMax->currency(), $result->max()->currency());
    }

    public function test_convert_edge_amount_returns_zero_when_edge_cannot_convert(): void
    {
        $order = OrderFactory::buy(
            base: 'EUR',
            quote: 'USD',
            minAmount: '0',
            maxAmount: '0',
            rate: '1.200',
            amountScale: 3,
            rateScale: 3,
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $graph['EUR']['edges'][0];

        $method = new ReflectionMethod(PathFinder::class, 'convertEdgeAmount');
        $method->setAccessible(true);

        $finder = new PathFinder(maxHops: 1, tolerance: '0.0');
        $amount = CurrencyScenarioFactory::money('EUR', '5.000', 3);
        $converted = $method->invoke($finder, $edge, $amount);

        self::assertSame('USD', $converted->currency());
        self::assertSame('0.000000000000000000', $converted->amount());
    }

    public function test_calculate_next_range_aligns_with_materializer_for_sell_with_fixed_quote_fee(): void
    {
        $feePolicy = new class implements FeePolicy {
            public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
            {
                $fee = Money::fromString($quoteAmount->currency(), '5.000000', max($quoteAmount->scale(), 6));

                return FeeBreakdown::forQuote($fee->withScale($quoteAmount->scale()));
            }

            public function fingerprint(): string
            {
                return 'fixed-quote:5.000000@6';
            }
        };

        $order = OrderFactory::sell(
            base: 'BTC',
            quote: 'USD',
            minAmount: '1.000',
            maxAmount: '3.000',
            rate: '20000.000',
            amountScale: 3,
            rateScale: 3,
            feePolicy: $feePolicy,
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $graph['USD']['edges'][0];

        $finder = new PathFinder(maxHops: 1, tolerance: '0.0');
        $range = SpendRange::fromBounds(
            $edge['quoteCapacity']['min'],
            $edge['quoteCapacity']['max'],
        );

        $method = new ReflectionMethod(PathFinder::class, 'calculateNextRange');
        $method->setAccessible(true);
        $convertedRange = $method->invoke($finder, $edge, $range);

        $materializer = new LegMaterializer();

        foreach (['min', 'max'] as $bound) {
            $baseAmount = 'min' === $bound ? $convertedRange->min() : $convertedRange->max();
            $evaluation = $materializer->evaluateSellQuote($order, $baseAmount);
            $grossQuote = $evaluation['grossQuote']->withScale(
                max($evaluation['grossQuote']->scale(), ('min' === $bound ? $range->min() : $range->max())->scale())
            );
            $rangeComparable = ('min' === $bound ? $range->min() : $range->max())->withScale($grossQuote->scale());

            self::assertSame($rangeComparable->amount(), $grossQuote->amount());
        }
    }

    public function test_edge_effective_conversion_rate_inverts_sell_edges(): void
    {
        $order = OrderFactory::sell(
            base: 'BTC',
            quote: 'USD',
            minAmount: '1.000',
            maxAmount: '1.000',
            rate: '30000',
            amountScale: 3,
            rateScale: 3,
        );

        $graph = (new GraphBuilder())->build([$order]);
        $edge = $graph['USD']['edges'][0];

        $finder = new PathFinder(maxHops: 1, tolerance: '0.0');

        $ratioMethod = new ReflectionMethod(PathFinder::class, 'edgeBaseToQuoteRatio');
        $ratioMethod->setAccessible(true);
        $ratio = $ratioMethod->invoke($finder, $edge);

        $method = new ReflectionMethod(PathFinder::class, 'edgeEffectiveConversionRate');
        $method->setAccessible(true);
        $conversion = $method->invoke($finder, $edge);

        self::assertSame(
            BcMath::div('1', $ratio, 18),
            $conversion,
        );
    }

    public function test_clamp_to_range_bounds_value(): void
    {
        $finder = new PathFinder(maxHops: 1, tolerance: '0.0');

        $range = SpendRange::fromBounds(
            CurrencyScenarioFactory::money('USD', '1.00', 2),
            CurrencyScenarioFactory::money('USD', '5.00', 2),
        );

        $method = new ReflectionMethod(PathFinder::class, 'clampToRange');
        $method->setAccessible(true);

        $below = CurrencyScenarioFactory::money('USD', '0.50', 2);
        $above = CurrencyScenarioFactory::money('USD', '10.00', 2);
        $within = CurrencyScenarioFactory::money('USD', '3.333', 3);

        $clampedBelow = $method->invoke($finder, $below, $range);
        $clampedAbove = $method->invoke($finder, $above, $range);
        $clampedWithin = $method->invoke($finder, $within, $range);

        self::assertSame('1.00', $clampedBelow->amount());
        self::assertSame('5.00', $clampedAbove->amount());
        self::assertSame('3.333', $clampedWithin->amount());
    }

    public function test_normalize_tolerance_rejects_non_numeric_strings(): void
    {
        $finder = new PathFinder(maxHops: 1, tolerance: '0.0');

        $method = new ReflectionMethod(PathFinder::class, 'normalizeTolerance');
        $method->setAccessible(true);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Tolerance must be numeric.');

        $method->invoke($finder, 'not-a-number');
    }

    public function test_normalize_tolerance_rejects_negative_values(): void
    {
        $finder = new PathFinder(maxHops: 1, tolerance: '0.0');

        $method = new ReflectionMethod(PathFinder::class, 'normalizeTolerance');
        $method->setAccessible(true);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Tolerance must be non-negative.');

        $method->invoke($finder, '-0.01');
    }

    public function test_normalize_tolerance_rejects_one_or_greater(): void
    {
        $finder = new PathFinder(maxHops: 1, tolerance: '0.0');

        $method = new ReflectionMethod(PathFinder::class, 'normalizeTolerance');
        $method->setAccessible(true);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Tolerance must be less than one.');

        $method->invoke($finder, '1.000');
    }

    public function test_normalize_tolerance_caps_values_close_to_one(): void
    {
        $finder = new PathFinder(maxHops: 1, tolerance: '0.0');

        $method = new ReflectionMethod(PathFinder::class, 'normalizeTolerance');
        $method->setAccessible(true);

        $almostOne = '0.9999999999999999999';
        $normalized = $method->invoke($finder, $almostOne);

        self::assertSame('0.'.str_repeat('9', 18), $normalized);
    }

    public function test_calculate_tolerance_amplifier_returns_one_for_zero_tolerance(): void
    {
        $finder = new PathFinder(maxHops: 1, tolerance: '0.0');

        $method = new ReflectionMethod(PathFinder::class, 'calculateToleranceAmplifier');
        $method->setAccessible(true);

        $amplifier = $method->invoke($finder, BcMath::normalize('0', 18));

        self::assertSame(BcMath::normalize('1', 18), $amplifier);
    }

    public function test_calculate_tolerance_amplifier_inverts_complement(): void
    {
        $finder = new PathFinder(maxHops: 1, tolerance: '0.0');

        $method = new ReflectionMethod(PathFinder::class, 'calculateToleranceAmplifier');
        $method->setAccessible(true);

        $tolerance = BcMath::normalize('0.25', 18);
        $amplifier = $method->invoke($finder, $tolerance);

        self::assertSame(BcMath::normalize('1.333333333333333333', 18), $amplifier);
    }

    public function test_record_result_trims_heap_to_requested_limit(): void
    {
        $finder = new PathFinder(maxHops: 1, tolerance: '0.0', topK: 2);

        $createHeap = new ReflectionMethod(PathFinder::class, 'createResultHeap');
        $createHeap->setAccessible(true);
        $heap = $createHeap->invoke($finder);

        $record = new ReflectionMethod(PathFinder::class, 'recordResult');
        $record->setAccessible(true);

        $finalize = new ReflectionMethod(PathFinder::class, 'finalizeResults');
        $finalize->setAccessible(true);

        $candidates = [
            ['cost' => '1.400', 'order' => 0],
            ['cost' => '0.900', 'order' => 1],
            ['cost' => '1.050', 'order' => 2],
        ];

        foreach ($candidates as $candidate) {
            $record->invoke(
                $finder,
                $heap,
                $this->buildCandidate($candidate['cost'], [[
                    'from' => 'SRC',
                    'to' => 'DST'.chr(65 + $candidate['order']),
                ]]),
                $candidate['order'],
            );
        }

        $finalized = $finalize->invoke($finder, $heap);

        self::assertCount(2, $finalized);
        $finalized = $finalized->toArray();
        self::assertSame(BcMath::normalize('0.900', 18), $finalized[0]->cost());
        self::assertSame(BcMath::normalize('1.050', 18), $finalized[1]->cost());
    }

    public function test_record_result_preserves_insertion_order_when_costs_are_equal(): void
    {
        $finder = new PathFinder(maxHops: 1, tolerance: '0.0', topK: 3);

        $createHeap = new ReflectionMethod(PathFinder::class, 'createResultHeap');
        $createHeap->setAccessible(true);
        $heap = $createHeap->invoke($finder);

        $record = new ReflectionMethod(PathFinder::class, 'recordResult');
        $record->setAccessible(true);

        $finalize = new ReflectionMethod(PathFinder::class, 'finalizeResults');
        $finalize->setAccessible(true);

        foreach ([0, 1, 2] as $order) {
            $record->invoke(
                $finder,
                $heap,
                CandidatePath::from(
                    BcMath::normalize('1.000', 18),
                    BcMath::normalize('1.000', 18),
                    $order,
                    $this->dummyEdges($order),
                ),
                $order,
            );
        }

        $finalized = $finalize->invoke($finder, $heap);

        self::assertCount(3, $finalized);
        $finalized = $finalized->toArray();
        self::assertSame(0, $finalized[0]->hops());
        self::assertSame(1, $finalized[1]->hops());
        self::assertSame(2, $finalized[2]->hops());
    }

    public function test_search_state_queue_prioritizes_lowest_cost_entries(): void
    {
        new PathFinder(maxHops: 1, tolerance: '0.0');

        $queue = new SearchStateQueue(18);
        $queue->push(new SearchQueueEntry(
            $this->searchState(
                'high',
                BcMath::normalize('1.500', 18),
                BcMath::normalize('0.666', 18),
                1,
            ),
            new SearchStatePriority(
                new PathCost(BcMath::normalize('1.500', 18)),
                1,
                new RouteSignature(['SRC', 'high']),
                0,
            ),
        ));

        $queue->push(new SearchQueueEntry(
            $this->searchState(
                'low',
                BcMath::normalize('0.750', 18),
                BcMath::normalize('1.333', 18),
                1,
            ),
            new SearchStatePriority(
                new PathCost(BcMath::normalize('0.750', 18)),
                1,
                new RouteSignature(['SRC', 'low']),
                1,
            ),
        ));

        self::assertSame('low', $queue->extract()->node());
    }

    public function test_search_state_queue_orders_equal_cost_entries_by_hops_signature_then_fifo(): void
    {
        new PathFinder(maxHops: 1, tolerance: '0.0');

        $queue = new SearchStateQueue(18);

        $entries = [
            [
                'state' => $this->searchState(
                    'lexicographically-later',
                    BcMath::normalize('0.500', 18),
                    BcMath::normalize('2.000', 18),
                    2,
                ),
                'priority' => new SearchStatePriority(
                    new PathCost(BcMath::normalize('0.500', 18)),
                    2,
                    new RouteSignature(['SRC', 'A', 'C']),
                    0,
                ),
            ],
            [
                'state' => $this->searchState(
                    'fewer-hops',
                    BcMath::normalize('0.500', 18),
                    BcMath::normalize('2.000', 18),
                    1,
                ),
                'priority' => new SearchStatePriority(
                    new PathCost(BcMath::normalize('0.500', 18)),
                    1,
                    new RouteSignature(['SRC', 'A']),
                    1,
                ),
            ],
            [
                'state' => $this->searchState(
                    'lexicographically-first',
                    BcMath::normalize('0.500', 18),
                    BcMath::normalize('2.000', 18),
                    2,
                ),
                'priority' => new SearchStatePriority(
                    new PathCost(BcMath::normalize('0.500', 18)),
                    2,
                    new RouteSignature(['SRC', 'A', 'B']),
                    2,
                ),
            ],
            [
                'state' => $this->searchState(
                    'lexicographically-later-second',
                    BcMath::normalize('0.500', 18),
                    BcMath::normalize('2.000', 18),
                    2,
                ),
                'priority' => new SearchStatePriority(
                    new PathCost(BcMath::normalize('0.500', 18)),
                    2,
                    new RouteSignature(['SRC', 'A', 'C']),
                    3,
                ),
            ],
        ];

        foreach ($entries as $entry) {
            $queue->push(new SearchQueueEntry($entry['state'], $entry['priority']));
        }

        $extracted = [];
        while (!$queue->isEmpty()) {
            $extracted[] = $queue->extract()->node();
        }

        self::assertSame(
            [
                'fewer-hops',
                'lexicographically-first',
                'lexicographically-later',
                'lexicographically-later-second',
            ],
            $extracted,
        );
    }

    public function test_finalize_results_sorts_candidates_by_cost(): void
    {
        $finder = new PathFinder(maxHops: 2, tolerance: '0.0', topK: 4);

        $createHeap = new ReflectionMethod(PathFinder::class, 'createResultHeap');
        $createHeap->setAccessible(true);

        $heap = $createHeap->invoke($finder);

        $recordResult = new ReflectionMethod(PathFinder::class, 'recordResult');
        $recordResult->setAccessible(true);

        $highCost = BcMath::normalize('2.000000000000000000', 18);
        $lowCost = BcMath::normalize('1.500000000000000000', 18);

        $recordResult->invokeArgs($finder, [$heap, $this->buildCandidate($highCost), 0]);
        $recordResult->invokeArgs($finder, [$heap, $this->buildCandidate($lowCost), 1]);

        $finalize = new ReflectionMethod(PathFinder::class, 'finalizeResults');
        $finalize->setAccessible(true);

        $results = $finalize->invoke($finder, $heap);

        self::assertCount(2, $results);
        $results = $results->toArray();
        self::assertSame($lowCost, $results[0]->cost());
        self::assertSame($highCost, $results[1]->cost());
    }

    public function test_finalize_results_preserves_insertion_order_for_equal_costs(): void
    {
        $finder = new PathFinder(maxHops: 2, tolerance: '0.0', topK: 4);

        $createHeap = new ReflectionMethod(PathFinder::class, 'createResultHeap');
        $createHeap->setAccessible(true);

        $heap = $createHeap->invoke($finder);

        $recordResult = new ReflectionMethod(PathFinder::class, 'recordResult');
        $recordResult->setAccessible(true);

        $cost = BcMath::normalize('1.750000000000000000', 18);

        $first = $this->buildCandidate($cost, [[
            'from' => 'SRC',
            'to' => 'MIDA',
        ]]);
        $second = $this->buildCandidate($cost, [[
            'from' => 'SRC',
            'to' => 'MIDB',
        ]]);

        $recordResult->invokeArgs($finder, [$heap, $first, 0]);
        $recordResult->invokeArgs($finder, [$heap, $second, 1]);

        $finalize = new ReflectionMethod(PathFinder::class, 'finalizeResults');
        $finalize->setAccessible(true);

        $results = $finalize->invoke($finder, $heap);

        $results = $results->toArray();

        self::assertSame('MIDA', $results[0]->edges()[0]->to());
        self::assertSame('MIDB', $results[1]->edges()[0]->to());
    }

    private function searchState(string $node, string $cost, string $product, int $hops): SearchState
    {
        return SearchState::fromComponents(
            $node,
            $cost,
            $product,
            $hops,
            PathEdgeSequence::empty(),
            null,
            null,
            [$node => true],
        );
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

    private function buildCandidate(string $cost, array $edges = []): CandidatePath
    {
        $normalized = BcMath::normalize($cost, self::SCALE);

        $edgeSequence = [] === $edges
            ? PathEdgeSequence::empty()
            : PathEdgeSequence::fromList(array_map(
                fn (array $edge): PathEdge => PathEdge::create(
                    $edge['from'],
                    $edge['to'],
                    $edge['order'],
                    $edge['rate'],
                    $edge['orderSide'],
                    $edge['conversionRate'],
                ),
                $this->ensureEdgeDefaults($edges),
            ));

        return CandidatePath::from(
            $normalized,
            BcMath::normalize('1.000000000000000000', self::SCALE),
            $edgeSequence->count(),
            $edgeSequence,
        );
    }

    /**
     * @param list<array> $edges
     *
     * @return list<array>
     */
    private function ensureEdgeDefaults(array $edges): array
    {
        return array_map(
            static function (array $edge): array {
                $from = $edge['from'] ?? 'SRC';
                $to = $edge['to'] ?? 'DST';
                $side = $edge['orderSide'] ?? OrderSide::BUY;

                $order = $edge['order'] ?? match ($side) {
                    OrderSide::BUY => OrderFactory::buy($from, $to, '1.000', '1.000', '1.000', 3, 3),
                    OrderSide::SELL => OrderFactory::sell($to, $from, '1.000', '1.000', '1.000', 3, 3),
                };

                $orderSide = $edge['orderSide'] ?? $order->side();

                return $edge + [
                    'from' => $from,
                    'to' => $to,
                    'order' => $order,
                    'rate' => $edge['rate'] ?? $order->effectiveRate(),
                    'orderSide' => $orderSide,
                    'conversionRate' => $edge['conversionRate'] ?? BcMath::normalize('1.000000000000000000', self::SCALE),
                ];
            },
            $edges,
        );
    }
}
