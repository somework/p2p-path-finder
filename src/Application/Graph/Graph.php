<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Graph;

use ArrayAccess;
use IteratorAggregate;
use JsonSerializable;
use LogicException;
use SomeWork\P2PPathFinder\Application\Support\GuardsArrayAccessOffset;
use Traversable;

/**
 * Directed multigraph representation keyed by asset symbol.
 *
 * @implements IteratorAggregate<string, GraphNode>
 * @implements ArrayAccess<string, GraphNode>
 */
final class Graph implements IteratorAggregate, JsonSerializable, ArrayAccess
{
    use GuardsArrayAccessOffset;

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

    public function offsetExists(mixed $offset): bool
    {
        $normalized = $this->normalizeStringOffset($offset);

        if (null === $normalized) {
            return false;
        }

        return $this->nodes->has($normalized);
    }

    public function offsetGet(mixed $offset): ?GraphNode
    {
        $normalized = $this->normalizeStringOffset($offset);

        if (null === $normalized) {
            return null;
        }

        return $this->nodes->get($normalized);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Graph is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Graph is immutable.');
    }

    /**
     * @return array<string, array{currency: string, edges: list<array<string, mixed>>}>
     */
    public function jsonSerialize(): array
    {
        return $this->nodes->jsonSerialize();
    }
}
