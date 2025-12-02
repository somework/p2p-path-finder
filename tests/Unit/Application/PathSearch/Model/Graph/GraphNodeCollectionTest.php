<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Tests\Unit\Application\PathSearch\Model\Graph;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\GraphNode;
use SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph\GraphNodeCollection;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

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
}
