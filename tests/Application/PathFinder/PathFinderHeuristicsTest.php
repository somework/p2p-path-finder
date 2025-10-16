<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\PathFinder\PathFinder;
use SomeWork\P2PPathFinder\Application\PathFinder\SearchStateQueue;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Tests\Fixture\CurrencyScenarioFactory;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

use function count;

/**
 * @covers \SomeWork\P2PPathFinder\Application\PathFinder\PathFinder
 */
final class PathFinderHeuristicsTest extends TestCase
{
    public function test_dominated_state_is_detected(): void
    {
        $finder = new PathFinder(maxHops: 2, tolerance: 0.0);

        $method = new ReflectionMethod(PathFinder::class, 'isDominated');
        $method->setAccessible(true);

        $signature = 'range:null|desired:null';
        $existing = [
            [
                'cost' => BcMath::normalize('1.000', 18),
                'hops' => 1,
                'signature' => $signature,
            ],
        ];

        $result = $method->invoke(
            $finder,
            $existing,
            BcMath::normalize('1.250', 18),
            3,
            $signature,
        );

        self::assertTrue($result);
    }

    public function test_record_state_replaces_inferior_entries(): void
    {
        $finder = new PathFinder(maxHops: 2, tolerance: 0.0);

        $signatureMethod = new ReflectionMethod(PathFinder::class, 'stateSignature');
        $signatureMethod->setAccessible(true);
        $signature = $signatureMethod->invoke($finder, null, null);

        $registry = [
            'USD' => [
                [
                    'cost' => BcMath::normalize('2.000', 18),
                    'hops' => 3,
                    'signature' => $signature,
                ],
                [
                    'cost' => BcMath::normalize('3.000', 18),
                    'hops' => 4,
                    'signature' => 'other-signature',
                ],
            ],
        ];

        $method = new ReflectionMethod(PathFinder::class, 'recordState');
        $method->setAccessible(true);

        $args = [
            &$registry,
            'USD',
            BcMath::normalize('1.500', 18),
            1,
            null,
            null,
            $signature,
        ];

        $netChange = $method->invokeArgs($finder, $args);

        self::assertSame(0, $netChange);
        self::assertCount(2, $registry['USD']);
        self::assertSame('other-signature', $registry['USD'][0]['signature']);

        $newEntry = $registry['USD'][1];
        self::assertSame($signature, $newEntry['signature']);
        self::assertSame(BcMath::normalize('1.500', 18), $newEntry['cost']);
        self::assertSame(1, $newEntry['hops']);
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

        $range = [
            'min' => CurrencyScenarioFactory::money('EUR', '1.000', 3),
            'max' => CurrencyScenarioFactory::money('EUR', '2.000', 3),
        ];

        $finder = new PathFinder(maxHops: 1, tolerance: 0.0);
        $result = $method->invoke($finder, $edge, $range);

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

        $range = [
            'min' => CurrencyScenarioFactory::money('EUR', '0', 3),
            'max' => CurrencyScenarioFactory::money('EUR', '0', 3),
        ];

        $finder = new PathFinder(maxHops: 1, tolerance: 0.0);
        $result = $method->invoke($finder, $edge, $range);

        self::assertNotNull($result);
        self::assertSame('0.000', $result['min']->amount());
        self::assertSame('0.000', $result['max']->amount());
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

        $range = [
            'min' => CurrencyScenarioFactory::money('EUR', '5.000', 3),
            'max' => CurrencyScenarioFactory::money('EUR', '1.000', 3),
        ];

        $finder = new PathFinder(maxHops: 1, tolerance: 0.0);
        $result = $method->invoke($finder, $edge, $range);

        $convertMethod = new ReflectionMethod(PathFinder::class, 'convertEdgeAmount');
        $convertMethod->setAccessible(true);

        $convertedMin = $convertMethod->invoke($finder, $edge, $range['min']);
        $convertedMax = $convertMethod->invoke($finder, $edge, $range['max']);

        if ($convertedMin->greaterThan($convertedMax)) {
            [$convertedMin, $convertedMax] = [$convertedMax, $convertedMin];
        }

        self::assertSame($convertedMin->amount(), $result['min']->amount());
        self::assertSame($convertedMax->amount(), $result['max']->amount());
        self::assertSame($convertedMin->currency(), $result['min']->currency());
        self::assertSame($convertedMax->currency(), $result['max']->currency());
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

        $finder = new PathFinder(maxHops: 1, tolerance: 0.0);
        $amount = CurrencyScenarioFactory::money('EUR', '5.000', 3);
        $converted = $method->invoke($finder, $edge, $amount);

        self::assertSame('USD', $converted->currency());
        self::assertSame('0.000000000000000000', $converted->amount());
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

        $finder = new PathFinder(maxHops: 1, tolerance: 0.0);

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
        $finder = new PathFinder(maxHops: 1, tolerance: 0.0);

        $range = [
            'min' => CurrencyScenarioFactory::money('USD', '1.00', 2),
            'max' => CurrencyScenarioFactory::money('USD', '5.00', 2),
        ];

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
        $finder = new PathFinder(maxHops: 1, tolerance: 0.0);

        $method = new ReflectionMethod(PathFinder::class, 'normalizeTolerance');
        $method->setAccessible(true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tolerance must be numeric.');

        $method->invoke($finder, 'not-a-number');
    }

    public function test_normalize_tolerance_rejects_negative_values(): void
    {
        $finder = new PathFinder(maxHops: 1, tolerance: 0.0);

        $method = new ReflectionMethod(PathFinder::class, 'normalizeTolerance');
        $method->setAccessible(true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tolerance must be non-negative.');

        $method->invoke($finder, '-0.01');
    }

    public function test_normalize_tolerance_rejects_one_or_greater(): void
    {
        $finder = new PathFinder(maxHops: 1, tolerance: 0.0);

        $method = new ReflectionMethod(PathFinder::class, 'normalizeTolerance');
        $method->setAccessible(true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tolerance must be less than one.');

        $method->invoke($finder, '1.000');
    }

    public function test_normalize_tolerance_normalizes_float_inputs(): void
    {
        $finder = new PathFinder(maxHops: 1, tolerance: 0.0);

        $method = new ReflectionMethod(PathFinder::class, 'normalizeTolerance');
        $method->setAccessible(true);

        $normalized = $method->invoke($finder, 0.5);

        self::assertSame(BcMath::normalize('0.5', 18), $normalized);
    }

    public function test_normalize_tolerance_caps_values_close_to_one(): void
    {
        $finder = new PathFinder(maxHops: 1, tolerance: 0.0);

        $method = new ReflectionMethod(PathFinder::class, 'normalizeTolerance');
        $method->setAccessible(true);

        $almostOne = '0.9999999999999999999';
        $normalized = $method->invoke($finder, $almostOne);

        self::assertSame('0.'.str_repeat('9', 18), $normalized);
    }

    public function test_calculate_tolerance_amplifier_returns_one_for_zero_tolerance(): void
    {
        $finder = new PathFinder(maxHops: 1, tolerance: 0.0);

        $method = new ReflectionMethod(PathFinder::class, 'calculateToleranceAmplifier');
        $method->setAccessible(true);

        $amplifier = $method->invoke($finder, BcMath::normalize('0', 18));

        self::assertSame(BcMath::normalize('1', 18), $amplifier);
    }

    public function test_calculate_tolerance_amplifier_inverts_complement(): void
    {
        $finder = new PathFinder(maxHops: 1, tolerance: 0.0);

        $method = new ReflectionMethod(PathFinder::class, 'calculateToleranceAmplifier');
        $method->setAccessible(true);

        $tolerance = BcMath::normalize('0.25', 18);
        $amplifier = $method->invoke($finder, $tolerance);

        self::assertSame(BcMath::normalize('1.333333333333333333', 18), $amplifier);
    }

    public function test_format_float_normalizes_negative_zero(): void
    {
        $finder = new PathFinder(maxHops: 1, tolerance: 0.0);

        $method = new ReflectionMethod(PathFinder::class, 'formatFloat');
        $method->setAccessible(true);

        $formatted = $method->invoke($finder, -0.0);

        self::assertSame('0', $formatted);
    }

    public function test_record_result_trims_heap_to_requested_limit(): void
    {
        $finder = new PathFinder(maxHops: 1, tolerance: 0.0, topK: 2);

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
            $normalizedCost = BcMath::normalize($candidate['cost'], 18);
            $record->invoke(
                $finder,
                $heap,
                [
                    'cost' => $normalizedCost,
                    'product' => BcMath::div('1', $normalizedCost, 18),
                    'hops' => 1,
                    'edges' => [],
                    'amountRange' => null,
                    'desiredAmount' => null,
                ],
                $candidate['order'],
            );
        }

        $finalized = $finalize->invoke($finder, $heap);

        self::assertCount(2, $finalized);
        self::assertSame(BcMath::normalize('0.900', 18), $finalized[0]['cost']);
        self::assertSame(BcMath::normalize('1.050', 18), $finalized[1]['cost']);
    }

    public function test_record_result_preserves_insertion_order_when_costs_are_equal(): void
    {
        $finder = new PathFinder(maxHops: 1, tolerance: 0.0, topK: 3);

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
                [
                    'cost' => BcMath::normalize('1.000', 18),
                    'product' => BcMath::normalize('1.000', 18),
                    'hops' => $order,
                    'edges' => [],
                    'amountRange' => null,
                    'desiredAmount' => null,
                ],
                $order,
            );
        }

        $finalized = $finalize->invoke($finder, $heap);

        self::assertCount(3, $finalized);
        self::assertSame(0, $finalized[0]['hops']);
        self::assertSame(1, $finalized[1]['hops']);
        self::assertSame(2, $finalized[2]['hops']);
    }

    public function test_search_state_queue_prioritizes_lowest_cost_entries(): void
    {
        new PathFinder(maxHops: 1, tolerance: 0.0);

        $queue = new SearchStateQueue(18);
        $queue->insert(
            [
                'node' => 'high',
                'cost' => BcMath::normalize('1.500', 18),
                'product' => BcMath::normalize('0.666', 18),
                'hops' => 1,
                'path' => [],
                'amountRange' => null,
                'desiredAmount' => null,
                'visited' => [],
            ],
            ['cost' => BcMath::normalize('1.500', 18), 'order' => 0],
        );

        $queue->insert(
            [
                'node' => 'low',
                'cost' => BcMath::normalize('0.750', 18),
                'product' => BcMath::normalize('1.333', 18),
                'hops' => 1,
                'path' => [],
                'amountRange' => null,
                'desiredAmount' => null,
                'visited' => [],
            ],
            ['cost' => BcMath::normalize('0.750', 18), 'order' => 1],
        );

        $first = $queue->extract();

        self::assertSame('low', $first['node']);
    }

    public function test_search_state_queue_prefers_earlier_insertion_when_costs_are_equal(): void
    {
        new PathFinder(maxHops: 1, tolerance: 0.0);

        $queue = new SearchStateQueue(18);

        foreach (['first', 'second', 'third'] as $order => $label) {
            $queue->insert(
                [
                    'node' => $label,
                    'cost' => BcMath::normalize('0.500', 18),
                    'product' => BcMath::normalize('2.000', 18),
                    'hops' => 1,
                    'path' => [],
                    'amountRange' => null,
                    'desiredAmount' => null,
                    'visited' => [],
                ],
                ['cost' => BcMath::normalize('0.500', 18), 'order' => $order],
            );
        }

        $extracted = [];
        while (!$queue->isEmpty()) {
            $extracted[] = $queue->extract()['node'];
        }

        self::assertSame(['first', 'second', 'third'], $extracted);
    }

    public function test_finalize_results_sorts_candidates_by_cost(): void
    {
        $finder = new PathFinder(maxHops: 2, tolerance: 0.0, topK: 4);

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
        self::assertSame($lowCost, $results[0]['cost']);
        self::assertSame($highCost, $results[1]['cost']);
    }

    public function test_finalize_results_preserves_insertion_order_for_equal_costs(): void
    {
        $finder = new PathFinder(maxHops: 2, tolerance: 0.0, topK: 4);

        $createHeap = new ReflectionMethod(PathFinder::class, 'createResultHeap');
        $createHeap->setAccessible(true);

        $heap = $createHeap->invoke($finder);

        $recordResult = new ReflectionMethod(PathFinder::class, 'recordResult');
        $recordResult->setAccessible(true);

        $cost = BcMath::normalize('1.750000000000000000', 18);

        $first = $this->buildCandidate($cost, [['label' => 'first']]);
        $second = $this->buildCandidate($cost, [['label' => 'second']]);

        $recordResult->invokeArgs($finder, [$heap, $first, 0]);
        $recordResult->invokeArgs($finder, [$heap, $second, 1]);

        $finalize = new ReflectionMethod(PathFinder::class, 'finalizeResults');
        $finalize->setAccessible(true);

        $results = $finalize->invoke($finder, $heap);

        self::assertSame([['label' => 'first']], $results[0]['edges']);
        self::assertSame([['label' => 'second']], $results[1]['edges']);
    }

    /**
     * @return array{
     *     cost: numeric-string,
     *     product: numeric-string,
     *     hops: int,
     *     edges: list<array<string, string>>,
     *     amountRange: null,
     *     desiredAmount: null,
     * }
     */
    private function buildCandidate(string $cost, array $edges = []): array
    {
        return [
            'cost' => $cost,
            'product' => BcMath::normalize('1.000000000000000000', 18),
            'hops' => count($edges),
            'edges' => $edges,
            'amountRange' => null,
            'desiredAmount' => null,
        ];
    }
}
