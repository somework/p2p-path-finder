<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Model\Graph;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\Graph;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\GraphEdgeCollection;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\GraphNode;
use SomeWork\P2PPathFinder\Application\PathSearch\Service\GraphBuilder;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

use function sprintf;

#[CoversClass(GraphNode::class)]
final class GraphNodeTest extends TestCase
{
    public function test_constructor_rejects_non_edges(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Every graph edge must be an instance of GraphEdge.');

        new GraphNode('USD', ['not-an-edge']);
    }

    public function test_constructor_rejects_edges_from_other_currency(): void
    {
        $order = OrderFactory::buy(base: 'AAA', quote: 'BBB');
        $graph = (new GraphBuilder())->build([$order]);

        $edge = $this->edge($graph, 'AAA', 0);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Graph node currency must match edge origins.');

        new GraphNode('BBB', [$edge]);
    }

    public function test_currency_returns_currency(): void
    {
        $node = new GraphNode('USD');
        self::assertSame('USD', $node->currency());
    }

    public function test_edges_returns_edge_collection(): void
    {
        $order = OrderFactory::buy(base: 'BTC', quote: 'USD');
        $graph = (new GraphBuilder())->build([$order]);
        $edge = $this->edge($graph, 'BTC', 0);

        $node = new GraphNode('BTC', [$edge]);

        $edges = $node->edges();
        self::assertInstanceOf(GraphEdgeCollection::class, $edges);
        self::assertSame(1, $edges->count());
        self::assertSame($edge, $edges->at(0));
    }

    public function test_get_iterator_implements_iterator_aggregate(): void
    {
        $order = OrderFactory::buy(base: 'BTC', quote: 'USD');
        $graph = (new GraphBuilder())->build([$order]);
        $edge = $this->edge($graph, 'BTC', 0);

        $node = new GraphNode('BTC', [$edge]);

        $iterator = $node->getIterator();
        self::assertInstanceOf(\Traversable::class, $iterator);

        $edges = [];
        foreach ($iterator as $edgeItem) {
            $edges[] = $edgeItem;
        }

        self::assertSame([$edge], $edges);
    }

    public function test_constructor_accepts_graph_edge_collection(): void
    {
        $order = OrderFactory::buy(base: 'BTC', quote: 'USD');
        $graph = (new GraphBuilder())->build([$order]);
        $edge = $this->edge($graph, 'BTC', 0);

        $collection = GraphEdgeCollection::fromArray([$edge]);
        $node = new GraphNode('BTC', $collection);

        self::assertSame('BTC', $node->currency());
        self::assertSame($collection, $node->edges());
    }

    public function test_constructor_accepts_empty_edges_array(): void
    {
        $node = new GraphNode('USD', []);

        self::assertSame('USD', $node->currency());
        self::assertTrue($node->edges()->isEmpty());
    }

    public function test_constructor_accepts_empty_collection_default(): void
    {
        $node = new GraphNode('EUR');

        self::assertSame('EUR', $node->currency());
        self::assertTrue($node->edges()->isEmpty());
    }

    public function test_constructor_accepts_multiple_edges_from_same_currency(): void
    {
        $order1 = OrderFactory::buy(base: 'BTC', quote: 'USD');
        $order2 = OrderFactory::buy(base: 'BTC', quote: 'EUR');
        $graph = (new GraphBuilder())->build([$order1, $order2]);

        $edge1 = $this->edge($graph, 'BTC', 0);
        $edge2 = $this->edge($graph, 'BTC', 1);

        $node = new GraphNode('BTC', [$edge1, $edge2]);

        self::assertSame('BTC', $node->currency());
        self::assertSame(2, $node->edges()->count());
    }

    public function test_constructor_validates_edges_with_null_origin(): void
    {
        // Create an empty edge collection (has null origin)
        $emptyCollection = GraphEdgeCollection::empty();

        // This should be allowed since null origin means no edges
        $node = new GraphNode('USD', $emptyCollection);

        self::assertSame('USD', $node->currency());
        self::assertTrue($node->edges()->isEmpty());
    }

    // ========================================================================
    // withoutOrders() Tests - Top-K Support
    // ========================================================================

    #[TestDox('withoutOrders returns same instance when exclusion array is empty')]
    public function test_without_orders_returns_same_instance_when_exclusion_empty(): void
    {
        $order = OrderFactory::buy(base: 'BTC', quote: 'USD');
        $graph = (new GraphBuilder())->build([$order]);
        $edge = $this->edge($graph, 'BTC', 0);

        $node = new GraphNode('BTC', [$edge]);
        $filtered = $node->withoutOrders([]);

        self::assertSame($node, $filtered);
    }

    #[TestDox('withoutOrders returns same instance when node has no edges')]
    public function test_without_orders_returns_same_instance_when_no_edges(): void
    {
        $node = new GraphNode('USD');
        $filtered = $node->withoutOrders([12345 => true]);

        self::assertSame($node, $filtered);
    }

    #[TestDox('withoutOrders excludes matching order')]
    public function test_without_orders_excludes_matching_order(): void
    {
        $order1 = OrderFactory::buy(base: 'BTC', quote: 'USD');
        $order2 = OrderFactory::buy(base: 'BTC', quote: 'EUR');
        $graph = (new GraphBuilder())->build([$order1, $order2]);
        $edge1 = $this->edge($graph, 'BTC', 0);
        $edge2 = $this->edge($graph, 'BTC', 1);

        $node = new GraphNode('BTC', [$edge1, $edge2]);

        $excludedIds = [spl_object_id($order1) => true];
        $filtered = $node->withoutOrders($excludedIds);

        self::assertNotSame($node, $filtered);
        self::assertSame('BTC', $filtered->currency());
        self::assertSame(1, $filtered->edges()->count());
    }

    #[TestDox('withoutOrders returns new instance when edges removed')]
    public function test_without_orders_returns_new_instance_when_changed(): void
    {
        $order = OrderFactory::buy(base: 'BTC', quote: 'USD');
        $graph = (new GraphBuilder())->build([$order]);
        $edge = $this->edge($graph, 'BTC', 0);

        $node = new GraphNode('BTC', [$edge]);

        $excludedIds = [spl_object_id($order) => true];
        $filtered = $node->withoutOrders($excludedIds);

        self::assertNotSame($node, $filtered);
    }

    #[TestDox('withoutOrders returns same instance when no orders match')]
    public function test_without_orders_returns_same_when_no_match(): void
    {
        $order = OrderFactory::buy(base: 'BTC', quote: 'USD');
        $graph = (new GraphBuilder())->build([$order]);
        $edge = $this->edge($graph, 'BTC', 0);

        $node = new GraphNode('BTC', [$edge]);

        $filtered = $node->withoutOrders([999999999 => true]);

        self::assertSame($node, $filtered);
    }

    #[TestDox('withoutOrders preserves currency')]
    public function test_without_orders_preserves_currency(): void
    {
        $order = OrderFactory::buy(base: 'BTC', quote: 'USD');
        $graph = (new GraphBuilder())->build([$order]);
        $edge = $this->edge($graph, 'BTC', 0);

        $node = new GraphNode('BTC', [$edge]);

        $excludedIds = [spl_object_id($order) => true];
        $filtered = $node->withoutOrders($excludedIds);

        self::assertSame('BTC', $filtered->currency());
    }

    #[TestDox('withoutOrders returns node with empty edges when all excluded')]
    public function test_without_orders_returns_empty_edges_when_all_excluded(): void
    {
        $order = OrderFactory::buy(base: 'BTC', quote: 'USD');
        $graph = (new GraphBuilder())->build([$order]);
        $edge = $this->edge($graph, 'BTC', 0);

        $node = new GraphNode('BTC', [$edge]);

        $excludedIds = [spl_object_id($order) => true];
        $filtered = $node->withoutOrders($excludedIds);

        self::assertSame('BTC', $filtered->currency());
        self::assertTrue($filtered->edges()->isEmpty());
    }

    #[TestDox('withoutOrders excludes multiple orders')]
    public function test_without_orders_excludes_multiple_orders(): void
    {
        $order1 = OrderFactory::buy(base: 'BTC', quote: 'USD');
        $order2 = OrderFactory::buy(base: 'BTC', quote: 'EUR');
        $order3 = OrderFactory::buy(base: 'BTC', quote: 'GBP');
        $graph = (new GraphBuilder())->build([$order1, $order2, $order3]);

        $edge1 = $this->edge($graph, 'BTC', 0);
        $edge2 = $this->edge($graph, 'BTC', 1);
        $edge3 = $this->edge($graph, 'BTC', 2);

        $node = new GraphNode('BTC', [$edge1, $edge2, $edge3]);

        $excludedIds = [
            spl_object_id($order1) => true,
            spl_object_id($order3) => true,
        ];
        $filtered = $node->withoutOrders($excludedIds);

        self::assertSame(1, $filtered->edges()->count());
    }

    #[TestDox('withoutOrders is idempotent')]
    public function test_without_orders_idempotent(): void
    {
        $order = OrderFactory::buy(base: 'BTC', quote: 'USD');
        $graph = (new GraphBuilder())->build([$order]);
        $edge = $this->edge($graph, 'BTC', 0);

        $node = new GraphNode('BTC', [$edge]);

        $excludedIds = [spl_object_id($order) => true];
        $filtered1 = $node->withoutOrders($excludedIds);
        $filtered2 = $filtered1->withoutOrders($excludedIds);

        self::assertSame($filtered1, $filtered2);
    }

    private function edge(Graph $graph, string $currency, int $index): \SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\GraphEdge
    {
        $node = $graph->node($currency);
        self::assertNotNull($node, sprintf('Graph is missing node for currency "%s".', $currency));

        return $node->edges()->at($index);
    }
}
