<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Graph;

use IteratorAggregate;
use JsonSerializable;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use Traversable;

/**
 * Represents a currency node and its outgoing edges within the trading graph.
 *
 * @internal
 *
 * @implements IteratorAggregate<int, GraphEdge>
 */
final class GraphNode implements IteratorAggregate, JsonSerializable
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
        return $this->edges->getIterator();
    }

    /**
     * @return array{currency: string, edges: list<array<string, mixed>>}
     */
    public function jsonSerialize(): array
    {
        return [
            'currency' => $this->currency,
            'edges' => $this->edges->jsonSerialize(),
        ];
    }
}
