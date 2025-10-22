<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Graph;

use ArrayAccess;
use ArrayIterator;
use IteratorAggregate;
use JsonSerializable;
use LogicException;
use SomeWork\P2PPathFinder\Application\Support\SerializesMoney;
use SomeWork\P2PPathFinder\Domain\Order\Order;
use SomeWork\P2PPathFinder\Domain\Order\OrderSide;
use SomeWork\P2PPathFinder\Domain\ValueObject\ExchangeRate;
use Traversable;

use function in_array;

/**
 * Immutable representation of a directed edge in the trading graph.
 *
 * @implements IteratorAggregate<int, EdgeSegment>
 * @implements ArrayAccess<string, mixed>
 */
final class GraphEdge implements IteratorAggregate, JsonSerializable, ArrayAccess
{
    use SerializesMoney;

    /**
     * @var list<EdgeSegment>
     */
    private readonly array $segments;

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
        $normalized = [];
        foreach ($segments as $segment) {
            if (!$segment instanceof EdgeSegment) {
                continue;
            }

            $normalized[] = $segment;
        }

        $this->segments = $normalized;
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
        return $this->segments;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->segments);
    }

    public function offsetExists(mixed $offset): bool
    {
        return in_array($offset, [
            'from',
            'to',
            'orderSide',
            'order',
            'rate',
            'baseCapacity',
            'quoteCapacity',
            'grossBaseCapacity',
            'segments',
        ], true);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return match ($offset) {
            'from' => $this->from,
            'to' => $this->to,
            'orderSide' => $this->orderSide,
            'order' => $this->order,
            'rate' => $this->rate,
            'baseCapacity' => [
                'min' => $this->baseCapacity->min(),
                'max' => $this->baseCapacity->max(),
            ],
            'quoteCapacity' => [
                'min' => $this->quoteCapacity->min(),
                'max' => $this->quoteCapacity->max(),
            ],
            'grossBaseCapacity' => [
                'min' => $this->grossBaseCapacity->min(),
                'max' => $this->grossBaseCapacity->max(),
            ],
            'segments' => array_map(
                static fn (EdgeSegment $segment): array => [
                    'isMandatory' => $segment->isMandatory(),
                    'base' => [
                        'min' => $segment->base()->min(),
                        'max' => $segment->base()->max(),
                    ],
                    'quote' => [
                        'min' => $segment->quote()->min(),
                        'max' => $segment->quote()->max(),
                    ],
                    'grossBase' => [
                        'min' => $segment->grossBase()->min(),
                        'max' => $segment->grossBase()->max(),
                    ],
                ],
                $this->segments,
            ),
            default => null,
        };
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Graph edges are immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Graph edges are immutable.');
    }

    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     orderSide: string,
     *     order: array{
     *         side: string,
     *         assetPair: array{base: string, quote: string},
     *         bounds: array{min: array{currency: string, amount: string, scale: int}, max: array{currency: string, amount: string, scale: int}},
     *         effectiveRate: array{baseCurrency: string, quoteCurrency: string, value: string, scale: int},
     *     },
     *     rate: array{baseCurrency: string, quoteCurrency: string, value: string, scale: int},
     *     baseCapacity: array{min: array{currency: string, amount: string, scale: int}, max: array{currency: string, amount: string, scale: int}},
     *     quoteCapacity: array{min: array{currency: string, amount: string, scale: int}, max: array{currency: string, amount: string, scale: int}},
     *     grossBaseCapacity: array{min: array{currency: string, amount: string, scale: int}, max: array{currency: string, amount: string, scale: int}},
     *     segments: list<array{
     *         isMandatory: bool,
     *         base: array{min: array{currency: string, amount: string, scale: int}, max: array{currency: string, amount: string, scale: int}},
     *         quote: array{min: array{currency: string, amount: string, scale: int}, max: array{currency: string, amount: string, scale: int}},
     *         grossBase: array{min: array{currency: string, amount: string, scale: int}, max: array{currency: string, amount: string, scale: int}},
     *     }>,
     * }
     */
    public function jsonSerialize(): array
    {
        $segments = [];
        foreach ($this->segments as $segment) {
            $segments[] = $segment->jsonSerialize();
        }

        return [
            'from' => $this->from,
            'to' => $this->to,
            'orderSide' => $this->orderSide->value,
            'order' => [
                'side' => $this->order->side()->value,
                'assetPair' => [
                    'base' => $this->order->assetPair()->base(),
                    'quote' => $this->order->assetPair()->quote(),
                ],
                'bounds' => [
                    'min' => self::serializeMoney($this->order->bounds()->min()),
                    'max' => self::serializeMoney($this->order->bounds()->max()),
                ],
                'effectiveRate' => [
                    'baseCurrency' => $this->order->effectiveRate()->baseCurrency(),
                    'quoteCurrency' => $this->order->effectiveRate()->quoteCurrency(),
                    'value' => $this->order->effectiveRate()->rate(),
                    'scale' => $this->order->effectiveRate()->scale(),
                ],
            ],
            'rate' => [
                'baseCurrency' => $this->rate->baseCurrency(),
                'quoteCurrency' => $this->rate->quoteCurrency(),
                'value' => $this->rate->rate(),
                'scale' => $this->rate->scale(),
            ],
            'baseCapacity' => $this->baseCapacity->jsonSerialize(),
            'quoteCapacity' => $this->quoteCapacity->jsonSerialize(),
            'grossBaseCapacity' => $this->grossBaseCapacity->jsonSerialize(),
            'segments' => $segments,
        ];
    }
}
