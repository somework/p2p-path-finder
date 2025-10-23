<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Graph;

use ArrayAccess;
use ArrayIterator;
use IteratorAggregate;
use JsonSerializable;
use LogicException;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use Traversable;

/**
 * Represents a currency node and its outgoing edges within the trading graph.
 *
 * @implements IteratorAggregate<int, GraphEdge>
 * @implements ArrayAccess<string, mixed>
 */
final class GraphNode implements IteratorAggregate, JsonSerializable, ArrayAccess
{
    private readonly GraphEdgeCollection $edges;

    /**
     * @param GraphEdgeCollection|array<array-key, GraphEdge> $edges
     */
    public function __construct(private readonly string $currency, GraphEdgeCollection|array $edges = [])
    {
        $collection = $edges instanceof GraphEdgeCollection
            ? $edges
            : GraphEdgeCollection::fromArray($edges);

        $origin = $collection->originCurrency();
        if (null !== $origin && $origin !== $this->currency) {
            throw new InvalidInput('Graph node currency must match edge origins.');
        }

        $this->edges = $collection;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function edges(): GraphEdgeCollection
    {
        return $this->edges;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->edges->toArray());
    }

    public function offsetExists(mixed $offset): bool
    {
        return 'currency' === $offset || 'edges' === $offset;
    }

    public function offsetGet(mixed $offset): mixed
    {
        return match ($offset) {
            'currency' => $this->currency,
            'edges' => $this->edges,
            default => null,
        };
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Graph nodes are immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Graph nodes are immutable.');
    }

    /**
     * @return array{currency: string, edges: list<array<string, mixed>>}
     */
    public function jsonSerialize(): array
    {
        return [
            'currency' => $this->currency,
            'edges' => array_map(
                static fn (GraphEdge $edge): array => $edge->jsonSerialize(),
                $this->edges->toArray(),
            ),
        ];
    }
}
