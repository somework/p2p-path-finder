<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Config;

use InvalidArgumentException;
use SomeWork\P2PPathFinder\Application\PathFinder\PathFinder;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

use function max;

/**
 * Immutable configuration carrying constraints used by {@see PathFinderService}.
 */
final class PathSearchConfig
{
    private readonly Money $minimumSpendAmount;

    private readonly Money $maximumSpendAmount;

    /** @var numeric-string */
    private readonly string $minimumTolerance;

    /** @var numeric-string */
    private readonly string $maximumTolerance;

    /** @var numeric-string */
    private readonly string $pathFinderTolerance;

    public function __construct(
        private readonly Money $spendAmount,
        string $minimumTolerance,
        string $maximumTolerance,
        private readonly int $minimumHops,
        private readonly int $maximumHops,
        private readonly int $resultLimit = 1,
        private readonly int $pathFinderMaxExpansions = PathFinder::DEFAULT_MAX_EXPANSIONS,
        private readonly int $pathFinderMaxVisitedStates = PathFinder::DEFAULT_MAX_VISITED_STATES,
        ?string $pathFinderToleranceOverride = null,
    ) {
        $this->minimumTolerance = $this->assertTolerance($minimumTolerance, 'Minimum tolerance');
        $this->maximumTolerance = $this->assertTolerance($maximumTolerance, 'Maximum tolerance');

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

        $this->pathFinderTolerance = $this->resolvePathFinderTolerance($pathFinderToleranceOverride);

        $scale = max($this->spendAmount->scale(), self::BOUND_SCALE);
        $lowerMultiplier = BcMath::sub('1', $this->minimumTolerance, $scale);
        $upperMultiplier = BcMath::add('1', $this->maximumTolerance, $scale);

        $this->minimumSpendAmount = $this->spendAmount
            ->multiply($lowerMultiplier, $scale)
            ->withScale($this->spendAmount->scale());
        $this->maximumSpendAmount = $this->spendAmount
            ->multiply($upperMultiplier, $scale)
            ->withScale($this->spendAmount->scale());
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
     *
     * @return numeric-string
     */
    public function minimumTolerance(): string
    {
        return $this->minimumTolerance;
    }

    /**
     * Returns the upper relative tolerance bound expressed as a fraction.
     *
     * @return numeric-string
     */
    public function maximumTolerance(): string
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
     *
     * @return numeric-string
     */
    public function pathFinderTolerance(): string
    {
        return $this->pathFinderTolerance;
    }

    /**
     * @return numeric-string
     */
    private function assertTolerance(string $value, string $context): string
    {
        BcMath::ensureNumeric($value);
        $normalized = BcMath::normalize($value, self::TOLERANCE_SCALE);

        if (BcMath::comp($normalized, '0', self::TOLERANCE_SCALE) < 0 || BcMath::comp($normalized, '1', self::TOLERANCE_SCALE) >= 0) {
            throw new InvalidArgumentException($context.' must be in the [0, 1) range.');
        }

        return $normalized;
    }

    /**
     * @return numeric-string
     */
    private function resolvePathFinderTolerance(?string $override): string
    {
        if (null !== $override) {
            $normalized = $this->assertTolerance($override, 'Path finder tolerance');

            return $normalized;
        }

        return BcMath::comp($this->minimumTolerance, $this->maximumTolerance, self::TOLERANCE_SCALE) >= 0
            ? $this->minimumTolerance
            : $this->maximumTolerance;
    }

    private const TOLERANCE_SCALE = 18;
    private const BOUND_SCALE = 8;
}
