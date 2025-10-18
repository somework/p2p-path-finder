<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\PathFinder;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SomeWork\P2PPathFinder\Application\PathFinder\CandidateResultHeap;
use SomeWork\P2PPathFinder\Application\PathFinder\PathFinder;
use SomeWork\P2PPathFinder\Application\PathFinder\SearchStateQueue;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Tests\Fixture\CurrencyScenarioFactory;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

use function array_is_list;
use function array_map;
use function is_array;
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
        $registry = [
            'USD' => [
                ['cost' => BcMath::normalize('1.2', self::SCALE), 'hops' => 3, 'signature' => $signature],
            ],
        ];

        $delta = $this->invokeFinderMethod(
            $finder,
            'recordState',
            [&$registry, 'USD', BcMath::normalize('1.0', self::SCALE), 2, $range, null, $signature],
        );

        self::assertSame(0, $delta);
        self::assertCount(1, $registry['USD']);
        self::assertSame($signature, $registry['USD'][0]['signature']);
        self::assertSame(BcMath::normalize('1.0', self::SCALE), $registry['USD'][0]['cost']);
        self::assertSame(2, $registry['USD'][0]['hops']);
    }

    public function test_record_state_preserves_existing_when_new_state_has_higher_hops(): void
    {
        $finder = new PathFinder(maxHops: 3, tolerance: '0.0');
        $range = [
            'min' => CurrencyScenarioFactory::money('USD', '0.50', 2),
            'max' => CurrencyScenarioFactory::money('USD', '3.00', 2),
        ];
        $signature = $this->invokeFinderMethod($finder, 'stateSignature', [$range, null]);
        $registry = [
            'USD' => [
                ['cost' => BcMath::normalize('1.2', self::SCALE), 'hops' => 2, 'signature' => $signature],
            ],
        ];

        $delta = $this->invokeFinderMethod(
            $finder,
            'recordState',
            [&$registry, 'USD', BcMath::normalize('1.0', self::SCALE), 4, $range, null, $signature],
        );

        self::assertSame(1, $delta);
        self::assertCount(2, $registry['USD']);
        $costsByHops = array_column($registry['USD'], 'cost', 'hops');
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
        $registry = [
            'USD' => [
                ['cost' => BcMath::normalize('1.0', self::SCALE), 'hops' => 4, 'signature' => $signature],
            ],
        ];

        $delta = $this->invokeFinderMethod(
            $finder,
            'recordState',
            [&$registry, 'USD', BcMath::normalize('1.5', self::SCALE), 2, $range, null, $signature],
        );

        self::assertSame(1, $delta);
        self::assertCount(2, $registry['USD']);
        $costsByHops = array_column($registry['USD'], 'cost', 'hops');
        self::assertSame(BcMath::normalize('1.0', self::SCALE), $costsByHops[4]);
        self::assertSame(BcMath::normalize('1.5', self::SCALE), $costsByHops[2]);
    }

    public function test_record_state_replaces_state_with_equal_hops_and_lower_cost(): void
    {
        $finder = new PathFinder(maxHops: 2, tolerance: '0.0');
        $range = [
            'min' => CurrencyScenarioFactory::money('USD', '1.00', 2),
            'max' => CurrencyScenarioFactory::money('USD', '2.00', 2),
        ];
        $signature = $this->invokeFinderMethod($finder, 'stateSignature', [$range, null]);
        $registry = [
            'USD' => [
                ['cost' => BcMath::normalize('1.5', self::SCALE), 'hops' => 2, 'signature' => $signature],
            ],
        ];

        $delta = $this->invokeFinderMethod(
            $finder,
            'recordState',
            [&$registry, 'USD', BcMath::normalize('1.0', self::SCALE), 2, $range, null, $signature],
        );

        self::assertSame(0, $delta);
        self::assertCount(1, $registry['USD']);
        self::assertSame(BcMath::normalize('1.0', self::SCALE), $registry['USD'][0]['cost']);
        self::assertSame(2, $registry['USD'][0]['hops']);
    }

    public function test_is_dominated_detects_matching_signature(): void
    {
        $finder = new PathFinder(maxHops: 2, tolerance: '0.0');
        $existing = [
            [
                'cost' => BcMath::normalize('1.0', self::SCALE),
                'hops' => 2,
                'signature' => 'sig',
            ],
        ];

        $dominated = $this->invokeFinderMethod(
            $finder,
            'isDominated',
            [$existing, BcMath::normalize('1.2', self::SCALE), 3, 'sig'],
        );

        self::assertTrue($dominated);
        self::assertFalse(
            $this->invokeFinderMethod($finder, 'isDominated', [$existing, BcMath::normalize('1.2', self::SCALE), 3, 'other']),
        );
    }

    public function test_has_state_with_signature_detects_existing(): void
    {
        $finder = new PathFinder(maxHops: 2, tolerance: '0.0');
        $existing = [
            ['cost' => '1', 'hops' => 1, 'signature' => 'alpha'],
            ['cost' => '2', 'hops' => 2, 'signature' => 'beta'],
        ];

        self::assertTrue($this->invokeFinderMethod($finder, 'hasStateWithSignature', [$existing, 'beta']));
        self::assertFalse($this->invokeFinderMethod($finder, 'hasStateWithSignature', [$existing, 'gamma']));
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

        $costs = array_map(static fn (array $entry): string => $entry['candidate']['cost'], $collected);
        sort($costs);

        self::assertSame([
            BcMath::normalize('0.50', self::SCALE),
            BcMath::normalize('1.00', self::SCALE),
        ], $costs);
    }

    public function test_finalize_results_orders_by_cost_then_insertion(): void
    {
        $finder = new PathFinder(maxHops: 2, tolerance: '0.0', topK: 3);
        /** @var CandidateResultHeap $heap */
        $heap = $this->invokeFinderMethod($finder, 'createResultHeap');

        $this->invokeFinderMethod($finder, 'recordResult', [$heap, $this->buildCandidate('2.00', '0.50'), 2]);
        $this->invokeFinderMethod($finder, 'recordResult', [$heap, $this->buildCandidate('1.00', '1.00'), 0]);
        $this->invokeFinderMethod($finder, 'recordResult', [$heap, $this->buildCandidate('1.00', '1.50'), 1]);

        $finalized = $this->invokeFinderMethod($finder, 'finalizeResults', [$heap]);

        self::assertSame([
            BcMath::normalize('1.00', self::SCALE),
            BcMath::normalize('1.00', self::SCALE),
            BcMath::normalize('2.00', self::SCALE),
        ], array_map(static fn (array $candidate): string => $candidate['cost'], $finalized));

        self::assertSame(
            BcMath::normalize('1.00', self::SCALE),
            $finalized[0]['product'],
        );
        self::assertSame(
            BcMath::normalize('1.50', self::SCALE),
            $finalized[1]['product'],
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

        $queue->insert($this->buildState('A'), ['cost' => BcMath::normalize('0.8', self::SCALE), 'order' => 1]);
        $queue->insert($this->buildState('B'), ['cost' => BcMath::normalize('0.5', self::SCALE), 'order' => 2]);
        $queue->insert($this->buildState('C'), ['cost' => BcMath::normalize('0.5', self::SCALE), 'order' => 0]);

        $first = $queue->extract();
        $second = $queue->extract();
        $third = $queue->extract();

        self::assertSame('C', $first['node']);
        self::assertSame('B', $second['node']);
        self::assertSame('A', $third['node']);
    }

    public function test_create_result_heap_orders_by_cost_and_insertion(): void
    {
        $finder = new PathFinder(maxHops: 2, tolerance: '0.0', topK: 3);
        /** @var CandidateResultHeap $heap */
        $heap = $this->invokeFinderMethod($finder, 'createResultHeap');

        $heap->insert(
            ['candidate' => $this->buildCandidate('1.00', '1.00'), 'order' => 0, 'cost' => BcMath::normalize('1.00', self::SCALE)],
            ['candidate' => $this->buildCandidate('1.00', '1.00'), 'order' => 0, 'cost' => BcMath::normalize('1.00', self::SCALE)],
        );
        $heap->insert(
            ['candidate' => $this->buildCandidate('2.00', '0.50'), 'order' => 2, 'cost' => BcMath::normalize('2.00', self::SCALE)],
            ['candidate' => $this->buildCandidate('2.00', '0.50'), 'order' => 2, 'cost' => BcMath::normalize('2.00', self::SCALE)],
        );
        $heap->insert(
            ['candidate' => $this->buildCandidate('1.00', '1.50'), 'order' => 1, 'cost' => BcMath::normalize('1.00', self::SCALE)],
            ['candidate' => $this->buildCandidate('1.00', '1.50'), 'order' => 1, 'cost' => BcMath::normalize('1.00', self::SCALE)],
        );

        $first = $heap->extract();
        $second = $heap->extract();
        $third = $heap->extract();

        self::assertSame(BcMath::normalize('2.00', self::SCALE), $first['candidate']['cost']);
        self::assertSame(BcMath::normalize('1.00', self::SCALE), $second['candidate']['cost']);
        self::assertSame(BcMath::normalize('1.00', self::SCALE), $third['candidate']['cost']);
        self::assertSame(BcMath::normalize('1.50', self::SCALE), $second['candidate']['product']);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function createSellEdge(array $overrides = []): array
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

        return $this->arrayReplaceRecursive($edge, $overrides);
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
     * @param array<int, mixed> $arguments
     */
    private function invokeFinderMethod(PathFinder $finder, string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionMethod(PathFinder::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($finder, $arguments);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCandidate(string $cost, string $product): array
    {
        return [
            'cost' => BcMath::normalize($cost, self::SCALE),
            'product' => BcMath::normalize($product, self::SCALE),
            'hops' => 1,
            'edges' => [],
            'amountRange' => null,
            'desiredAmount' => null,
        ];
    }

    /**
     * @return array{node: string, cost: string, product: string, hops: int, path: list<array>, amountRange: null, desiredAmount: null, visited: array<string, bool>}
     */
    private function buildState(string $node): array
    {
        $unit = BcMath::normalize('1', self::SCALE);

        return [
            'node' => $node,
            'cost' => $unit,
            'product' => $unit,
            'hops' => 0,
            'path' => [],
            'amountRange' => null,
            'desiredAmount' => null,
            'visited' => [$node => true],
        ];
    }
}
