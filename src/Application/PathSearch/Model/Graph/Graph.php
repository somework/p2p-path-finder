<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph;

use IteratorAggregate;
use Traversable;

/**
 * Directed multigraph representation keyed by asset symbol.
 *
 * @internal
 *
 * @implements IteratorAggregate<string, GraphNode>
 */
final class Graph implements IteratorAggregate
{
    private readonly GraphNodeCollection $nodes;

    /**
     * @param GraphNodeCollection|array<array-key, GraphNode> $nodes
     */
    public function __construct(GraphNodeCollection|array $nodes = [])
    {
        $this->nodes = $nodes instanceof GraphNodeCollection
            ? $nodes
            : GraphNodeCollection::fromArray($nodes);
    }

    public function nodes(): GraphNodeCollection
    {
        return $this->nodes;
    }

    public function hasNode(string $currency): bool
    {
        return $this->nodes->has($currency);
    }

    public function node(string $currency): ?GraphNode
    {
        return $this->nodes->get($currency);
    }

    public function getIterator(): Traversable
    {
        return $this->nodes->getIterator();
    }

    /**
     * Returns a new Graph with specified orders excluded.
     *
     * Creates a filtered copy of this graph where edges referencing any of the
     * excluded orders are removed. Nodes are preserved even if they lose all
     * edges, as they may still be needed as source/target currencies.
     *
     * This method is used for Top-K path finding, where each subsequent search
     * excludes orders already used in previously found plans.
     *
     * @param array<int, true> $excludedOrderIds Order object IDs to exclude (spl_object_id keys)
     *
     * @return self New graph without the excluded orders
     */
    public function withoutOrders(array $excludedOrderIds): self
    {
        if ([] === $excludedOrderIds) {
            return $this;
        }

        $filteredNodes = $this->nodes->withoutOrders($excludedOrderIds);

        if ($filteredNodes === $this->nodes) {
            return $this;
        }

        return new self($filteredNodes);
    }
}
