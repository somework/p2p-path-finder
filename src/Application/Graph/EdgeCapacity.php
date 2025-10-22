<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Graph;

use JsonSerializable;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

/**
 * Represents the minimum and maximum capacity for a given measurement on an edge.
 */
final class EdgeCapacity implements JsonSerializable
{
    public function __construct(
        private readonly Money $min,
        private readonly Money $max,
    ) {
    }

    public function min(): Money
    {
        return $this->min;
    }

    public function max(): Money
    {
        return $this->max;
    }

    /**
     * @return array{min: array{currency: string, amount: string, scale: int}, max: array{currency: string, amount: string, scale: int}}
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'min' => self::serializeMoney($this->min),
            'max' => self::serializeMoney($this->max),
        ];
    }

    /**
     * @return array{currency: string, amount: string, scale: int}
     */
    private static function serializeMoney(Money $money): array
    {
        return [
            'currency' => $money->currency(),
            'amount' => $money->amount(),
            'scale' => $money->scale(),
        ];
    }
}
