<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Graph;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use SomeWork\P2PPathFinder\Application\Support\GuardsArrayAccessOffset;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use Traversable;

use function array_is_list;
use function count;

/**
 * Immutable ordered collection of {@see GraphEdge} instances for a single origin currency.
 *
 * @implements ArrayAccess<int, GraphEdge>
 * @implements IteratorAggregate<int, GraphEdge>
 */
final class GraphEdgeCollection implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    use GuardsArrayAccessOffset;

    /**
     * @var list<GraphEdge>
     */
    private array $edges;

    private readonly ?string $originCurrency;

    /**
     * @param list<GraphEdge> $edges
     */
    private function __construct(array $edges, ?string $originCurrency)
    {
        $this->edges = $edges;
        $this->originCurrency = $originCurrency;
    }

    public static function empty(): self
    {
        return new self([], null);
    }

    /**
     * @param array<array-key, GraphEdge> $edges
     */
    public static function fromArray(array $edges): self
    {
        if ([] === $edges) {
            return new self([], null);
        }

        if (!array_is_list($edges)) {
            throw new InvalidInput('Graph edges must be provided as a list.');
        }

        /** @var list<GraphEdge> $normalized */
        $normalized = [];
        $originCurrency = null;

        foreach ($edges as $edge) {
            if (!$edge instanceof GraphEdge) {
                throw new InvalidInput('Every graph edge must be an instance of GraphEdge.');
            }

            $from = $edge->from();

            if (null === $originCurrency) {
                $originCurrency = $from;
            } elseif ($originCurrency !== $from) {
                throw new InvalidInput('Graph edges must share the same origin currency.');
            }

            $normalized[] = $edge;
        }

        return new self($normalized, $originCurrency);
    }

    public function count(): int
    {
        return count($this->edges);
    }

    public function isEmpty(): bool
    {
        return [] === $this->edges;
    }

    public function originCurrency(): ?string
    {
        return $this->originCurrency;
    }

    /**
     * @return Traversable<int, GraphEdge>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->edges);
    }

    public function offsetExists(mixed $offset): bool
    {
        $normalized = $this->normalizeIntegerOffset($offset);

        if (null === $normalized) {
            return false;
        }

        return isset($this->edges[$normalized]);
    }

    public function offsetGet(mixed $offset): GraphEdge
    {
        $normalized = $this->normalizeIntegerOffset($offset);

        if (null === $normalized || !isset($this->edges[$normalized])) {
            throw new InvalidInput('Graph edge index must reference an existing position.');
        }

        return $this->edges[$normalized];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new InvalidInput('GraphEdgeCollection is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new InvalidInput('GraphEdgeCollection is immutable.');
    }

    /**
     * @return list<GraphEdge>
     */
    public function toArray(): array
    {
        return $this->edges;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function jsonSerialize(): array
    {
        $serialized = [];

        foreach ($this->edges as $edge) {
            $serialized[] = $edge->jsonSerialize();
        }

        return $serialized;
    }
}
