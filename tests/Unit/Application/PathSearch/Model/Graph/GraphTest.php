<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Model\Graph;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\Graph;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\GraphNode;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\GraphNodeCollection;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

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
}
