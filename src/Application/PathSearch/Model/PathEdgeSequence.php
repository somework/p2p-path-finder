<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Model;

use Countable;
use IteratorAggregate;
use LogicException;
use SomeWork\P2PPathFinder\Domain\Money\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use Traversable;

use function array_key_last;
use function array_map;
use function count;
use function sprintf;

/**
 * Immutable sequence of {@see PathEdge} instances.
 *
 * @internal
 *
 * @implements IteratorAggregate<int, PathEdge>
 */
final class PathEdgeSequence implements Countable, IteratorAggregate
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
            /* @phpstan-ignore-next-line instanceof.alwaysTrue */
            if (!$edge instanceof PathEdge) {
                throw new InvalidInput('Path edge sequence elements must be PathEdge instances.');
            }

            $list[] = $edge;
        }

        self::assertValidChain($list);

        return new self($list);
    }

    /**
     * @param list<PathEdge> $edges
     */
    public static function fromList(array $edges): self
    {
        foreach ($edges as $edge) {
            /* @phpstan-ignore-next-line instanceof.alwaysTrue */
            if (!$edge instanceof PathEdge) {
                throw new InvalidInput('Path edge sequence elements must be PathEdge instances.');
            }
        }

        self::assertValidChain($edges);

        return new self($edges);
    }

    public function append(PathEdge $edge): self
    {
        $edges = $this->edges;

        self::assertEdgeAlignment($edge);

        $last = $this->last();
        if ($last instanceof PathEdge) {
            self::assertAdjacent($last, $edge);
        }

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

        if (null === $lastKey) {
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

    /**
     * @return list<PathEdge>
     */
    public function toList(): array
    {
        return $this->edges;
    }

    public function at(int $index): PathEdge
    {
        if (!isset($this->edges[$index])) {
            throw new LogicException(sprintf('Undefined path edge at offset %d.', $index));
        }

        return $this->edges[$index];
    }

    public function has(int $index): bool
    {
        return isset($this->edges[$index]);
    }

    /**
     * @param list<PathEdge> $edges
     */
    private static function assertValidChain(array $edges): void
    {
        $previous = null;

        foreach ($edges as $edge) {
            self::assertEdgeAlignment($edge);

            if ($previous instanceof PathEdge) {
                self::assertAdjacent($previous, $edge);
            }

            $previous = $edge;
        }
    }

    private static function assertEdgeAlignment(PathEdge $edge): void
    {
        $order = $edge->order();

        if ($edge->orderSide() !== $order->side()) {
            throw new InvalidInput('Path edge order side must match the underlying order.');
        }

        $pair = $order->assetPair();

        [$expectedFrom, $expectedTo] = match ($edge->orderSide()) {
            OrderSide::BUY => [$pair->base(), $pair->quote()],
            OrderSide::SELL => [$pair->quote(), $pair->base()],
        };

        if ($edge->from() !== $expectedFrom || $edge->to() !== $expectedTo) {
            throw new InvalidInput('Path edge endpoints must align with the underlying order asset pair and side.');
        }

        $rate = $edge->rate();

        if ($rate->baseCurrency() !== $pair->base() || $rate->quoteCurrency() !== $pair->quote()) {
            throw new InvalidInput('Path edge exchange rate currencies must match the order asset pair.');
        }
    }

    private static function assertAdjacent(PathEdge $previous, PathEdge $edge): void
    {
        if ($previous->to() !== $edge->from()) {
            throw new InvalidInput(sprintf('Path edge sequences must form a continuous chain. Expected "%s" to lead into "%s".', $previous->to(), $edge->from()));
        }
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
