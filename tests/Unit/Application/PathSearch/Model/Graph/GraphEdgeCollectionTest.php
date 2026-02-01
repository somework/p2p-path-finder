<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Model\Graph;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\EdgeCapacity;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\EdgeSegment;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\GraphEdge;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\GraphEdgeCollection;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\Fee\FeeBreakdown;
use SomeWork\P2PPathFinder\Domain\Order\Fee\FeePolicy;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Fixture\FeePolicyFactory;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

use function count;

#[CoversClass(GraphEdgeCollection::class)]
final class GraphEdgeCollectionTest extends TestCase
{
    public function test_from_array_accepts_empty_list(): void
    {
        $collection = GraphEdgeCollection::fromArray([]);

        self::assertTrue($collection->isEmpty());
        self::assertNull($collection->originCurrency());
    }

    public function test_at_rejects_out_of_bounds_index(): void
    {
        $edge = $this->createEdge();
        $collection = GraphEdgeCollection::fromArray([$edge]);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Graph edge index must reference an existing position.');

        $collection->at(1);
    }

    public function test_first_returns_first_edge(): void
    {
        $edge = $this->createEdge();
        $collection = GraphEdgeCollection::fromArray([$edge]);

        $first = $collection->first();
        self::assertNotNull($first);
        $this->assertGraphEdgeContentSame($edge, $first);
    }

    public function test_from_array_sorts_edges_using_canonical_order(): void
    {
        $first = $this->createEdgeForQuote('USD');
        $second = $this->createEdgeForQuote('EUR');
        $third = $this->createEdgeForQuote('USD');

        $collection = GraphEdgeCollection::fromArray([$first, $third, $second]);

        $expected = [$first, $third, $second];
        usort($expected, GraphEdgeCollection::canonicalComparator());

        $this->assertGraphEdgesContentSame($expected, $collection->toArray());
    }

    public function test_from_array_rejects_fee_policy_with_empty_fingerprint(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Fee policy fingerprint must not be empty.');

        GraphEdgeCollection::fromArray([
            $this->createEdgeForQuote('USD', new class implements FeePolicy {
                public function calculate(OrderSide $side, Money $baseAmount, Money $quoteAmount): FeeBreakdown
                {
                    return FeeBreakdown::none();
                }

                public function fingerprint(): string
                {
                    return '';
                }
            }),
            $this->createEdgeForQuote('USD', FeePolicyFactory::baseSurcharge('0.005')),
        ]);
    }

    public function test_from_array_sorts_edges_with_same_quote_by_fee_fingerprint(): void
    {
        $first = $this->createEdgeForQuote('USD', FeePolicyFactory::baseSurcharge('0.015'));
        $second = $this->createEdgeForQuote('USD', FeePolicyFactory::baseSurcharge('0.020'));
        $third = $this->createEdgeForQuote('USD', FeePolicyFactory::baseSurcharge('0.010'));

        $collection = GraphEdgeCollection::fromArray([$first, $second, $third]);

        $this->assertGraphEdgesContentSame([
            $third,
            $first,
            $second,
        ], $collection->toArray());
    }

    public function test_empty_returns_empty_collection(): void
    {
        $collection = GraphEdgeCollection::empty();

        self::assertTrue($collection->isEmpty());
        self::assertSame(0, $collection->count());
        self::assertNull($collection->originCurrency());
        self::assertNull($collection->first());
    }

    public function test_count_returns_number_of_edges(): void
    {
        $collection = GraphEdgeCollection::empty();
        self::assertSame(0, $collection->count());

        $edge = $this->createEdge();
        $collection = GraphEdgeCollection::fromArray([$edge]);
        self::assertSame(1, $collection->count());

        $collection = GraphEdgeCollection::fromArray([$edge, $edge]);
        self::assertSame(2, $collection->count());
    }

    public function test_origin_currency_returns_correct_currency(): void
    {
        $collection = GraphEdgeCollection::empty();
        self::assertNull($collection->originCurrency());

        $edge = $this->createEdge(); // from USD
        $collection = GraphEdgeCollection::fromArray([$edge]);
        self::assertSame('USD', $collection->originCurrency());
    }

    public function test_comparator_returns_stored_comparator(): void
    {
        $collection = GraphEdgeCollection::empty();
        $comparator = $collection->comparator();

        self::assertInstanceOf(\Closure::class, $comparator);

        // Test that it's the canonical comparator
        $edge1 = $this->createEdgeForQuote('USD');
        $edge2 = $this->createEdgeForQuote('EUR');
        self::assertSame(-1, $comparator($edge2, $edge1)); // EUR before USD
    }

    public function test_get_iterator_implements_iterator_aggregate(): void
    {
        $edge1 = $this->createEdgeForQuote('USD');
        $edge2 = $this->createEdgeForQuote('EUR');
        $collection = GraphEdgeCollection::fromArray([$edge1, $edge2]);

        $iterator = $collection->getIterator();
        self::assertInstanceOf(\Traversable::class, $iterator);

        $edges = [];
        foreach ($iterator as $edge) {
            $edges[] = $edge;
        }

        // Edges are sorted by canonical comparator (EUR before USD)
        self::assertCount(2, $edges);
        self::assertSame('EUR', $edges[0]->order()->assetPair()->quote());
        self::assertSame('USD', $edges[1]->order()->assetPair()->quote());
    }

    public function test_iterator_works_with_empty_collection(): void
    {
        $collection = GraphEdgeCollection::empty();
        $iterator = $collection->getIterator();

        $edges = [];
        foreach ($iterator as $edge) {
            $edges[] = $edge;
        }

        self::assertSame([], $edges);
    }

    public function test_at_returns_edge_at_valid_index(): void
    {
        $edge1 = $this->createEdgeForQuote('USD');
        $edge2 = $this->createEdgeForQuote('EUR');
        $collection = GraphEdgeCollection::fromArray([$edge1, $edge2]);

        // Edges are sorted by canonical comparator (EUR before USD)
        self::assertSame('EUR', $collection->at(0)->order()->assetPair()->quote());
        self::assertSame('USD', $collection->at(1)->order()->assetPair()->quote());
    }

    public function test_at_rejects_negative_index(): void
    {
        $edge = $this->createEdge();
        $collection = GraphEdgeCollection::fromArray([$edge]);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Graph edge index must reference an existing position.');

        $collection->at(-1);
    }

    public function test_first_returns_null_for_empty_collection(): void
    {
        $collection = GraphEdgeCollection::empty();
        self::assertNull($collection->first());
    }

    public function test_to_array_returns_edges_array(): void
    {
        $edge1 = $this->createEdgeForQuote('USD');
        $edge2 = $this->createEdgeForQuote('EUR');
        $collection = GraphEdgeCollection::fromArray([$edge1, $edge2]);

        $edges = $collection->toArray();
        self::assertIsArray($edges);
        self::assertCount(2, $edges);
        // Edges are sorted by canonical comparator (EUR before USD)
        self::assertSame('EUR', $edges[0]->order()->assetPair()->quote());
        self::assertSame('USD', $edges[1]->order()->assetPair()->quote());
    }

    public function test_from_array_accepts_custom_comparator(): void
    {
        $edge1 = $this->createEdgeForQuote('USD');
        $edge2 = $this->createEdgeForQuote('EUR');

        // Custom comparator that sorts by quote in reverse order
        $customComparator = static fn (GraphEdge $a, GraphEdge $b): int => $b->order()->assetPair()->quote() <=> $a->order()->assetPair()->quote();

        $collection = GraphEdgeCollection::fromArray([$edge1, $edge2], $customComparator);

        // With reverse sorting, USD should come before EUR (U > E)
        self::assertSame('USD', $collection->at(0)->order()->assetPair()->quote());
        self::assertSame('EUR', $collection->at(1)->order()->assetPair()->quote());

        // Test that comparator() returns the custom one
        $storedComparator = $collection->comparator();
        self::assertSame(1, $storedComparator($edge2, $edge1)); // For descending order: EUR vs USD, EUR should come after USD, so return positive
    }

    public function test_from_array_rejects_non_list_arrays(): void
    {
        $edge = $this->createEdge();

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Graph edges must be provided as a list.');

        // Non-list array (associative)
        GraphEdgeCollection::fromArray(['key' => $edge]);
    }

    public function test_from_array_rejects_invalid_edge_objects(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Every graph edge must be an instance of GraphEdge.');

        GraphEdgeCollection::fromArray(['not-an-edge']);
    }

    public function test_from_array_rejects_edges_with_different_origin_currencies(): void
    {
        $edge1 = $this->createEdge(); // from USD
        $edge2 = $this->createEdgeForQuote('EUR'); // from BTC

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Graph edges must share the same origin currency.');

        GraphEdgeCollection::fromArray([$edge1, $edge2]);
    }

    public function test_from_array_sorts_edges_by_side_when_other_criteria_equal(): void
    {
        // Create edges with same quote currency and same order fingerprint but different sides
        $buyOrder = OrderFactory::buy(
            base: 'BTC',
            quote: 'USD',
            minAmount: '1.000',
            maxAmount: '2.000',
            rate: '2.000',
            amountScale: 3,
            rateScale: 3,
        );

        $sellOrder = OrderFactory::sell(
            base: 'BTC',
            quote: 'USD',
            minAmount: '1.000',
            maxAmount: '2.000',
            rate: '2.000',
            amountScale: 3,
            rateScale: 3,
        );

        $buyEdge = new GraphEdge(
            from: 'BTC',
            to: 'USD',
            orderSide: OrderSide::BUY,
            order: $buyOrder,
            rate: $buyOrder->effectiveRate(),
            baseCapacity: new EdgeCapacity(
                Money::fromString('BTC', '1.000', 3),
                Money::fromString('BTC', '2.000', 3),
            ),
            quoteCapacity: new EdgeCapacity(
                Money::fromString('USD', '2.000', 3),
                Money::fromString('USD', '4.000', 3),
            ),
            grossBaseCapacity: new EdgeCapacity(
                Money::fromString('BTC', '1.000', 3),
                Money::fromString('BTC', '2.000', 3),
            ),
        );

        $sellEdge = new GraphEdge(
            from: 'BTC',
            to: 'USD',
            orderSide: OrderSide::SELL,
            order: $sellOrder,
            rate: $sellOrder->effectiveRate(),
            baseCapacity: new EdgeCapacity(
                Money::fromString('BTC', '1.000', 3),
                Money::fromString('BTC', '2.000', 3),
            ),
            quoteCapacity: new EdgeCapacity(
                Money::fromString('USD', '2.000', 3),
                Money::fromString('USD', '4.000', 3),
            ),
            grossBaseCapacity: new EdgeCapacity(
                Money::fromString('BTC', '1.000', 3),
                Money::fromString('BTC', '2.000', 3),
            ),
        );

        $collection = GraphEdgeCollection::fromArray([$sellEdge, $buyEdge]);

        // BUY should come before SELL (BUY value = 1, SELL value = 2)
        self::assertSame(OrderSide::BUY, $collection->at(0)->orderSide());
        self::assertSame(OrderSide::SELL, $collection->at(1)->orderSide());
    }

    // ========================================================================
    // withoutOrders() Tests - Top-K Support
    // ========================================================================

    #[TestDox('withoutOrders returns same instance when exclusion array is empty')]
    public function test_without_orders_returns_same_instance_when_exclusion_empty(): void
    {
        $edge = $this->createEdge();
        $collection = GraphEdgeCollection::fromArray([$edge]);

        $filtered = $collection->withoutOrders([]);

        self::assertSame($collection, $filtered);
    }

    #[TestDox('withoutOrders returns same instance when collection is empty')]
    public function test_without_orders_returns_same_instance_when_collection_empty(): void
    {
        $collection = GraphEdgeCollection::empty();

        $filtered = $collection->withoutOrders([12345 => true]);

        self::assertSame($collection, $filtered);
    }

    #[TestDox('withoutOrders excludes single matching order')]
    public function test_without_orders_excludes_single_matching_order(): void
    {
        $edge1 = $this->createEdgeForQuote('USD');
        $edge2 = $this->createEdgeForQuote('EUR');
        $collection = GraphEdgeCollection::fromArray([$edge1, $edge2]);

        $orderId = spl_object_id($edge1->order());
        $filtered = $collection->withoutOrders([$orderId => true]);

        self::assertSame(1, $filtered->count());
        self::assertSame('EUR', $filtered->first()->order()->assetPair()->quote());
    }

    #[TestDox('withoutOrders excludes multiple matching orders')]
    public function test_without_orders_excludes_multiple_matching_orders(): void
    {
        $edge1 = $this->createEdgeForQuote('USD');
        $edge2 = $this->createEdgeForQuote('EUR');
        $edge3 = $this->createEdgeForQuote('GBP');
        $collection = GraphEdgeCollection::fromArray([$edge1, $edge2, $edge3]);

        $excludedIds = [
            spl_object_id($edge1->order()) => true,
            spl_object_id($edge3->order()) => true,
        ];
        $filtered = $collection->withoutOrders($excludedIds);

        self::assertSame(1, $filtered->count());
        self::assertSame('EUR', $filtered->first()->order()->assetPair()->quote());
    }

    #[TestDox('withoutOrders returns empty collection when all edges excluded')]
    public function test_without_orders_returns_empty_when_all_excluded(): void
    {
        $edge1 = $this->createEdgeForQuote('USD');
        $edge2 = $this->createEdgeForQuote('EUR');
        $collection = GraphEdgeCollection::fromArray([$edge1, $edge2]);

        $excludedIds = [
            spl_object_id($edge1->order()) => true,
            spl_object_id($edge2->order()) => true,
        ];
        $filtered = $collection->withoutOrders($excludedIds);

        self::assertTrue($filtered->isEmpty());
        self::assertSame(0, $filtered->count());
    }

    #[TestDox('withoutOrders returns same instance when no orders match')]
    public function test_without_orders_returns_same_instance_when_no_match(): void
    {
        $edge = $this->createEdge();
        $collection = GraphEdgeCollection::fromArray([$edge]);

        $filtered = $collection->withoutOrders([999999999 => true]);

        self::assertSame($collection, $filtered);
    }

    #[TestDox('withoutOrders preserves comparator in new collection')]
    public function test_without_orders_preserves_comparator(): void
    {
        $edge1 = $this->createEdgeForQuote('USD');
        $edge2 = $this->createEdgeForQuote('EUR');

        // Custom comparator
        $customComparator = static fn (GraphEdge $a, GraphEdge $b): int => $b->order()->assetPair()->quote() <=> $a->order()->assetPair()->quote();

        $collection = GraphEdgeCollection::fromArray([$edge1, $edge2], $customComparator);
        $orderId = spl_object_id($edge1->order());
        $filtered = $collection->withoutOrders([$orderId => true]);

        // Comparator should be preserved
        $storedComparator = $filtered->comparator();
        self::assertInstanceOf(\Closure::class, $storedComparator);
    }

    #[TestDox('withoutOrders preserves origin currency in new collection')]
    public function test_without_orders_preserves_origin_currency(): void
    {
        $edge1 = $this->createEdgeForQuote('USD');
        $edge2 = $this->createEdgeForQuote('EUR');
        $collection = GraphEdgeCollection::fromArray([$edge1, $edge2]);

        $orderId = spl_object_id($edge1->order());
        $filtered = $collection->withoutOrders([$orderId => true]);

        self::assertSame('BTC', $filtered->originCurrency());
    }

    #[TestDox('withoutOrders returns new instance when edges removed')]
    public function test_without_orders_returns_new_instance_when_changed(): void
    {
        $edge1 = $this->createEdgeForQuote('USD');
        $edge2 = $this->createEdgeForQuote('EUR');
        $collection = GraphEdgeCollection::fromArray([$edge1, $edge2]);

        $orderId = spl_object_id($edge1->order());
        $filtered = $collection->withoutOrders([$orderId => true]);

        self::assertNotSame($collection, $filtered);
    }

    #[TestDox('withoutOrders handles large exclusion set efficiently')]
    public function test_without_orders_handles_large_exclusion_set(): void
    {
        $edge = $this->createEdge();
        $collection = GraphEdgeCollection::fromArray([$edge]);

        // Create a large exclusion set that doesn't include our edge
        $excludedIds = [];
        for ($i = 0; $i < 1000; ++$i) {
            $excludedIds[$i] = true;
        }

        $filtered = $collection->withoutOrders($excludedIds);

        // Edge should still be present (its ID won't be in 0-999)
        self::assertSame($collection, $filtered);
    }

    #[TestDox('withoutOrders maintains order of remaining edges')]
    public function test_without_orders_maintains_edge_order(): void
    {
        $edge1 = $this->createEdgeForQuote('AAA');
        $edge2 = $this->createEdgeForQuote('BBB');
        $edge3 = $this->createEdgeForQuote('CCC');
        $collection = GraphEdgeCollection::fromArray([$edge1, $edge2, $edge3]);

        // Remove middle edge
        $orderId = spl_object_id($edge2->order());
        $filtered = $collection->withoutOrders([$orderId => true]);

        $edges = $filtered->toArray();
        self::assertSame(2, count($edges));
        self::assertSame('AAA', $edges[0]->order()->assetPair()->quote());
        self::assertSame('CCC', $edges[1]->order()->assetPair()->quote());
    }

    #[TestDox('withoutOrders with single edge collection returns empty when excluded')]
    public function test_without_orders_single_edge_returns_empty(): void
    {
        $edge = $this->createEdge();
        $collection = GraphEdgeCollection::fromArray([$edge]);

        $orderId = spl_object_id($edge->order());
        $filtered = $collection->withoutOrders([$orderId => true]);

        self::assertTrue($filtered->isEmpty());
        self::assertNull($filtered->first());
    }

    #[TestDox('withoutOrders is idempotent for same exclusion set')]
    public function test_without_orders_idempotent(): void
    {
        $edge1 = $this->createEdgeForQuote('USD');
        $edge2 = $this->createEdgeForQuote('EUR');
        $collection = GraphEdgeCollection::fromArray([$edge1, $edge2]);

        $excludedIds = [spl_object_id($edge1->order()) => true];

        $filtered1 = $collection->withoutOrders($excludedIds);
        $filtered2 = $filtered1->withoutOrders($excludedIds);

        // Second call should return same instance (no change)
        self::assertSame($filtered1, $filtered2);
    }

    #[TestDox('withoutOrders handles duplicate IDs in exclusion set gracefully')]
    public function test_without_orders_handles_duplicate_ids(): void
    {
        $edge1 = $this->createEdgeForQuote('USD');
        $edge2 = $this->createEdgeForQuote('EUR');
        $collection = GraphEdgeCollection::fromArray([$edge1, $edge2]);

        $orderId = spl_object_id($edge1->order());
        // Array keys are inherently unique, but test the behavior
        $excludedIds = [$orderId => true];

        $filtered = $collection->withoutOrders($excludedIds);

        self::assertSame(1, $filtered->count());
    }

    // ========================================================================
    // withOrderPenalties() Tests
    // ========================================================================

    #[TestDox('withOrderPenalties returns same instance when usageCounts empty')]
    public function test_with_order_penalties_returns_same_instance_when_usage_counts_empty(): void
    {
        $edge = $this->createEdge();
        $collection = GraphEdgeCollection::fromArray([$edge]);

        $result = $collection->withOrderPenalties([], '0.15');

        self::assertSame($collection, $result);
    }

    #[TestDox('withOrderPenalties returns same instance when collection empty')]
    public function test_with_order_penalties_returns_same_instance_when_collection_empty(): void
    {
        $collection = GraphEdgeCollection::empty();

        $result = $collection->withOrderPenalties([12345 => 2], '0.15');

        self::assertSame($collection, $result);
    }

    #[TestDox('withOrderPenalties returns same instance when no usageCount > 0')]
    public function test_with_order_penalties_returns_same_instance_when_no_usage(): void
    {
        $edge1 = $this->createEdgeForQuote('USD');
        $edge2 = $this->createEdgeForQuote('EUR');
        $collection = GraphEdgeCollection::fromArray([$edge1, $edge2]);

        // All usage counts are 0
        $usageCounts = [
            spl_object_id($edge1->order()) => 0,
            spl_object_id($edge2->order()) => 0,
        ];
        $result = $collection->withOrderPenalties($usageCounts, '0.15');

        self::assertSame($collection, $result);
    }

    #[TestDox('withOrderPenalties returns new collection with penalized edges when usageCount > 0')]
    public function test_with_order_penalties_applies_penalty_when_usage_count_positive(): void
    {
        $edge1 = $this->createEdgeForQuote('USD');
        $edge2 = $this->createEdgeForQuote('EUR');
        $collection = GraphEdgeCollection::fromArray([$edge1, $edge2]);

        $orderId = spl_object_id($edge1->order());
        $usageCounts = [$orderId => 1];
        $result = $collection->withOrderPenalties($usageCounts, '0.15');

        self::assertNotSame($collection, $result);
        self::assertSame(2, $result->count());

        // Find the edge that was penalized (same order as edge1)
        $resultEdges = $result->toArray();
        $penalizedEdge = null;
        foreach ($resultEdges as $e) {
            if (spl_object_id($e->order()) === $orderId) {
                $penalizedEdge = $e;
                break;
            }
        }
        self::assertNotNull($penalizedEdge);
        self::assertNotSame($edge1, $penalizedEdge, 'Penalized edge should be a new instance from withCapacityPenalty');
        self::assertTrue(
            $penalizedEdge->baseCapacity()->max()->lessThan($edge1->baseCapacity()->max()),
            'Edge with usageCount > 0 should have reduced max capacity'
        );
    }

    #[TestDox('withOrderPenalties replaces only edges with usageCount > 0')]
    public function test_with_order_penalties_only_penalizes_edges_with_positive_usage(): void
    {
        $edge1 = $this->createEdgeForQuote('USD');
        $edge2 = $this->createEdgeForQuote('EUR');
        $collection = GraphEdgeCollection::fromArray([$edge1, $edge2]);

        $usageCounts = [spl_object_id($edge1->order()) => 2];
        $result = $collection->withOrderPenalties($usageCounts, '0.15');

        $resultEdges = $result->toArray();
        $usdResult = $resultEdges[1]->order()->assetPair()->quote() === 'USD' ? $resultEdges[1] : $resultEdges[0];
        $eurResult = $resultEdges[1]->order()->assetPair()->quote() === 'EUR' ? $resultEdges[1] : $resultEdges[0];

        // Edge with usageCount 2 should have lower max than original
        $usdOriginal = $edge1->order()->assetPair()->quote() === 'USD' ? $edge1 : $edge2;
        $eurOriginal = $edge1->order()->assetPair()->quote() === 'EUR' ? $edge1 : $edge2;
        self::assertTrue($usdResult->baseCapacity()->max()->lessThan($usdOriginal->baseCapacity()->max()));
        // Edge with usageCount 0 unchanged (same capacity)
        self::assertTrue($eurResult->baseCapacity()->max()->equals($eurOriginal->baseCapacity()->max()));
    }

    private function createEdge(): GraphEdge
    {
        $order = OrderFactory::sell(
            base: 'USD',
            quote: 'EUR',
            minAmount: '1.000',
            maxAmount: '5.000',
            rate: '0.900',
            amountScale: 3,
            rateScale: 3,
        );

        return new GraphEdge(
            from: 'USD',
            to: 'EUR',
            orderSide: OrderSide::SELL,
            order: $order,
            rate: $order->effectiveRate(),
            baseCapacity: new EdgeCapacity(
                Money::fromString('USD', '1.000', 3),
                Money::fromString('USD', '5.000', 3),
            ),
            quoteCapacity: new EdgeCapacity(
                Money::fromString('EUR', '0.900', 3),
                Money::fromString('EUR', '4.500', 3),
            ),
            grossBaseCapacity: new EdgeCapacity(
                Money::fromString('USD', '1.000', 3),
                Money::fromString('USD', '5.000', 3),
            ),
            segments: [
                new EdgeSegment(
                    true,
                    new EdgeCapacity(
                        Money::fromString('USD', '1.000', 3),
                        Money::fromString('USD', '3.000', 3),
                    ),
                    new EdgeCapacity(
                        Money::fromString('EUR', '0.900', 3),
                        Money::fromString('EUR', '2.700', 3),
                    ),
                    new EdgeCapacity(
                        Money::fromString('USD', '1.000', 3),
                        Money::fromString('USD', '3.000', 3),
                    ),
                ),
            ],
        );
    }

    private function createEdgeForQuote(string $quoteCurrency, ?FeePolicy $feePolicy = null): GraphEdge
    {
        $order = OrderFactory::buy(
            base: 'BTC',
            quote: $quoteCurrency,
            minAmount: '1.000',
            maxAmount: '2.000',
            rate: '2.000',
            amountScale: 3,
            rateScale: 3,
            feePolicy: $feePolicy,
        );

        return new GraphEdge(
            from: 'BTC',
            to: $quoteCurrency,
            orderSide: OrderSide::BUY,
            order: $order,
            rate: $order->effectiveRate(),
            baseCapacity: new EdgeCapacity(
                Money::fromString('BTC', '1.000', 3),
                Money::fromString('BTC', '2.000', 3),
            ),
            quoteCapacity: new EdgeCapacity(
                Money::fromString($quoteCurrency, '2.000', 3),
                Money::fromString($quoteCurrency, '4.000', 3),
            ),
            grossBaseCapacity: new EdgeCapacity(
                Money::fromString('BTC', '1.000', 3),
                Money::fromString('BTC', '2.000', 3),
            ),
        );
    }

    /**
     * Asserts that two GraphEdge objects have the same content (not just object identity).
     */
    private function assertGraphEdgeContentSame(GraphEdge $expected, GraphEdge $actual): void
    {
        self::assertSame($expected->from(), $actual->from());
        self::assertSame($expected->to(), $actual->to());
        self::assertSame($expected->orderSide(), $actual->orderSide());

        // Compare order content
        $expectedOrder = $expected->order();
        $actualOrder = $actual->order();
        self::assertSame($expectedOrder->side(), $actualOrder->side());
        self::assertSame($expectedOrder->assetPair()->base(), $actualOrder->assetPair()->base());
        self::assertSame($expectedOrder->assetPair()->quote(), $actualOrder->assetPair()->quote());
        self::assertTrue($expectedOrder->bounds()->min()->equals($actualOrder->bounds()->min()));
        self::assertTrue($expectedOrder->bounds()->max()->equals($actualOrder->bounds()->max()));
        self::assertSame($expectedOrder->effectiveRate()->baseCurrency(), $actualOrder->effectiveRate()->baseCurrency());
        self::assertSame($expectedOrder->effectiveRate()->quoteCurrency(), $actualOrder->effectiveRate()->quoteCurrency());
        self::assertSame($expectedOrder->effectiveRate()->rate(), $actualOrder->effectiveRate()->rate());
        self::assertSame($expectedOrder->effectiveRate()->scale(), $actualOrder->effectiveRate()->scale());
        self::assertSame($expectedOrder->feePolicy()?->fingerprint(), $actualOrder->feePolicy()?->fingerprint());

        // Compare capacities
        self::assertTrue($expected->baseCapacity()->min()->equals($actual->baseCapacity()->min()));
        self::assertTrue($expected->baseCapacity()->max()->equals($actual->baseCapacity()->max()));
        self::assertTrue($expected->quoteCapacity()->min()->equals($actual->quoteCapacity()->min()));
        self::assertTrue($expected->quoteCapacity()->max()->equals($actual->quoteCapacity()->max()));
        self::assertTrue($expected->grossBaseCapacity()->min()->equals($actual->grossBaseCapacity()->min()));
        self::assertTrue($expected->grossBaseCapacity()->max()->equals($actual->grossBaseCapacity()->max()));
    }

    /**
     * Asserts that two arrays of GraphEdge objects have the same content.
     *
     * @param list<GraphEdge> $expected
     * @param list<GraphEdge> $actual
     */
    private function assertGraphEdgesContentSame(array $expected, array $actual): void
    {
        self::assertCount(count($expected), $actual);

        foreach ($expected as $index => $expectedEdge) {
            $this->assertGraphEdgeContentSame($expectedEdge, $actual[$index]);
        }
    }
}
