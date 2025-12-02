<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Result;

use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Money\MoneyMap;
use SomeWork\P2PPathFinder\Domain\Tolerance\DecimalTolerance;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

/**
 * Aggregated representation of a discovered conversion path.
 *
 * @see PathLeg For individual hop details
 * @see PathLegCollection For the legs collection
 * @see MoneyMap For fee breakdown aggregation
 * @see docs/api-contracts.md#pathresult For JSON structure specification
 *
 * @api
 */
final class PathResult
{
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
     * @throws InvalidInput when the tolerance percentage cannot be calculated at the requested scale
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
}
