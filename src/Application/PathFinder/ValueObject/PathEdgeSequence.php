<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\ValueObject;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use LogicException;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use Traversable;

use function array_key_last;
use function array_map;
use function count;
use function sprintf;

/**
 * Immutable sequence of {@see PathEdge} instances.
 *
 * @implements ArrayAccess<int, PathEdge>
 * @implements IteratorAggregate<int, PathEdge>
 */
final class PathEdgeSequence implements ArrayAccess, Countable, IteratorAggregate
{
    /**
     * @param list<PathEdge> $edges
     */
    private function __construct(
        /** @var list<PathEdge> */
        private readonly array $edges,
    ) {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param iterable<PathEdge> $edges
     */
    public static function fromIterable(iterable $edges): self
    {
        $list = [];

        foreach ($edges as $edge) {
            if (!$edge instanceof PathEdge) {
                throw new InvalidInput('Path edge sequence elements must be PathEdge instances.');
            }

            $list[] = $edge;
        }

        return new self($list);
    }

    /**
     * @param list<PathEdge> $edges
     */
    public static function fromList(array $edges): self
    {
        foreach ($edges as $edge) {
            if (!$edge instanceof PathEdge) {
                throw new InvalidInput('Path edge sequence elements must be PathEdge instances.');
            }
        }

        return new self($edges);
    }

    public function append(PathEdge $edge): self
    {
        $edges = $this->edges;
        $edges[] = $edge;

        return new self($edges);
    }

    public function isEmpty(): bool
    {
        return [] === $this->edges;
    }

    public function first(): ?PathEdge
    {
        return $this->edges[0] ?? null;
    }

    public function last(): ?PathEdge
    {
        $lastKey = array_key_last($this->edges);

        if ($lastKey === null) {
            return null;
        }

        return $this->edges[$lastKey];
    }

    /**
     * @return Traversable<int, PathEdge>
     */
    public function getIterator(): Traversable
    {
        foreach ($this->edges as $edge) {
            yield $edge;
        }
    }

    public function count(): int
    {
        return count($this->edges);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->edges[$offset]);
    }

    public function offsetGet(mixed $offset): PathEdge
    {
        if (!isset($this->edges[$offset])) {
            throw new LogicException(sprintf('Undefined path edge at offset %s.', $offset));
        }

        return $this->edges[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Path edge sequences are immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Path edge sequences are immutable.');
    }

    /**
     * @return list<PathEdge>
     */
    public function toList(): array
    {
        return $this->edges;
    }

    /**
     * @return list<array{from: string, to: string, order: Order, rate: ExchangeRate, orderSide: OrderSide, conversionRate: numeric-string}>
     */
    public function toArray(): array
    {
        /** @var list<array{from: string, to: string, order: Order, rate: ExchangeRate, orderSide: OrderSide, conversionRate: numeric-string}> $edges */
        $edges = array_map(
            static fn (PathEdge $edge): array => $edge->toArray(),
            $this->edges,
        );

        return $edges;
    }
}
