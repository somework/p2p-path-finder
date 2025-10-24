<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Graph;

use ArrayAccess;
use ArrayIterator;
use Closure;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use SomeWork\P2PPathFinder\Application\Support\GuardsArrayAccessOffset;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use Traversable;

use function array_is_list;
use function count;
use function implode;
use function spl_object_hash;
use function str_contains;

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

        $comparator = $comparator instanceof Closure
            ? $comparator
            : (null !== $comparator ? Closure::fromCallable($comparator) : self::canonicalComparator());

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
            $class = $feePolicy::class;
            $feeKey = $class;

            if (str_contains($class, '@anonymous')) {
                $feeKey .= '#'.spl_object_hash($feePolicy);
            }
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
