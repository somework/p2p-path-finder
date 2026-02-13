<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use IteratorAggregate;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use Traversable;

use function sprintf;

/**
 * Directed multigraph representation keyed by asset symbol.
 *
 * @internal
 *
 * @implements IteratorAggregate<string, GraphNode>
 */
final class Graph implements IteratorAggregate
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
     * Returns a new Graph with specified orders excluded.
     *
     * Creates a filtered copy of this graph where edges referencing any of the
     * excluded orders are removed. Nodes are preserved even if they lose all
     * edges, as they may still be needed as source/target currencies.
     *
     * This method is used for Top-K path finding, where each subsequent search
     * excludes orders already used in previously found plans.
     *
     * @param array<int, true> $excludedOrderIds Order object IDs to exclude (spl_object_id keys)
     *
     * @return self New graph without the excluded orders
     */
    public function withoutOrders(array $excludedOrderIds): self
    {
        if ([] === $excludedOrderIds) {
            return $this;
        }

        $filteredNodes = $this->nodes->withoutOrders($excludedOrderIds);

        if ($filteredNodes === $this->nodes) {
            return $this;
        }

        return new self($filteredNodes);
    }

    /**
     * Returns a new Graph with capacity penalties applied to specified orders.
     *
     * Creates a modified copy of this graph where edges referencing penalized
     * orders have their capacity reduced proportionally. This encourages the
     * search algorithm to explore alternative orders while still allowing reuse.
     *
     * The penalty reduces the edge's effective capacity:
     * - penalizedCapacity = baseCapacity / (1 + usageCount * penaltyFactor)
     *
     * This method is used for reusable Top-K path finding, where order reuse
     * is allowed but discouraged through soft penalties.
     *
     * @param array<int, int> $usageCounts   Order object ID => usage count
     * @param string          $penaltyFactor Penalty multiplier per usage (e.g., "0.15" = 15%)
     *
     * @return self New graph with penalized edge capacities
     */
    public function withOrderPenalties(array $usageCounts, string $penaltyFactor): self
    {
        try {
            $penalty = BigDecimal::of($penaltyFactor);
        } catch (MathException $exception) {
            throw new InvalidInput(sprintf('Penalty factor "%s" is not a valid numeric value.', $penaltyFactor), 0, $exception);
        }

        if (!$penalty->isPositive()) {
            throw new InvalidInput(sprintf('Penalty factor must be positive. Got: "%s".', $penaltyFactor));
        }

        if ([] === $usageCounts) {
            return $this;
        }

        $penalizedNodes = $this->nodes->withOrderPenalties($usageCounts, $penaltyFactor);

        if ($penalizedNodes === $this->nodes) {
            return $this;
        }

        return new self($penalizedNodes);
    }
}
