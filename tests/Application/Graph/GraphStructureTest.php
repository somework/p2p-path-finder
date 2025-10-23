<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Application\Graph;

use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\Graph\Graph;
use SomeWork\P2PPathFinder\Application\Graph\GraphBuilder;
use SomeWork\P2PPathFinder\Application\Graph\GraphEdgeCollection;
use SomeWork\P2PPathFinder\Application\Graph\GraphNode;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Tests\Fixture\OrderFactory;

final class GraphStructureTest extends TestCase
{
    public function test_graph_constructor_rejects_non_nodes(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Every graph node must be an instance of GraphNode.');

        new Graph(['USD' => 'not-a-node']);
    }

    public function test_graph_node_constructor_rejects_non_edges(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Every graph edge must be an instance of GraphEdge.');

        new GraphNode('USD', ['not-an-edge']);
    }

    public function test_graph_node_constructor_rejects_edges_from_other_currency(): void
    {
        $order = OrderFactory::buy(base: 'AAA', quote: 'BBB');
        $graph = (new GraphBuilder())->build([$order]);

        $edge = $graph['AAA']['edges'][0];

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Graph node currency must match edge origins.');

        new GraphNode('BBB', [$edge]);
    }

    public function test_edge_collection_rejects_mixed_origin_currencies(): void
    {
        $firstOrder = OrderFactory::buy(base: 'AAA', quote: 'BBB');
        $secondOrder = OrderFactory::buy(base: 'BBB', quote: 'CCC');

        $graph = (new GraphBuilder())->build([$firstOrder, $secondOrder]);

        $first = $graph['AAA']['edges'][0];
        $second = $graph['BBB']['edges'][0];

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Graph edges must share the same origin currency.');

        GraphEdgeCollection::fromArray([$first, $second]);
    }

    public function test_graph_constructor_rejects_duplicate_currencies(): void
    {
        $node = new GraphNode('USD');

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Graph nodes must be unique per currency.');

        new Graph([$node, $node]);
    }
}
