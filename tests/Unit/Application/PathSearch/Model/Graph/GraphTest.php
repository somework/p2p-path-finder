<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Model\Graph;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\EdgeCapacity;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\Graph;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\GraphEdge;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\GraphNode;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\GraphNodeCollection;
use SomeWork\P2PPathFinder\Domain\Money\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

#[CoversClass(Graph::class)]
final class GraphTest extends TestCase
{
    public function test_constructor_rejects_non_nodes(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Every graph node must be an instance of GraphNode.');

        new Graph(['USD' => 'not-a-node']);
    }

    public function test_constructor_rejects_duplicate_currencies(): void
    {
        $node = new GraphNode('USD');

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessageMatches('/Graph nodes must be unique per currency.*provided more than once/');

        new Graph([$node, $node]);
    }

    public function test_nodes_returns_node_collection(): void
    {
        $node1 = new GraphNode('USD');
        $node2 = new GraphNode('EUR');
        $graph = new Graph([$node1, $node2]);

        $nodes = $graph->nodes();
        self::assertInstanceOf(GraphNodeCollection::class, $nodes);
        self::assertSame(2, $nodes->count());
    }

    public function test_has_node_returns_true_for_existing_currency(): void
    {
        $node = new GraphNode('USD');
        $graph = new Graph([$node]);

        self::assertTrue($graph->hasNode('USD'));
    }

    public function test_has_node_returns_false_for_non_existing_currency(): void
    {
        $node = new GraphNode('USD');
        $graph = new Graph([$node]);

        self::assertFalse($graph->hasNode('EUR'));
    }

    public function test_node_returns_node_for_existing_currency(): void
    {
        $node = new GraphNode('USD');
        $graph = new Graph([$node]);

        $retrievedNode = $graph->node('USD');
        self::assertSame($node, $retrievedNode);
    }

    public function test_node_returns_null_for_non_existing_currency(): void
    {
        $node = new GraphNode('USD');
        $graph = new Graph([$node]);

        $retrievedNode = $graph->node('EUR');
        self::assertNull($retrievedNode);
    }

    public function test_get_iterator_implements_iterator_aggregate(): void
    {
        $node1 = new GraphNode('USD');
        $node2 = new GraphNode('EUR');
        $graph = new Graph([$node1, $node2]);

        $iterator = $graph->getIterator();
        self::assertInstanceOf(\Traversable::class, $iterator);

        $nodes = [];
        foreach ($iterator as $currency => $node) {
            $nodes[$currency] = $node;
        }

        self::assertCount(2, $nodes);
        self::assertArrayHasKey('USD', $nodes);
        self::assertArrayHasKey('EUR', $nodes);
    }

    public function test_constructor_accepts_graph_node_collection(): void
    {
        $node1 = new GraphNode('USD');
        $node2 = new GraphNode('EUR');
        $collection = GraphNodeCollection::fromArray([$node1, $node2]);
        $graph = new Graph($collection);

        self::assertSame($collection, $graph->nodes());
        self::assertTrue($graph->hasNode('USD'));
        self::assertTrue($graph->hasNode('EUR'));
    }

    public function test_constructor_accepts_empty_array(): void
    {
        $graph = new Graph([]);

        self::assertSame(0, $graph->nodes()->count());
        self::assertFalse($graph->hasNode('USD'));
    }

    public function test_constructor_accepts_empty_collection_default(): void
    {
        $graph = new Graph();

        self::assertSame(0, $graph->nodes()->count());
        self::assertFalse($graph->hasNode('USD'));
    }

    public function test_multiple_nodes_with_different_currencies(): void
    {
        $node1 = new GraphNode('USD');
        $node2 = new GraphNode('EUR');
        $node3 = new GraphNode('BTC');
        $graph = new Graph([$node1, $node2, $node3]);

        self::assertSame(3, $graph->nodes()->count());
        self::assertTrue($graph->hasNode('USD'));
        self::assertTrue($graph->hasNode('EUR'));
        self::assertTrue($graph->hasNode('BTC'));
        self::assertFalse($graph->hasNode('ETH'));
    }

    public function test_node_retrieval_with_edges(): void
    {
        // Create a node with edges to test full functionality
        $node = new GraphNode('USD'); // Empty node for simplicity
        $graph = new Graph([$node]);

        $retrieved = $graph->node('USD');
        self::assertNotNull($retrieved);
        self::assertSame('USD', $retrieved->currency());
        self::assertTrue($retrieved->edges()->isEmpty());
    }

    // ========================================================================
    // withoutOrders TESTS
    // ========================================================================

    #[TestDox('withoutOrders excludes specified orders from graph')]
    public function test_without_orders_excludes_specified_orders(): void
    {
        // Create orders
        $order1 = OrderFactory::buy('USD', 'EUR', '10.00', '1000.00', '0.92', 2, 2);
        $order2 = OrderFactory::buy('USD', 'GBP', '10.00', '1000.00', '0.80', 2, 2);

        // Create edges referencing these orders
        $edge1 = $this->createEdge('USD', 'EUR', $order1);
        $edge2 = $this->createEdge('USD', 'GBP', $order2);

        // Create graph with nodes and edges
        $nodeUsd = new GraphNode('USD', [$edge1, $edge2]);
        $nodeEur = new GraphNode('EUR');
        $nodeGbp = new GraphNode('GBP');
        $graph = new Graph([$nodeUsd, $nodeEur, $nodeGbp]);

        // Exclude order1
        $excludedIds = [spl_object_id($order1) => true];
        $filtered = $graph->withoutOrders($excludedIds);

        // Verify order1 edge is removed, order2 edge remains
        $usdNode = $filtered->node('USD');
        self::assertNotNull($usdNode);
        self::assertSame(1, $usdNode->edges()->count());

        $remainingEdge = $usdNode->edges()->first();
        self::assertNotNull($remainingEdge);
        self::assertSame($order2, $remainingEdge->order());
    }

    #[TestDox('withoutOrders returns new instance')]
    public function test_without_orders_returns_new_instance(): void
    {
        $order = OrderFactory::buy('USD', 'EUR', '10.00', '1000.00', '0.92', 2, 2);
        $edge = $this->createEdge('USD', 'EUR', $order);

        $nodeUsd = new GraphNode('USD', [$edge]);
        $nodeEur = new GraphNode('EUR');
        $graph = new Graph([$nodeUsd, $nodeEur]);

        $excludedIds = [spl_object_id($order) => true];
        $filtered = $graph->withoutOrders($excludedIds);

        // Should be different instance
        self::assertNotSame($graph, $filtered);
    }

    #[TestDox('withoutOrders with empty exclusion returns same graph')]
    public function test_without_orders_with_empty_exclusion_returns_same_graph(): void
    {
        $order = OrderFactory::buy('USD', 'EUR', '10.00', '1000.00', '0.92', 2, 2);
        $edge = $this->createEdge('USD', 'EUR', $order);

        $nodeUsd = new GraphNode('USD', [$edge]);
        $nodeEur = new GraphNode('EUR');
        $graph = new Graph([$nodeUsd, $nodeEur]);

        $filtered = $graph->withoutOrders([]);

        // Should return same instance for optimization
        self::assertSame($graph, $filtered);
    }

    #[TestDox('withoutOrders preserves nodes even when all edges removed')]
    public function test_without_orders_preserves_nodes_when_edges_removed(): void
    {
        $order = OrderFactory::buy('USD', 'EUR', '10.00', '1000.00', '0.92', 2, 2);
        $edge = $this->createEdge('USD', 'EUR', $order);

        $nodeUsd = new GraphNode('USD', [$edge]);
        $nodeEur = new GraphNode('EUR');
        $graph = new Graph([$nodeUsd, $nodeEur]);

        // Exclude all orders
        $excludedIds = [spl_object_id($order) => true];
        $filtered = $graph->withoutOrders($excludedIds);

        // Node should still exist but have no edges
        self::assertTrue($filtered->hasNode('USD'));
        self::assertTrue($filtered->hasNode('EUR'));

        $usdNode = $filtered->node('USD');
        self::assertNotNull($usdNode);
        self::assertTrue($usdNode->edges()->isEmpty());
    }

    #[TestDox('withoutOrders handles multiple orders exclusion')]
    public function test_without_orders_handles_multiple_orders_exclusion(): void
    {
        $order1 = OrderFactory::buy('USD', 'EUR', '10.00', '1000.00', '0.92', 2, 2);
        $order2 = OrderFactory::buy('USD', 'GBP', '10.00', '1000.00', '0.80', 2, 2);
        $order3 = OrderFactory::buy('USD', 'JPY', '10.00', '1000.00', '150.00', 2, 2);

        $edge1 = $this->createEdge('USD', 'EUR', $order1);
        $edge2 = $this->createEdge('USD', 'GBP', $order2);
        $edge3 = $this->createEdge('USD', 'JPY', $order3);

        $nodeUsd = new GraphNode('USD', [$edge1, $edge2, $edge3]);
        $graph = new Graph([$nodeUsd]);

        // Exclude order1 and order2
        $excludedIds = [
            spl_object_id($order1) => true,
            spl_object_id($order2) => true,
        ];
        $filtered = $graph->withoutOrders($excludedIds);

        // Only order3 edge should remain
        $usdNode = $filtered->node('USD');
        self::assertNotNull($usdNode);
        self::assertSame(1, $usdNode->edges()->count());

        $remainingEdge = $usdNode->edges()->first();
        self::assertNotNull($remainingEdge);
        self::assertSame($order3, $remainingEdge->order());
    }

    #[TestDox('withoutOrders handles non-existent order IDs gracefully')]
    public function test_without_orders_handles_nonexistent_order_ids(): void
    {
        $order = OrderFactory::buy('USD', 'EUR', '10.00', '1000.00', '0.92', 2, 2);
        $edge = $this->createEdge('USD', 'EUR', $order);

        $nodeUsd = new GraphNode('USD', [$edge]);
        $graph = new Graph([$nodeUsd]);

        // Exclude a non-existent order ID
        $nonExistentId = 999999999;
        $excludedIds = [$nonExistentId => true];
        $filtered = $graph->withoutOrders($excludedIds);

        // Graph should be unchanged (same instance returned)
        self::assertSame($graph, $filtered);
    }

    #[TestDox('withoutOrders on empty graph with exclusions returns same instance')]
    public function test_without_orders_empty_graph_with_exclusions(): void
    {
        $graph = new Graph([]);

        $filtered = $graph->withoutOrders([12345 => true]);

        // Empty graph should return same instance
        self::assertSame($graph, $filtered);
    }

    #[TestDox('withoutOrders filters and returns valid graph structure')]
    public function test_without_orders_returns_valid_graph(): void
    {
        $order1 = OrderFactory::buy('USD', 'EUR', '10.00', '1000.00', '0.92', 2, 2);
        $order2 = OrderFactory::buy('USD', 'GBP', '10.00', '1000.00', '0.85', 2, 2);
        $edge1 = $this->createEdge('USD', 'EUR', $order1);
        $edge2 = $this->createEdge('USD', 'GBP', $order2);

        $nodeUsd = new GraphNode('USD', [$edge1, $edge2]);
        $nodeEur = new GraphNode('EUR');
        $nodeGbp = new GraphNode('GBP');
        $graph = new Graph([$nodeUsd, $nodeEur, $nodeGbp]);

        $excludedIds = [spl_object_id($order1) => true];
        $filtered = $graph->withoutOrders($excludedIds);

        // Verify structure is valid
        self::assertNotSame($graph, $filtered);
        self::assertTrue($filtered->hasNode('USD'));
        self::assertTrue($filtered->hasNode('EUR'));
        self::assertTrue($filtered->hasNode('GBP'));

        $usdNode = $filtered->node('USD');
        self::assertNotNull($usdNode);
        self::assertSame(1, $usdNode->edges()->count());
        self::assertSame('GBP', $usdNode->edges()->first()->to());
    }

    #[TestDox('withoutOrders is commutative for disjoint exclusion sets')]
    public function test_without_orders_commutative(): void
    {
        $order1 = OrderFactory::buy('USD', 'EUR', '10.00', '1000.00', '0.92', 2, 2);
        $order2 = OrderFactory::buy('USD', 'GBP', '10.00', '1000.00', '0.85', 2, 2);
        $order3 = OrderFactory::buy('USD', 'JPY', '10.00', '1000.00', '110.00', 2, 2);
        $edge1 = $this->createEdge('USD', 'EUR', $order1);
        $edge2 = $this->createEdge('USD', 'GBP', $order2);
        $edge3 = $this->createEdge('USD', 'JPY', $order3);

        $nodeUsd = new GraphNode('USD', [$edge1, $edge2, $edge3]);
        $graph = new Graph([$nodeUsd]);

        $setA = [spl_object_id($order1) => true];
        $setB = [spl_object_id($order2) => true];

        // Filter A then B
        $filteredAB = $graph->withoutOrders($setA)->withoutOrders($setB);

        // Filter B then A
        $filteredBA = $graph->withoutOrders($setB)->withoutOrders($setA);

        // Results should be equivalent
        self::assertSame(
            $filteredAB->node('USD')->edges()->count(),
            $filteredBA->node('USD')->edges()->count()
        );
        self::assertSame(1, $filteredAB->node('USD')->edges()->count());
    }

    #[TestDox('withoutOrders combined exclusion equals sequential exclusion')]
    public function test_without_orders_combined_equals_sequential(): void
    {
        $order1 = OrderFactory::buy('USD', 'EUR', '10.00', '1000.00', '0.92', 2, 2);
        $order2 = OrderFactory::buy('USD', 'GBP', '10.00', '1000.00', '0.85', 2, 2);
        $edge1 = $this->createEdge('USD', 'EUR', $order1);
        $edge2 = $this->createEdge('USD', 'GBP', $order2);

        $nodeUsd = new GraphNode('USD', [$edge1, $edge2]);
        $graph = new Graph([$nodeUsd]);

        $id1 = spl_object_id($order1);
        $id2 = spl_object_id($order2);

        // Combined exclusion
        $combined = $graph->withoutOrders([$id1 => true, $id2 => true]);

        // Sequential exclusion
        $sequential = $graph->withoutOrders([$id1 => true])->withoutOrders([$id2 => true]);

        // Both should result in empty edges
        self::assertSame(0, $combined->node('USD')->edges()->count());
        self::assertSame(0, $sequential->node('USD')->edges()->count());
    }

    #[TestDox('withoutOrders with empty array returns exact same instance')]
    public function test_without_orders_empty_returns_same_instance(): void
    {
        $order = OrderFactory::buy('USD', 'EUR', '10.00', '1000.00', '0.92', 2, 2);
        $edge = $this->createEdge('USD', 'EUR', $order);
        $nodeUsd = new GraphNode('USD', [$edge]);
        $graph = new Graph([$nodeUsd]);

        // Empty exclusion must return exact same instance
        $result = $graph->withoutOrders([]);

        self::assertSame($graph, $result);
        // Verify graph is still functional
        self::assertSame(1, $result->node('USD')->edges()->count());
    }

    // ========================================================================
    // withOrderPenalties TESTS
    // ========================================================================

    #[TestDox('withOrderPenalties applies penalties to specified orders')]
    public function test_with_order_penalties_applies_penalties(): void
    {
        $order = OrderFactory::buy('USD', 'EUR', '10.00', '1000.00', '0.92', 2, 2);
        $edge = $this->createEdge('USD', 'EUR', $order);

        $nodeUsd = new GraphNode('USD', [$edge]);
        $nodeEur = new GraphNode('EUR');
        $graph = new Graph([$nodeUsd, $nodeEur]);

        $orderId = spl_object_id($order);
        $penalized = $graph->withOrderPenalties([$orderId => 1], '0.15');

        // Should be different instance
        self::assertNotSame($graph, $penalized);

        // Penalized edge should have reduced capacity
        $penalizedNode = $penalized->node('USD');
        self::assertNotNull($penalizedNode);
        self::assertSame(1, $penalizedNode->edges()->count());

        $penalizedEdge = $penalizedNode->edges()->first();
        self::assertNotNull($penalizedEdge);

        $originalMax = $edge->baseCapacity()->max()->decimal();
        $penalizedMax = $penalizedEdge->baseCapacity()->max()->decimal();

        self::assertTrue($penalizedMax->isLessThan($originalMax));
    }

    #[TestDox('withOrderPenalties with empty usage counts returns same graph')]
    public function test_with_order_penalties_empty_returns_same(): void
    {
        $order = OrderFactory::buy('USD', 'EUR', '10.00', '1000.00', '0.92', 2, 2);
        $edge = $this->createEdge('USD', 'EUR', $order);

        $nodeUsd = new GraphNode('USD', [$edge]);
        $graph = new Graph([$nodeUsd]);

        $penalized = $graph->withOrderPenalties([], '0.15');

        // Should return same instance
        self::assertSame($graph, $penalized);
    }

    #[TestDox('withOrderPenalties preserves unpenalized orders')]
    public function test_with_order_penalties_preserves_unpenalized(): void
    {
        $order1 = OrderFactory::buy('USD', 'EUR', '10.00', '1000.00', '0.92', 2, 2);
        $order2 = OrderFactory::buy('USD', 'GBP', '10.00', '1000.00', '0.80', 2, 2);
        $edge1 = $this->createEdge('USD', 'EUR', $order1);
        $edge2 = $this->createEdge('USD', 'GBP', $order2);

        $nodeUsd = new GraphNode('USD', [$edge1, $edge2]);
        $graph = new Graph([$nodeUsd]);

        // Only penalize order1
        $orderId1 = spl_object_id($order1);
        $penalized = $graph->withOrderPenalties([$orderId1 => 1], '0.15');

        $penalizedNode = $penalized->node('USD');
        self::assertNotNull($penalizedNode);
        self::assertSame(2, $penalizedNode->edges()->count());
    }

    #[TestDox('withOrderPenalties with non-existent order IDs returns same graph')]
    public function test_with_order_penalties_nonexistent_returns_same(): void
    {
        $order = OrderFactory::buy('USD', 'EUR', '10.00', '1000.00', '0.92', 2, 2);
        $edge = $this->createEdge('USD', 'EUR', $order);

        $nodeUsd = new GraphNode('USD', [$edge]);
        $graph = new Graph([$nodeUsd]);

        // Penalize a non-existent order ID
        $penalized = $graph->withOrderPenalties([999999999 => 1], '0.15');

        // Should return same instance since no changes
        self::assertSame($graph, $penalized);
    }

    #[TestDox('withOrderPenalties on empty graph returns same instance')]
    public function test_with_order_penalties_empty_graph(): void
    {
        $graph = new Graph([]);

        $penalized = $graph->withOrderPenalties([12345 => 1], '0.15');

        self::assertSame($graph, $penalized);
    }

    #[TestDox('withOrderPenalties higher usage count applies more penalty')]
    public function test_with_order_penalties_higher_count_more_penalty(): void
    {
        $order = OrderFactory::buy('USD', 'EUR', '10.00', '1000.00', '0.92', 2, 2);
        $edge = $this->createEdge('USD', 'EUR', $order);

        $nodeUsd = new GraphNode('USD', [$edge]);
        $graph = new Graph([$nodeUsd]);

        $orderId = spl_object_id($order);

        $penalized1 = $graph->withOrderPenalties([$orderId => 1], '0.15');
        $penalized2 = $graph->withOrderPenalties([$orderId => 2], '0.15');
        $penalized3 = $graph->withOrderPenalties([$orderId => 3], '0.15');

        $max1 = $penalized1->node('USD')->edges()->first()->baseCapacity()->max()->decimal();
        $max2 = $penalized2->node('USD')->edges()->first()->baseCapacity()->max()->decimal();
        $max3 = $penalized3->node('USD')->edges()->first()->baseCapacity()->max()->decimal();

        // Higher usage count = lower max
        self::assertTrue($max1->isGreaterThan($max2));
        self::assertTrue($max2->isGreaterThan($max3));
    }

    #[TestDox('withoutOrders with empty array returns instance with unchanged structure')]
    public function test_without_orders_empty_returns_unchanged_structure(): void
    {
        $order = OrderFactory::buy('USD', 'EUR', '10.00', '1000.00', '0.92', 2, 2);
        $edge = $this->createEdge('USD', 'EUR', $order);
        $nodeUsd = new GraphNode('USD', [$edge]);
        $graph = new Graph([$nodeUsd]);

        $result = $graph->withoutOrders([]);

        // Should return same instance (optimization)
        self::assertSame($graph, $result);

        // But also verify structure is intact
        self::assertTrue($result->hasNode('USD'));
        self::assertSame(1, $result->node('USD')->edges()->count());
        self::assertSame($order, $result->node('USD')->edges()->first()->order());
    }

    #[TestDox('withOrderPenalties with empty usage returns instance with unchanged structure')]
    public function test_with_order_penalties_empty_returns_unchanged_structure(): void
    {
        $order = OrderFactory::buy('USD', 'EUR', '10.00', '1000.00', '0.92', 2, 2);
        $edge = $this->createEdge('USD', 'EUR', $order);
        $nodeUsd = new GraphNode('USD', [$edge]);
        $graph = new Graph([$nodeUsd]);

        $result = $graph->withOrderPenalties([], '0.15');

        // Should return same instance (optimization)
        self::assertSame($graph, $result);

        // But also verify structure is intact
        self::assertTrue($result->hasNode('USD'));
        self::assertSame(1, $result->node('USD')->edges()->count());
        self::assertSame($order, $result->node('USD')->edges()->first()->order());
    }

    /**
     * Helper to create a GraphEdge for testing.
     */
    private function createEdge(string $from, string $to, \SomeWork\P2PPathFinder\Domain\Order\Order $order): GraphEdge
    {
        $minMoney = Money::fromString($from, '10.00', 2);
        $maxMoney = Money::fromString($from, '1000.00', 2);

        return new GraphEdge(
            $from,
            $to,
            OrderSide::BUY,
            $order,
            ExchangeRate::fromString($from, $to, '1.00', 2),
            new EdgeCapacity($minMoney, $maxMoney),
            new EdgeCapacity($minMoney, $maxMoney),
            new EdgeCapacity($minMoney, $maxMoney),
        );
    }
}
