<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph;

use ArrayIterator;
use Closure;
use Countable;
use IteratorAggregate;
use LogicException;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use Traversable;

use function array_is_list;
use function count;
use function implode;

/**
 * Immutable ordered collection of {@see GraphEdge} instances for a single origin currency.
 *
 * @internal
 *
 * @implements IteratorAggregate<int, GraphEdge>
 */
final class GraphEdgeCollection implements Countable, IteratorAggregate
{
    /**
     * @var list<GraphEdge>
     */
    private array $edges;

    private readonly ?string $originCurrency;

    /**
     * @var Closure(GraphEdge, GraphEdge): int
     */
    private readonly Closure $comparator;

    /**
     * @param list<GraphEdge>                    $edges
     * @param Closure(GraphEdge, GraphEdge): int $comparator
     */
    private function __construct(array $edges, ?string $originCurrency, Closure $comparator)
    {
        $this->edges = $edges;
        $this->originCurrency = $originCurrency;
        $this->comparator = $comparator;
    }

    public static function empty(): self
    {
        return new self([], null, self::canonicalComparator());
    }

    /**
     * @param array<array-key, GraphEdge>                                                     $edges
     * @param (Closure(GraphEdge, GraphEdge): int)|(callable(GraphEdge, GraphEdge): int)|null $comparator
     */
    public static function fromArray(array $edges, ?callable $comparator = null): self
    {
        if ([] === $edges) {
            return new self([], null, self::canonicalComparator());
        }

        if (!array_is_list($edges)) {
            throw new InvalidInput('Graph edges must be provided as a list.');
        }

        /** @var list<GraphEdge> $normalized */
        $normalized = [];
        $originCurrency = null;

        if ($comparator instanceof Closure) {
            $resolvedComparator = $comparator;
        } elseif (null !== $comparator) {
            $resolvedComparator = Closure::fromCallable($comparator);
        } else {
            $resolvedComparator = self::canonicalComparator();
        }

        $comparator = $resolvedComparator;

        foreach ($edges as $edge) {
            /* @phpstan-ignore-next-line instanceof.alwaysTrue */
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

        usort($normalized, $comparator);

        return new self($normalized, $originCurrency, $comparator);
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
     * @return Closure(GraphEdge, GraphEdge): int
     */
    public function comparator(): Closure
    {
        return $this->comparator;
    }

    /**
     * @return Traversable<int, GraphEdge>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->edges);
    }

    public function at(int $index): GraphEdge
    {
        if (!isset($this->edges[$index])) {
            throw new InvalidInput('Graph edge index must reference an existing position.');
        }

        return $this->edges[$index];
    }

    public function first(): ?GraphEdge
    {
        return $this->edges[0] ?? null;
    }

    /**
     * @return list<GraphEdge>
     */
    public function toArray(): array
    {
        return $this->edges;
    }

    /**
     * Returns a new collection excluding edges whose orders are in the exclusion set.
     *
     * @param array<int, true> $excludedOrderIds Order object IDs to exclude (spl_object_id)
     *
     * @return self New collection without the excluded edges
     */
    public function withoutOrders(array $excludedOrderIds): self
    {
        if ([] === $excludedOrderIds || [] === $this->edges) {
            return $this;
        }

        $filtered = [];
        foreach ($this->edges as $edge) {
            $orderId = spl_object_id($edge->order());
            if (!isset($excludedOrderIds[$orderId])) {
                $filtered[] = $edge;
            }
        }

        if ([] === $filtered) {
            return self::empty();
        }

        if (count($filtered) === count($this->edges)) {
            return $this;
        }

        return new self($filtered, $this->originCurrency, $this->comparator);
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
        if ([] === $usageCounts || [] === $this->edges) {
            return $this;
        }

        $penalized = [];
        $changed = false;

        foreach ($this->edges as $edge) {
            $orderId = spl_object_id($edge->order());
            $usageCount = $usageCounts[$orderId] ?? 0;

            if ($usageCount > 0) {
                $penalized[] = $edge->withCapacityPenalty($usageCount, $penaltyFactor);
                $changed = true;
            } else {
                $penalized[] = $edge;
            }
        }

        if (!$changed) {
            return $this;
        }

        return new self($penalized, $this->originCurrency, $this->comparator);
    }

    /**
     * Canonical comparator ordering edges by quote currency, serialized order payload, then side.
     *
     * @return Closure(GraphEdge, GraphEdge): int
     */
    public static function canonicalComparator(): Closure
    {
        return static function (GraphEdge $left, GraphEdge $right): int {
            $quoteComparison = $left->order()->assetPair()->quote() <=> $right->order()->assetPair()->quote();
            if (0 !== $quoteComparison) {
                return $quoteComparison;
            }

            $orderComparison = self::orderFingerprint($left) <=> self::orderFingerprint($right);
            if (0 !== $orderComparison) {
                return $orderComparison;
            }

            return $left->orderSide()->value <=> $right->orderSide()->value;
        };
    }

    private static function orderFingerprint(GraphEdge $edge): string
    {
        $order = $edge->order();
        $bounds = $order->bounds();
        $min = $bounds->min();
        $max = $bounds->max();
        $rate = $order->effectiveRate();
        $feePolicy = $order->feePolicy();

        $feeKey = 'none';
        if (null !== $feePolicy) {
            $fingerprint = $feePolicy->fingerprint();
            /** @var string $fingerprint */
            if ('' === $fingerprint) {
                throw new LogicException('Fee policy fingerprint must not be empty.');
            }

            $feeKey = implode('|', [$feePolicy::class, $fingerprint]);
        }

        return implode('|', [
            $order->side()->value,
            $order->assetPair()->base(),
            $order->assetPair()->quote(),
            $min->currency(),
            $min->amount(),
            $min->scale(),
            $max->currency(),
            $max->amount(),
            $max->scale(),
            $rate->baseCurrency(),
            $rate->quoteCurrency(),
            $rate->rate(),
            $rate->scale(),
            $feeKey,
        ]);
    }
}
