<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph;

use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * Directed multigraph representation keyed by asset symbol.
 *
 * @internal
 *
 * @implements IteratorAggregate<string, GraphNode>
 */
final class Graph implements IteratorAggregate, JsonSerializable
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
     * @return array<string, array{currency: string, edges: list<array<string, mixed>>}>
     */
    public function jsonSerialize(): array
    {
        return $this->nodes->jsonSerialize();
    }
}
