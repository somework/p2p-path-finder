<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\ValueObject;

use Brick\Math\BigDecimal;
use SomeWork\P2PPathFinder\Application\Graph\GraphEdge;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;

/**
 * Represents a single edge within a candidate path.
 *
 * @internal
 */
final class PathEdge
{
    private function __construct(
        private readonly string $from,
        private readonly string $to,
        private readonly Order $order,
        private readonly ExchangeRate $rate,
        private readonly OrderSide $orderSide,
        private readonly BigDecimal $conversionRate,
    ) {
    }

    public static function create(
        string $from,
        string $to,
        Order $order,
        ExchangeRate $rate,
        OrderSide $orderSide,
        BigDecimal $conversionRate,
    ): self {
        return new self($from, $to, $order, $rate, $orderSide, $conversionRate);
    }

    public static function fromGraphEdge(GraphEdge $edge, BigDecimal $conversionRate): self
    {
        return self::create(
            $edge->from(),
            $edge->to(),
            $edge->order(),
            $edge->rate(),
            $edge->orderSide(),
            $conversionRate,
        );
    }

    /**
     * @return array{from: string, to: string, order: Order, rate: ExchangeRate, orderSide: OrderSide, conversionRate: numeric-string}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'order' => $this->order,
            'rate' => $this->rate,
            'orderSide' => $this->orderSide,
            'conversionRate' => $this->conversionRate(),
        ];
    }

    public function from(): string
    {
        return $this->from;
    }

    public function to(): string
    {
        return $this->to;
    }

    public function order(): Order
    {
        return $this->order;
    }

    public function rate(): ExchangeRate
    {
        return $this->rate;
    }

    public function orderSide(): OrderSide
    {
        return $this->orderSide;
    }

    /**
     * Returns the conversion rate as a numeric string.
     *
     * The scale is preserved from the BigDecimal provided at construction time,
     * typically normalized to 18 decimal places by PathFinder.
     *
     * @return numeric-string The conversion rate (e.g., "1.234567890123456789")
     */
    public function conversionRate(): string
    {
        /** @var numeric-string $value */
        $value = $this->conversionRate->__toString();

        return $value;
    }

    /**
     * Returns the conversion rate as a BigDecimal with preserved scale from creation.
     *
     * Useful for calculations that need to maintain full precision without
     * string conversion overhead.
     *
     * @return BigDecimal The conversion rate at its original scale
     */
    public function conversionRateDecimal(): BigDecimal
    {
        return $this->conversionRate;
    }
}
