<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Result;

use JsonSerializable;
use SomeWork\P2PPathFinder\Application\Support\SerializesMoney;
use SomeWork\P2PPathFinder\Domain\ValueObject\DecimalTolerance;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

/**
 * Aggregated representation of a discovered conversion path.
 */
final class PathResult implements JsonSerializable
{
    use SerializesMoney;

    private readonly MoneyMap $feeBreakdown;

    private readonly PathLegCollection $legs;

    /**
     * @throws InvalidInput|PrecisionViolation when fee entries are invalid or cannot be merged deterministically
     */
    public function __construct(
        private readonly Money $totalSpent,
        private readonly Money $totalReceived,
        private readonly DecimalTolerance $residualTolerance,
        ?PathLegCollection $legs = null,
        ?MoneyMap $feeBreakdown = null,
    ) {
        $this->legs = $legs ?? PathLegCollection::empty();
        $this->feeBreakdown = $feeBreakdown ?? MoneyMap::empty();
    }

    /**
     * Returns the total amount of source asset spent across the entire path.
     */
    public function totalSpent(): Money
    {
        return $this->totalSpent;
    }

    /**
     * Returns the total amount of destination asset received across the path.
     */
    public function totalReceived(): Money
    {
        return $this->totalReceived;
    }

    public function feeBreakdown(): MoneyMap
    {
        return $this->feeBreakdown;
    }

    /**
     * @return array<string, Money>
     */
    public function feeBreakdownAsArray(): array
    {
        return $this->feeBreakdown->toArray();
    }

    /**
     * Returns the remaining tolerance after accounting for the chosen path.
     */
    public function residualTolerance(): DecimalTolerance
    {
        return $this->residualTolerance;
    }

    /**
     * @throws InvalidInput|PrecisionViolation when the tolerance percentage cannot be calculated at the requested scale
     */
    public function residualTolerancePercentage(int $scale = 2): string
    {
        return $this->residualTolerance->percentage($scale);
    }

    public function legs(): PathLegCollection
    {
        return $this->legs;
    }

    /**
     * @return list<PathLeg>
     */
    public function legsAsArray(): array
    {
        return $this->legs->all();
    }

    /**
     * @return array{
     *     totalSpent: Money,
     *     totalReceived: Money,
     *     residualTolerance: DecimalTolerance,
     *     feeBreakdown: MoneyMap,
     *     legs: PathLegCollection,
     * }
     */
    public function toArray(): array
    {
        return [
            'totalSpent' => $this->totalSpent,
            'totalReceived' => $this->totalReceived,
            'residualTolerance' => $this->residualTolerance,
            'feeBreakdown' => $this->feeBreakdown,
            'legs' => $this->legs,
        ];
    }

    /**
     * @return array{
     *     totalSpent: array{currency: string, amount: numeric-string, scale: int},
     *     totalReceived: array{currency: string, amount: numeric-string, scale: int},
     *     residualTolerance: numeric-string,
     *     feeBreakdown: array<string, array{currency: string, amount: numeric-string, scale: int}>,
     *     legs: list<array{
     *         from: string,
     *         to: string,
     *         spent: array{currency: string, amount: numeric-string, scale: int},
     *         received: array{currency: string, amount: numeric-string, scale: int},
     *         fees: array<string, array{currency: string, amount: numeric-string, scale: int}>,
     *     }>,
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'totalSpent' => self::serializeMoney($this->totalSpent),
            'totalReceived' => self::serializeMoney($this->totalReceived),
            'residualTolerance' => $this->residualTolerance->ratio(),
            'feeBreakdown' => $this->feeBreakdown->jsonSerialize(),
            'legs' => $this->legs->jsonSerialize(),
        ];
    }
}
