<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Result;

use SomeWork\P2PPathFinder\Domain\Money\Money;
use SomeWork\P2PPathFinder\Domain\Money\MoneyMap;
use SomeWork\P2PPathFinder\Domain\Tolerance\DecimalTolerance;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

/**
 * Aggregated representation of a discovered conversion path derived from hops.
 *
 * @api
 */
final class Path
{
    private readonly Money $totalSpent;

    private readonly Money $totalReceived;

    private readonly MoneyMap $feeBreakdown;

    public function __construct(
        private readonly PathHopCollection $hops,
        private readonly DecimalTolerance $residualTolerance,
    ) {
        if ($hops->isEmpty()) {
            throw new InvalidInput('Path must contain at least one hop.');
        }

        /** @var PathHop $firstHop */
        $firstHop = $hops->first();
        /** @var PathHop $lastHop */
        $lastHop = $hops->last();

        $this->totalSpent = $firstHop->spent();
        $this->totalReceived = $lastHop->received();
        $this->feeBreakdown = $this->aggregateFees($hops);
    }

    public function hops(): PathHopCollection
    {
        return $this->hops;
    }

    /**
     * @return list<PathHop>
     */
    public function hopsAsArray(): array
    {
        return $this->hops->all();
    }

    public function totalSpent(): Money
    {
        return $this->totalSpent;
    }

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

    /**
     * @return array{
     *     totalSpent: Money,
     *     totalReceived: Money,
     *     residualTolerance: DecimalTolerance,
     *     feeBreakdown: MoneyMap,
     *     hops: PathHopCollection,
     * }
     */
    public function toArray(): array
    {
        return [
            'totalSpent' => $this->totalSpent,
            'totalReceived' => $this->totalReceived,
            'residualTolerance' => $this->residualTolerance,
            'feeBreakdown' => $this->feeBreakdown,
            'hops' => $this->hops,
        ];
    }

    /**
     * @throws PrecisionViolation when the fee breakdown cannot be merged deterministically
     */
    private function aggregateFees(PathHopCollection $hops): MoneyMap
    {
        $aggregate = MoneyMap::empty();

        foreach ($hops as $hop) {
            $aggregate = $aggregate->merge($hop->fees());
        }

        return $aggregate;
    }
}
