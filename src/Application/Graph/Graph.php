<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Graph;

use ArrayAccess;
use ArrayIterator;
use IteratorAggregate;
use JsonSerializable;
use LogicException;
use Traversable;

/**
 * Directed multigraph representation keyed by asset symbol.
 *
 * @implements IteratorAggregate<string, GraphNode>
 * @implements ArrayAccess<string, GraphNode>
 */
final class Graph implements IteratorAggregate, JsonSerializable, ArrayAccess
{
    /**
     * @var array<string, GraphNode>
     */
    private readonly array $nodes;

    /**
     * @param array<string, GraphNode> $nodes
     */
    public function __construct(array $nodes = [])
    {
        $normalized = [];
        foreach ($nodes as $node) {
            if (!$node instanceof GraphNode) {
                continue;
            }

            $normalized[$node->currency()] = $node;
        }

        $this->nodes = $normalized;
    }

    /**
     * @return array<string, GraphNode>
     */
    public function nodes(): array
    {
        return $this->nodes;
    }

    public function hasNode(string $currency): bool
    {
        return isset($this->nodes[$currency]);
    }

    public function node(string $currency): ?GraphNode
    {
        return $this->nodes[$currency] ?? null;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->nodes);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->nodes[$offset]);
    }

    public function offsetGet(mixed $offset): ?GraphNode
    {
        return $this->nodes[$offset] ?? null;
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
        $serialized = [];
        foreach ($this->nodes as $currency => $node) {
            $serialized[$currency] = $node->jsonSerialize();
        }

        return $serialized;
    }
}
