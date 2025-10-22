<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Graph;

use JsonSerializable;
use SomeWork\P2PPathFinder\Application\Support\SerializesMoney;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;

/**
 * Represents the minimum and maximum capacity for a given measurement on an edge.
 */
final class EdgeCapacity implements JsonSerializable
{
    use SerializesMoney;

    public function __construct(
        private readonly Money $min,
        private readonly Money $max,
    ) {
        if ($min->currency() !== $max->currency()) {
            throw new InvalidInput('Edge capacity bounds must share the same currency.');
        }

        if ($min->greaterThan($max)) {
            throw new InvalidInput('Edge capacity minimum cannot exceed maximum.');
        }
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
    public function jsonSerialize(): array
    {
        return [
            'min' => self::serializeMoney($this->min),
            'max' => self::serializeMoney($this->max),
        ];
    }
}
