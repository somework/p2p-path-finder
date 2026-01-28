<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Model\Graph;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\EdgeCapacity;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\GraphEdge;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\GraphNode;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\GraphNodeCollection;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

#[CoversClass(GraphNodeCollection::class)]
final class GraphNodeCollectionTest extends TestCase
{
    public function test_empty_returns_empty_collection(): void
    {
        $collection = GraphNodeCollection::empty();

        self::assertSame(0, $collection->count());
        self::assertFalse($collection->has('USD'));
        self::assertNull($collection->get('USD'));
        self::assertSame([], $collection->toArray());
    }

    public function test_from_array_accepts_empty_array(): void
    {
        $collection = GraphNodeCollection::fromArray([]);

        self::assertSame(0, $collection->count());
        self::assertSame([], $collection->toArray());
    }

    public function test_from_array_rejects_non_graph_nodes(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Every graph node must be an instance of GraphNode.');

        GraphNodeCollection::fromArray(['not-a-node']);
    }

    public function test_from_array_rejects_duplicate_currencies(): void
    {
        $node1 = new GraphNode('USD');
        $node2 = new GraphNode('USD'); // Same currency

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Graph nodes must be unique per currency. "USD" was provided more than once.');

        GraphNodeCollection::fromArray([$node1, $node2]);
    }

    public function test_count_returns_number_of_nodes(): void
    {
        $node1 = new GraphNode('USD');
        $node2 = new GraphNode('EUR');
        $collection = GraphNodeCollection::fromArray([$node1, $node2]);

        self::assertSame(2, $collection->count());
    }

    public function test_has_returns_true_for_existing_currency(): void
    {
        $node = new GraphNode('USD');
        $collection = GraphNodeCollection::fromArray([$node]);

        self::assertTrue($collection->has('USD'));
    }

    public function test_has_returns_false_for_non_existing_currency(): void
    {
        $node = new GraphNode('USD');
        $collection = GraphNodeCollection::fromArray([$node]);

        self::assertFalse($collection->has('EUR'));
    }

    public function test_get_returns_node_for_existing_currency(): void
    {
        $node = new GraphNode('USD');
        $collection = GraphNodeCollection::fromArray([$node]);

        $retrieved = $collection->get('USD');
        self::assertSame($node, $retrieved);
    }

    public function test_get_returns_null_for_non_existing_currency(): void
    {
        $node = new GraphNode('USD');
        $collection = GraphNodeCollection::fromArray([$node]);

        $retrieved = $collection->get('EUR');
        self::assertNull($retrieved);
    }

    public function test_get_iterator_implements_iterator_aggregate(): void
    {
        $node1 = new GraphNode('USD');
        $node2 = new GraphNode('EUR');
        $collection = GraphNodeCollection::fromArray([$node1, $node2]);

        $iterator = $collection->getIterator();
        self::assertInstanceOf(\Traversable::class, $iterator);

        $nodes = [];
        foreach ($iterator as $currency => $node) {
            $nodes[$currency] = $node;
        }

        self::assertCount(2, $nodes);
        self::assertArrayHasKey('USD', $nodes);
        self::assertArrayHasKey('EUR', $nodes);
        self::assertSame($node1, $nodes['USD']);
        self::assertSame($node2, $nodes['EUR']);
    }

    public function test_to_array_returns_ordered_nodes(): void
    {
        $node1 = new GraphNode('USD');
        $node2 = new GraphNode('EUR');
        $node3 = new GraphNode('BTC');
        $collection = GraphNodeCollection::fromArray([$node1, $node2, $node3]);

        $array = $collection->toArray();

        self::assertSame(['USD' => $node1, 'EUR' => $node2, 'BTC' => $node3], $array);
    }

    public function test_preserves_insertion_order(): void
    {
        $node1 = new GraphNode('BTC');
        $node2 = new GraphNode('USD');
        $node3 = new GraphNode('EUR');
        $collection = GraphNodeCollection::fromArray([$node1, $node2, $node3]);

        $iterator = $collection->getIterator();

        $currencies = [];
        foreach ($iterator as $currency => $node) {
            $currencies[] = $currency;
        }

        self::assertSame(['BTC', 'USD', 'EUR'], $currencies);
    }

    public function test_single_node_collection(): void
    {
        $node = new GraphNode('USD');
        $collection = GraphNodeCollection::fromArray([$node]);

        self::assertSame(1, $collection->count());
        self::assertTrue($collection->has('USD'));
        self::assertSame($node, $collection->get('USD'));
        self::assertSame(['USD' => $node], $collection->toArray());
    }

    public function test_multiple_operations_consistency(): void
    {
        $node1 = new GraphNode('USD');
        $node2 = new GraphNode('EUR');
        $node3 = new GraphNode('BTC');
        $collection = GraphNodeCollection::fromArray([$node1, $node2, $node3]);

        // Test all operations are consistent
        self::assertSame(3, $collection->count());
        self::assertTrue($collection->has('USD'));
        self::assertTrue($collection->has('EUR'));
        self::assertTrue($collection->has('BTC'));
        self::assertFalse($collection->has('ETH'));

        self::assertSame($node1, $collection->get('USD'));
        self::assertSame($node2, $collection->get('EUR'));
        self::assertSame($node3, $collection->get('BTC'));

        $array = $collection->toArray();
        self::assertCount(3, $array);
        self::assertSame($node1, $array['USD']);
        self::assertSame($node2, $array['EUR']);
        self::assertSame($node3, $array['BTC']);
    }

    public function test_iterator_with_empty_collection(): void
    {
        $collection = GraphNodeCollection::empty();
        $iterator = $collection->getIterator();

        $nodes = [];
        foreach ($iterator as $currency => $node) {
            $nodes[$currency] = $node;
        }

        self::assertSame([], $nodes);
    }

    // ========================================================================
    // withoutOrders() Tests - Top-K Support
    // ========================================================================

    #[TestDox('withoutOrders returns same instance when exclusion array is empty')]
    public function test_without_orders_returns_same_instance_when_exclusion_empty(): void
    {
        $node = new GraphNode('USD');
        $collection = GraphNodeCollection::fromArray([$node]);

        $filtered = $collection->withoutOrders([]);

        self::assertSame($collection, $filtered);
    }

    #[TestDox('withoutOrders returns same instance when collection is empty')]
    public function test_without_orders_returns_same_instance_when_collection_empty(): void
    {
        $collection = GraphNodeCollection::empty();

        $filtered = $collection->withoutOrders([12345 => true]);

        self::assertSame($collection, $filtered);
    }

    #[TestDox('withoutOrders filters edges from nodes')]
    public function test_without_orders_filters_edges_from_nodes(): void
    {
        $order1 = OrderFactory::buy('USD', 'EUR', '10.00', '100.00', '0.92', 2, 2);
        $order2 = OrderFactory::buy('USD', 'GBP', '10.00', '100.00', '0.85', 2, 2);
        $edge1 = $this->createEdge('USD', 'EUR', $order1);
        $edge2 = $this->createEdge('USD', 'GBP', $order2);

        $node = new GraphNode('USD', [$edge1, $edge2]);
        $collection = GraphNodeCollection::fromArray([$node]);

        $excludedIds = [spl_object_id($order1) => true];
        $filtered = $collection->withoutOrders($excludedIds);

        self::assertNotSame($collection, $filtered);
        $filteredNode = $filtered->get('USD');
        self::assertNotNull($filteredNode);
        self::assertSame(1, $filteredNode->edges()->count());
    }

    #[TestDox('withoutOrders preserves all nodes even when edges are removed')]
    public function test_without_orders_preserves_nodes(): void
    {
        $order = OrderFactory::buy('USD', 'EUR', '10.00', '100.00', '0.92', 2, 2);
        $edge = $this->createEdge('USD', 'EUR', $order);

        $nodeWithEdge = new GraphNode('USD', [$edge]);
        $nodeWithoutEdge = new GraphNode('EUR');
        $collection = GraphNodeCollection::fromArray([$nodeWithEdge, $nodeWithoutEdge]);

        $excludedIds = [spl_object_id($order) => true];
        $filtered = $collection->withoutOrders($excludedIds);

        // Both nodes should still exist
        self::assertSame(2, $filtered->count());
        self::assertTrue($filtered->has('USD'));
        self::assertTrue($filtered->has('EUR'));
    }

    #[TestDox('withoutOrders returns same instance when no orders match')]
    public function test_without_orders_returns_same_when_no_match(): void
    {
        $order = OrderFactory::buy('USD', 'EUR', '10.00', '100.00', '0.92', 2, 2);
        $edge = $this->createEdge('USD', 'EUR', $order);

        $node = new GraphNode('USD', [$edge]);
        $collection = GraphNodeCollection::fromArray([$node]);

        // Use a non-existent ID
        $filtered = $collection->withoutOrders([999999999 => true]);

        self::assertSame($collection, $filtered);
    }

    #[TestDox('withoutOrders filters edges across multiple nodes')]
    public function test_without_orders_filters_across_multiple_nodes(): void
    {
        $order1 = OrderFactory::buy('USD', 'EUR', '10.00', '100.00', '0.92', 2, 2);
        $order2 = OrderFactory::buy('EUR', 'GBP', '10.00', '100.00', '0.85', 2, 2);
        $edge1 = $this->createEdge('USD', 'EUR', $order1);
        $edge2 = $this->createEdge('EUR', 'GBP', $order2);

        $nodeUsd = new GraphNode('USD', [$edge1]);
        $nodeEur = new GraphNode('EUR', [$edge2]);
        $collection = GraphNodeCollection::fromArray([$nodeUsd, $nodeEur]);

        // Exclude both orders
        $excludedIds = [
            spl_object_id($order1) => true,
            spl_object_id($order2) => true,
        ];
        $filtered = $collection->withoutOrders($excludedIds);

        // Both nodes should exist but with no edges
        self::assertSame(2, $filtered->count());
        self::assertSame(0, $filtered->get('USD')->edges()->count());
        self::assertSame(0, $filtered->get('EUR')->edges()->count());
    }

    #[TestDox('withoutOrders preserves insertion order')]
    public function test_without_orders_preserves_insertion_order(): void
    {
        $order = OrderFactory::buy('USD', 'EUR', '10.00', '100.00', '0.92', 2, 2);
        $edge = $this->createEdge('USD', 'EUR', $order);

        $node1 = new GraphNode('BTC');
        $node2 = new GraphNode('USD', [$edge]);
        $node3 = new GraphNode('EUR');
        $collection = GraphNodeCollection::fromArray([$node1, $node2, $node3]);

        $excludedIds = [spl_object_id($order) => true];
        $filtered = $collection->withoutOrders($excludedIds);

        $currencies = [];
        foreach ($filtered as $currency => $node) {
            $currencies[] = $currency;
        }

        self::assertSame(['BTC', 'USD', 'EUR'], $currencies);
    }

    #[TestDox('withoutOrders returns new instance when any node changes')]
    public function test_without_orders_returns_new_instance_when_changed(): void
    {
        $order = OrderFactory::buy('USD', 'EUR', '10.00', '100.00', '0.92', 2, 2);
        $edge = $this->createEdge('USD', 'EUR', $order);

        $node = new GraphNode('USD', [$edge]);
        $collection = GraphNodeCollection::fromArray([$node]);

        $excludedIds = [spl_object_id($order) => true];
        $filtered = $collection->withoutOrders($excludedIds);

        self::assertNotSame($collection, $filtered);
    }

    #[TestDox('withoutOrders is idempotent for same exclusion set')]
    public function test_without_orders_idempotent(): void
    {
        $order = OrderFactory::buy('USD', 'EUR', '10.00', '100.00', '0.92', 2, 2);
        $edge = $this->createEdge('USD', 'EUR', $order);

        $node = new GraphNode('USD', [$edge]);
        $collection = GraphNodeCollection::fromArray([$node]);

        $excludedIds = [spl_object_id($order) => true];
        $filtered1 = $collection->withoutOrders($excludedIds);
        $filtered2 = $filtered1->withoutOrders($excludedIds);

        // Second call should return same instance
        self::assertSame($filtered1, $filtered2);
    }

    #[TestDox('withoutOrders handles nodes without edges')]
    public function test_without_orders_handles_nodes_without_edges(): void
    {
        $node1 = new GraphNode('USD');
        $node2 = new GraphNode('EUR');
        $collection = GraphNodeCollection::fromArray([$node1, $node2]);

        $filtered = $collection->withoutOrders([12345 => true]);

        // Should return same instance - nothing to filter
        self::assertSame($collection, $filtered);
    }

    #[TestDox('withoutOrders with empty nodes and non-empty exclusion returns same')]
    public function test_without_orders_empty_nodes_non_empty_exclusion(): void
    {
        $collection = GraphNodeCollection::fromArray([]);

        $filtered = $collection->withoutOrders([12345 => true, 67890 => true]);

        self::assertSame($collection, $filtered);
        self::assertSame(0, $filtered->count());
    }

    #[TestDox('withoutOrders with non-empty nodes and empty exclusion returns same')]
    public function test_without_orders_non_empty_nodes_empty_exclusion(): void
    {
        $order = OrderFactory::buy('USD', 'EUR', '10.00', '100.00', '0.92', 2, 2);
        $edge = $this->createEdge('USD', 'EUR', $order);
        $node = new GraphNode('USD', [$edge]);
        $collection = GraphNodeCollection::fromArray([$node]);

        $filtered = $collection->withoutOrders([]);

        self::assertSame($collection, $filtered);
    }

    #[TestDox('withoutOrders filters correctly when only some nodes have matching edges')]
    public function test_without_orders_partial_node_match(): void
    {
        $order1 = OrderFactory::buy('USD', 'EUR', '10.00', '100.00', '0.92', 2, 2);
        $order2 = OrderFactory::buy('GBP', 'EUR', '10.00', '100.00', '0.85', 2, 2);
        $edge1 = $this->createEdge('USD', 'EUR', $order1);
        $edge2 = $this->createEdge('GBP', 'EUR', $order2);

        $node1 = new GraphNode('USD', [$edge1]);
        $node2 = new GraphNode('GBP', [$edge2]);
        $node3 = new GraphNode('EUR'); // No edges
        $collection = GraphNodeCollection::fromArray([$node1, $node2, $node3]);

        // Only exclude order1
        $excludedIds = [spl_object_id($order1) => true];
        $filtered = $collection->withoutOrders($excludedIds);

        self::assertNotSame($collection, $filtered);
        self::assertSame(3, $filtered->count());
        self::assertSame(0, $filtered->get('USD')->edges()->count());
        self::assertSame(1, $filtered->get('GBP')->edges()->count());
        self::assertSame(0, $filtered->get('EUR')->edges()->count());
    }

    #[TestDox('fromArray with empty array returns functional empty collection')]
    public function test_from_array_empty_returns_functional(): void
    {
        $collection = GraphNodeCollection::fromArray([]);

        // Kill ReturnRemoval mutant - verify the return value is usable
        self::assertSame(0, $collection->count());
        self::assertFalse($collection->has('USD'));
        self::assertNull($collection->get('USD'));

        // Can iterate without error
        $count = 0;
        foreach ($collection as $node) {
            ++$count;
        }
        self::assertSame(0, $count);
    }

    #[TestDox('withoutOrders with empty exclusion on non-empty collection returns same instance')]
    public function test_without_orders_empty_exclusion_returns_same(): void
    {
        $order = OrderFactory::buy('USD', 'EUR', '10.00', '100.00', '0.92', 2, 2);
        $edge = $this->createEdge('USD', 'EUR', $order);
        $node = new GraphNode('USD', [$edge]);
        $collection = GraphNodeCollection::fromArray([$node]);

        // Kill the || to && mutation by testing empty exclusion separately
        $result = $collection->withoutOrders([]);

        self::assertSame($collection, $result);
        self::assertSame(1, $result->count());
    }

    #[TestDox('withoutOrders on empty collection with exclusions returns same instance')]
    public function test_without_orders_empty_collection_returns_same(): void
    {
        $collection = GraphNodeCollection::fromArray([]);

        // Kill the || to && mutation by testing empty collection separately
        $result = $collection->withoutOrders([12345 => true]);

        self::assertSame($collection, $result);
        self::assertSame(0, $result->count());
    }

    /**
     * Helper to create a GraphEdge for testing.
     */
    private function createEdge(string $from, string $to, Order $order): GraphEdge
    {
        $minMoney = Money::fromString($from, '10.00', 2);
        $maxMoney = Money::fromString($from, '1000.00', 2);

        return new GraphEdge(
            $from,
            $to,
            OrderSide::BUY,
            $order,
            $order->effectiveRate(),
            new EdgeCapacity($minMoney, $maxMoney),
            new EdgeCapacity($minMoney, $maxMoney),
            new EdgeCapacity($minMoney, $maxMoney),
        );
    }
}
