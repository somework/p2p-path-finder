<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Config;

use InvalidArgumentException;
use SomeWork\P2PPathFinder\Application\PathFinder\PathFinder;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

use function sprintf;

/**
 * Immutable configuration carrying constraints used by {@see PathFinderService}.
 */
final class PathSearchConfig
{
    private readonly Money $minimumSpendAmount;

    private readonly Money $maximumSpendAmount;

    public function __construct(
        private readonly Money $spendAmount,
        private readonly float $minimumTolerance,
        private readonly float $maximumTolerance,
        private readonly int $minimumHops,
        private readonly int $maximumHops,
        private readonly int $resultLimit = 1,
        private readonly int $pathFinderMaxExpansions = PathFinder::DEFAULT_MAX_EXPANSIONS,
        private readonly int $pathFinderMaxVisitedStates = PathFinder::DEFAULT_MAX_VISITED_STATES,
    ) {
        if ($minimumTolerance < 0.0 || $minimumTolerance >= 1.0) {
            throw new InvalidArgumentException('Minimum tolerance must be in the [0, 1) range.');
        }

        if ($maximumTolerance < 0.0 || $maximumTolerance >= 1.0) {
            throw new InvalidArgumentException('Maximum tolerance must be in the [0, 1) range.');
        }

        if ($minimumHops < 1) {
            throw new InvalidArgumentException('Minimum hops must be at least one.');
        }

        if ($maximumHops < $minimumHops) {
            throw new InvalidArgumentException('Maximum hops must be greater than or equal to minimum hops.');
        }

        if ($resultLimit < 1) {
            throw new InvalidArgumentException('Result limit must be at least one.');
        }

        if ($pathFinderMaxExpansions < 1) {
            throw new InvalidArgumentException('Maximum expansions must be at least one.');
        }

        if ($pathFinderMaxVisitedStates < 1) {
            throw new InvalidArgumentException('Maximum visited states must be at least one.');
        }

        $this->minimumSpendAmount = $this->calculateBoundedSpend(1.0 - $minimumTolerance);
        $this->maximumSpendAmount = $this->calculateBoundedSpend(1.0 + $maximumTolerance);
    }

    /**
     * Returns a fluent builder for constructing configuration instances.
     */
    public static function builder(): PathSearchConfigBuilder
    {
        return new PathSearchConfigBuilder();
    }

    /**
     * Returns the target spend amount expressed in the source asset.
     */
    public function spendAmount(): Money
    {
        return $this->spendAmount;
    }

    /**
     * Returns the lower relative tolerance bound expressed as a fraction.
     */
    public function minimumTolerance(): float
    {
        return $this->minimumTolerance;
    }

    /**
     * Returns the upper relative tolerance bound expressed as a fraction.
     */
    public function maximumTolerance(): float
    {
        return $this->maximumTolerance;
    }

    /**
     * Returns the minimum number of hops allowed in a resulting path.
     */
    public function minimumHops(): int
    {
        return $this->minimumHops;
    }

    /**
     * Returns the maximum number of hops allowed in a resulting path.
     */
    public function maximumHops(): int
    {
        return $this->maximumHops;
    }

    /**
     * Returns the maximum number of paths that should be returned by the search.
     */
    public function resultLimit(): int
    {
        return $this->resultLimit;
    }

    /**
     * Returns the maximum number of state expansions the path finder is allowed to perform.
     */
    public function pathFinderMaxExpansions(): int
    {
        return $this->pathFinderMaxExpansions;
    }

    /**
     * Returns the maximum number of unique state signatures tracked during search.
     */
    public function pathFinderMaxVisitedStates(): int
    {
        return $this->pathFinderMaxVisitedStates;
    }

    /**
     * Returns the minimum amount that can be spent after tolerance adjustments.
     */
    public function minimumSpendAmount(): Money
    {
        return $this->minimumSpendAmount;
    }

    /**
     * Returns the maximum amount that can be spent after tolerance adjustments.
     */
    public function maximumSpendAmount(): Money
    {
        return $this->maximumSpendAmount;
    }

    /**
     * Returns the tolerance value used by the graph search heuristic.
     */
    public function pathFinderTolerance(): float
    {
        return min(max($this->minimumTolerance, $this->maximumTolerance), 0.999999);
    }

    private function calculateBoundedSpend(float $multiplier): Money
    {
        if ($multiplier < 0.0) {
            throw new InvalidArgumentException('Spend multiplier must be non-negative.');
        }

        $scale = max($this->spendAmount->scale(), 8);
        $factor = self::floatToString($multiplier, $scale);

        $adjusted = $this->spendAmount->multiply($factor, $scale);

        return $adjusted->withScale($this->spendAmount->scale());
    }

    /**
     * @return numeric-string
     */
    private static function floatToString(float $value, int $scale): string
    {
        $formatted = sprintf('%.'.($scale + 2).'F', $value);
        BcMath::ensureNumeric($formatted);
        $normalized = BcMath::normalize($formatted, $scale);

        if ('-0' === $normalized) {
            return '0';
        }

        return $normalized;
    }
}
