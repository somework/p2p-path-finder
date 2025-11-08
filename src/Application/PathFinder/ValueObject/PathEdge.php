<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\ValueObject;

use SomeWork\P2PPathFinder\Application\Graph\GraphEdge;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;

final class PathEdge
{
    private function __construct(
        private readonly string $from,
        private readonly string $to,
        private readonly Order $order,
        private readonly ExchangeRate $rate,
        private readonly OrderSide $orderSide,
        /** @var numeric-string */
        private readonly string $conversionRate,
    ) {
    }

    public static function create(
        string $from,
        string $to,
        Order $order,
        ExchangeRate $rate,
        OrderSide $orderSide,
        string $conversionRate,
    ): self {
        BcMath::ensureNumeric($conversionRate);

        /** @var numeric-string $conversionRate */
        $conversionRate = $conversionRate;

        return new self($from, $to, $order, $rate, $orderSide, $conversionRate);
    }

    public static function fromGraphEdge(GraphEdge $edge, string $conversionRate): self
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
            'conversionRate' => $this->conversionRate,
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
     * @return numeric-string
     */
    public function conversionRate(): string
    {
        return $this->conversionRate;
    }
}
