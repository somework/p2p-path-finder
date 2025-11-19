<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\ValueObject;

use Brick\Math\BigDecimal;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

/**
 * Represents a candidate path discovered by the search algorithm.
 *
 * @internal
 */
final class CandidatePath
{
    private function __construct(
        private readonly BigDecimal $cost,
        private readonly BigDecimal $product,
        private readonly int $hops,
        private readonly PathEdgeSequence $edges,
        private readonly ?SpendConstraints $range,
    ) {
    }

    /**
     * @throws InvalidInput when the hop count does not match the number of edges
     */
    public static function from(
        BigDecimal $cost,
        BigDecimal $product,
        int $hops,
        PathEdgeSequence $edges,
        ?SpendConstraints $range = null
    ): self {
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
        /** @var numeric-string $value */
        $value = $this->cost->__toString();

        return $value;
    }

    public function costDecimal(): BigDecimal
    {
        return $this->cost;
    }

    /**
     * @return numeric-string
     */
    public function product(): string
    {
        /** @var numeric-string $value */
        $value = $this->product->__toString();

        return $value;
    }

    public function productDecimal(): BigDecimal
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
            'cost' => $this->cost(),
            'product' => $this->product(),
            'hops' => $this->hops,
            'edges' => $this->edges->toArray(),
            'amountRange' => $this->range?->bounds(),
            'desiredAmount' => $this->range?->desired(),
        ];
    }
}
