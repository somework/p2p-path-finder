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

    /**
     * Returns a new collection excluding edges whose orders are in the exclusion set.
     *
     * Each node is filtered to remove edges referencing excluded orders. Nodes
     * that become empty (no remaining edges) are removed from the collection.
     *
     * @param array<int, true> $excludedOrderIds Order object IDs to exclude (spl_object_id)
     *
     * @return self New collection without the excluded orders
     */
    public function withoutOrders(array $excludedOrderIds): self
    {
        if ([] === $excludedOrderIds || [] === $this->nodes) {
            return $this;
        }

        /** @var array<string, GraphNode> $filteredNodes */
        $filteredNodes = [];
        /** @var list<string> $filteredOrder */
        $filteredOrder = [];
        $changed = false;

        foreach ($this->order as $currency) {
            $node = $this->nodes[$currency];
            $filteredNode = $node->withoutOrders($excludedOrderIds);

            if ($filteredNode !== $node) {
                $changed = true;
            }

            // Keep the node even if it has no edges - it might still be needed as a target
            $filteredNodes[$currency] = $filteredNode;
            $filteredOrder[] = $currency;
        }

        if (!$changed) {
            return $this;
        }

        if ([] === $filteredNodes) {
            return self::empty();
        }

        return new self($filteredNodes, $filteredOrder);
    }

    /**
     * Returns a new collection with capacity penalties applied to specified orders.
     *
     * @param array<int, int> $usageCounts   Order object ID => usage count
     * @param string          $penaltyFactor Penalty multiplier per usage
     *
     * @return self New collection with penalized edges
     */
    public function withOrderPenalties(array $usageCounts, string $penaltyFactor): self
    {
        if ([] === $usageCounts || [] === $this->nodes) {
            return $this;
        }

        /** @var array<string, GraphNode> $penalizedNodes */
        $penalizedNodes = [];
        /** @var list<string> $penalizedOrder */
        $penalizedOrder = [];
        $changed = false;

        foreach ($this->order as $currency) {
            $node = $this->nodes[$currency];
            $penalizedNode = $node->withOrderPenalties($usageCounts, $penaltyFactor);

            if ($penalizedNode !== $node) {
                $changed = true;
            }

            $penalizedNodes[$currency] = $penalizedNode;
            $penalizedOrder[] = $currency;
        }

        if (!$changed) {
            return $this;
        }

        return new self($penalizedNodes, $penalizedOrder);
    }
}
