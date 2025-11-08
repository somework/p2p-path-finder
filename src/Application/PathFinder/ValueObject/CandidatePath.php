<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\ValueObject;

use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

/**
 * Represents a candidate path discovered by the search algorithm.
 */
final class CandidatePath
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
            'amountRange' => $this->range?->range()->toBoundsArray(),
            'desiredAmount' => $this->range?->desired(),
        ];
    }
}
