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

use function count;
use function sprintf;

/**
 * Immutable ordered collection of {@see GraphNode} instances keyed by currency.
 *
 * @implements ArrayAccess<string, GraphNode>
 * @implements IteratorAggregate<string, GraphNode>
 */
final class GraphNodeCollection implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    use GuardsArrayAccessOffset;

    /**
     * @var array<string, GraphNode>
     */
    private array $nodes;

    /**
     * @var list<string>
     */
    private array $order;

    /**
     * @param array<string, GraphNode> $nodes
     * @param list<string>             $order
     */
    private function __construct(array $nodes, array $order)
    {
        $this->nodes = $nodes;
        $this->order = $order;
    }

    public static function empty(): self
    {
        return new self([], []);
    }

    /**
     * @param array<array-key, GraphNode> $nodes
     */
    public static function fromArray(array $nodes): self
    {
        if ([] === $nodes) {
            return new self([], []);
        }

        /** @var array<string, GraphNode> $byCurrency */
        $byCurrency = [];
        /** @var list<string> $order */
        $order = [];

        foreach ($nodes as $node) {
            if (!$node instanceof GraphNode) {
                throw new InvalidInput('Every graph node must be an instance of GraphNode.');
            }

            $currency = $node->currency();

            if (isset($byCurrency[$currency])) {
                throw new InvalidInput(sprintf('Graph nodes must be unique per currency. "%s" was provided more than once.', $currency));
            }

            $byCurrency[$currency] = $node;
            $order[] = $currency;
        }

        return new self($byCurrency, $order);
    }

    public function count(): int
    {
        return count($this->nodes);
    }

    public function has(string $currency): bool
    {
        return isset($this->nodes[$currency]);
    }

    public function get(string $currency): ?GraphNode
    {
        return $this->nodes[$currency] ?? null;
    }

    /**
     * @return Traversable<string, GraphNode>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->buildOrderedNodes());
    }

    public function offsetExists(mixed $offset): bool
    {
        $normalized = $this->normalizeStringOffset($offset);

        if (null === $normalized) {
            return false;
        }

        return isset($this->nodes[$normalized]);
    }

    public function offsetGet(mixed $offset): GraphNode
    {
        $normalized = $this->normalizeStringOffset($offset);

        if (null === $normalized || !isset($this->nodes[$normalized])) {
            throw new InvalidInput('Graph node currency must reference an existing node.');
        }

        return $this->nodes[$normalized];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new InvalidInput('GraphNodeCollection is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new InvalidInput('GraphNodeCollection is immutable.');
    }

    /**
     * @return array<string, GraphNode>
     */
    public function toArray(): array
    {
        return $this->buildOrderedNodes();
    }

    /**
     * @return array<string, array{currency: string, edges: list<array<string, mixed>>}>
     */
    public function jsonSerialize(): array
    {
        $serialized = [];

        foreach ($this->buildOrderedNodes() as $currency => $node) {
            $serialized[$currency] = $node->jsonSerialize();
        }

        return $serialized;
    }

    /**
     * @return array<string, GraphNode>
     */
    private function buildOrderedNodes(): array
    {
        $ordered = [];

        foreach ($this->order as $currency) {
            $ordered[$currency] = $this->nodes[$currency];
        }

        return $ordered;
    }
}
