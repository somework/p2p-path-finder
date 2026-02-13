<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph;

use IteratorAggregate;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use Traversable;

/**
 * Represents a currency node and its outgoing edges within the trading graph.
 *
 * @internal
 *
 * @implements IteratorAggregate<int, GraphEdge>
 */
final class GraphNode implements IteratorAggregate
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
     * Returns a new node excluding edges whose orders are in the exclusion set.
     *
     * @param array<int, true> $excludedOrderIds Order object IDs to exclude (spl_object_id)
     *
     * @return self New node without the excluded edges
     */
    public function withoutOrders(array $excludedOrderIds): self
    {
        $filteredEdges = $this->edges->withoutOrders($excludedOrderIds);

        if ($filteredEdges === $this->edges) {
            return $this;
        }

        return new self($this->currency, $filteredEdges);
    }

    /**
     * Returns a new node with capacity penalties applied to specified orders.
     *
     * @param array<int, int> $usageCounts   Order object ID => usage count
     * @param string          $penaltyFactor Penalty multiplier per usage
     *
     * @return self New node with penalized edges
     */
    public function withOrderPenalties(array $usageCounts, string $penaltyFactor): self
    {
        $penalizedEdges = $this->edges->withOrderPenalties($usageCounts, $penaltyFactor);

        if ($penalizedEdges === $this->edges) {
            return $this;
        }

        return new self($this->currency, $penalizedEdges);
    }
}
