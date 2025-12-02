<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use Traversable;

use function count;
use function sprintf;

/**
 * Immutable ordered collection of {@see GraphNode} instances keyed by currency.
 *
 * @internal
 *
 * @implements IteratorAggregate<string, GraphNode>
 */
final class GraphNodeCollection implements Countable, IteratorAggregate
{
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
            /* @phpstan-ignore-next-line instanceof.alwaysTrue */
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

    /**
     * @return array<string, GraphNode>
     */
    public function toArray(): array
    {
        return $this->buildOrderedNodes();
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
