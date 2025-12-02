<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Model\Graph;

use IteratorAggregate;
use SomeWork\P2PPathFinder\Domain\Money\ExchangeRate;
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
}
