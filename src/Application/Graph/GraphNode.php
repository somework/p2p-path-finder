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
 * Represents a currency node and its outgoing edges within the trading graph.
 *
 * @implements IteratorAggregate<int, GraphEdge>
 * @implements ArrayAccess<string, mixed>
 */
final class GraphNode implements IteratorAggregate, JsonSerializable, ArrayAccess
{
    /**
     * @var list<GraphEdge>
     */
    private readonly array $edges;

    /**
     * @param list<GraphEdge> $edges
     */
    public function __construct(private readonly string $currency, array $edges = [])
    {
        $normalized = [];
        foreach ($edges as $edge) {
            if (!$edge instanceof GraphEdge) {
                continue;
            }

            $normalized[] = $edge;
        }

        $this->edges = $normalized;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    /**
     * @return list<GraphEdge>
     */
    public function edges(): array
    {
        return $this->edges;
    }

    #[\Override]
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->edges);
    }

    #[\Override]
    public function offsetExists(mixed $offset): bool
    {
        return 'currency' === $offset || 'edges' === $offset;
    }

    #[\Override]
    public function offsetGet(mixed $offset): mixed
    {
        return match ($offset) {
            'currency' => $this->currency,
            'edges' => $this->edges,
            default => null,
        };
    }

    #[\Override]
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Graph nodes are immutable.');
    }

    #[\Override]
    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Graph nodes are immutable.');
    }

    /**
     * @return array{currency: string, edges: list<array<string, mixed>>}
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'currency' => $this->currency,
            'edges' => array_map(
                static fn (GraphEdge $edge): array => $edge->jsonSerialize(),
                $this->edges,
            ),
        ];
    }
}
