<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\ValueObject;

use ArrayAccess;
use LogicException;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

use function in_array;
use function sprintf;

/**
 * Represents a candidate path discovered by the search algorithm.
 */
/**
 * @implements ArrayAccess<string, mixed>
 */
final class CandidatePath implements ArrayAccess
{
    /**
     * @param numeric-string $cost    aggregate spend for the candidate path
     * @param numeric-string $product cumulative product of the edge exchange rates
     */
    private function __construct(
        private readonly string $cost,
        private readonly string $product,
        private readonly int $hops,
        private readonly PathEdgeSequence $edges,
        private readonly ?SpendConstraints $range,
    ) {
    }

    /**
     * @param numeric-string $cost    aggregate spend for the candidate path
     * @param numeric-string $product cumulative product of the edge exchange rates
     *
     * @throws InvalidInput when the hop count does not match the number of edges
     */
    public static function from(
        string $cost,
        string $product,
        int $hops,
        PathEdgeSequence $edges,
        ?SpendConstraints $range = null
    ): self {
        BcMath::ensureNumeric($cost, $product);

        if ($hops < 0) {
            throw new InvalidInput('Hop count cannot be negative.');
        }

        if ($hops !== $edges->count()) {
            throw new InvalidInput('Hop count must match the number of edges in the candidate path.');
        }

        return new self($cost, $product, $hops, $edges, $range);
    }

    /**
     * @return numeric-string
     */
    public function cost(): string
    {
        return $this->cost;
    }

    /**
     * @return numeric-string
     */
    public function product(): string
    {
        return $this->product;
    }

    public function hops(): int
    {
        return $this->hops;
    }

    public function edges(): PathEdgeSequence
    {
        return $this->edges;
    }

    public function range(): ?SpendConstraints
    {
        return $this->range;
    }

    public function offsetExists(mixed $offset): bool
    {
        if (in_array($offset, ['amountRange', 'desiredAmount'], true)) {
            return null !== $this->range;
        }

        return in_array($offset, ['cost', 'product', 'hops', 'edges'], true);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return match ($offset) {
            'cost' => $this->cost,
            'product' => $this->product,
            'hops' => $this->hops,
            'edges' => $this->edges->toArray(),
            'amountRange' => $this->range?->toRange(),
            'desiredAmount' => $this->range?->desired(),
            default => throw new LogicException(sprintf('Unknown candidate path attribute "%s".', $offset)),
        };
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Candidate paths are immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Candidate paths are immutable.');
    }

    /**
     * @return array{
     *     cost: numeric-string,
     *     product: numeric-string,
     *     hops: int,
     *     edges: list<array{from: string, to: string, order: Order, rate: ExchangeRate, orderSide: OrderSide, conversionRate: numeric-string}>,
     *     amountRange: array{min: Money, max: Money}|null,
     *     desiredAmount: Money|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'cost' => $this->cost,
            'product' => $this->product,
            'hops' => $this->hops,
            'edges' => $this->edges->toArray(),
            'amountRange' => $this->range?->toRange(),
            'desiredAmount' => $this->range?->desired(),
        ];
    }
}
