<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use IteratorAggregate;
use SomeWork\P2PPathFinder\Domain\Money\ExchangeRate;
use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use Traversable;

/**
 * Immutable representation of a directed edge in the trading graph.
 *
 * @internal
 *
 * @implements IteratorAggregate<int, EdgeSegment>
 */
final class GraphEdge implements IteratorAggregate
{
    private readonly EdgeSegmentCollection $segments;

    /**
     * @param list<EdgeSegment> $segments
     */
    public function __construct(
        private readonly string $from,
        private readonly string $to,
        private readonly OrderSide $orderSide,
        private readonly Order $order,
        private readonly ExchangeRate $rate,
        private readonly EdgeCapacity $baseCapacity,
        private readonly EdgeCapacity $quoteCapacity,
        private readonly EdgeCapacity $grossBaseCapacity,
        array $segments = [],
    ) {
        $this->segments = EdgeSegmentCollection::fromArray($segments);
    }

    public function from(): string
    {
        return $this->from;
    }

    public function to(): string
    {
        return $this->to;
    }

    public function orderSide(): OrderSide
    {
        return $this->orderSide;
    }

    public function order(): Order
    {
        return $this->order;
    }

    public function rate(): ExchangeRate
    {
        return $this->rate;
    }

    public function baseCapacity(): EdgeCapacity
    {
        return $this->baseCapacity;
    }

    public function quoteCapacity(): EdgeCapacity
    {
        return $this->quoteCapacity;
    }

    public function grossBaseCapacity(): EdgeCapacity
    {
        return $this->grossBaseCapacity;
    }

    /**
     * @return list<EdgeSegment>
     */
    public function segments(): array
    {
        return $this->segments->toArray();
    }

    public function segmentCollection(): EdgeSegmentCollection
    {
        return $this->segments;
    }

    public function getIterator(): Traversable
    {
        return $this->segments->getIterator();
    }

    /**
     * Returns a new GraphEdge with capacity penalties applied.
     *
     * The penalty reduces the edge's maximum capacities (making the edge less
     * attractive for large amounts) while keeping minimums unchanged.
     *
     * Formula: penalizedMax = originalMax / (1 + usageCount * penaltyFactor)
     *
     * This encourages the search algorithm to explore alternative orders while
     * still allowing reuse when necessary.
     *
     * @param int    $usageCount    Number of times this order has been used
     * @param string $penaltyFactor Penalty multiplier per usage (e.g., "0.15" = 15%)
     *
     * @return self New edge with penalized capacities
     */
    public function withCapacityPenalty(int $usageCount, string $penaltyFactor): self
    {
        if ($usageCount <= 0) {
            return $this;
        }

        $penalty = BigDecimal::of($penaltyFactor)->multipliedBy($usageCount);
        $divisor = BigDecimal::one()->plus($penalty);

        // Cap penalty to prevent extreme reduction (max 10x reduction)
        $maxDivisor = BigDecimal::of('10');
        if ($divisor->isGreaterThan($maxDivisor)) {
            $divisor = $maxDivisor;
        }

        // Apply penalty to max capacities
        $penalizedBaseCapacity = $this->applyPenaltyToCapacity($this->baseCapacity, $divisor);
        $penalizedQuoteCapacity = $this->applyPenaltyToCapacity($this->quoteCapacity, $divisor);
        $penalizedGrossBaseCapacity = $this->applyPenaltyToCapacity($this->grossBaseCapacity, $divisor);

        return new self(
            $this->from,
            $this->to,
            $this->orderSide,
            $this->order,
            $this->rate,
            $penalizedBaseCapacity,
            $penalizedQuoteCapacity,
            $penalizedGrossBaseCapacity,
            $this->segments->toArray(),
        );
    }

    /**
     * Applies penalty divisor to a capacity, reducing max while preserving min.
     */
    private function applyPenaltyToCapacity(EdgeCapacity $capacity, BigDecimal $divisor): EdgeCapacity
    {
        $originalMax = $capacity->max();
        $penalizedMaxDecimal = $originalMax->decimal()->dividedBy($divisor, $originalMax->scale(), RoundingMode::HalfUp);

        // Ensure penalized max is not below min
        $minDecimal = $capacity->min()->decimal();
        if ($penalizedMaxDecimal->isLessThan($minDecimal)) {
            $penalizedMaxDecimal = $minDecimal;
        }

        $penalizedMax = Money::fromString(
            $originalMax->currency(),
            $penalizedMaxDecimal->toScale($originalMax->scale(), RoundingMode::HalfUp)->__toString(),
            $originalMax->scale()
        );

        return new EdgeCapacity($capacity->min(), $penalizedMax);
    }
}
