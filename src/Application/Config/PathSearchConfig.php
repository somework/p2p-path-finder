<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Config;

use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Domain\ValueObject\ToleranceWindow;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

use function max;

/**
 * Immutable configuration carrying constraints used by {@see PathFinderService}.
 */
final class PathSearchConfig
{
    private readonly Money $minimumSpendAmount;

    private readonly Money $maximumSpendAmount;

    /** @var numeric-string */
    private readonly string $pathFinderTolerance;

    /** @var 'override'|'minimum'|'maximum' */
    private readonly string $pathFinderToleranceSource;

    private readonly SearchGuardConfig $searchGuards;

    private readonly bool $throwOnGuardLimit;

    /**
     * @throws InvalidInput|PrecisionViolation when one of the provided guard or tolerance constraints is invalid
     */
    public function __construct(
        private readonly Money $spendAmount,
        private readonly ToleranceWindow $toleranceWindow,
        private readonly int $minimumHops,
        private readonly int $maximumHops,
        private readonly int $resultLimit = 1,
        ?SearchGuardConfig $searchGuards = null,
        ?string $pathFinderToleranceOverride = null,
        bool $throwOnGuardLimit = false,
    ) {
        if ($minimumHops < 1) {
            throw new InvalidInput('Minimum hops must be at least one.');
        }

        if ($maximumHops < $minimumHops) {
            throw new InvalidInput('Maximum hops must be greater than or equal to minimum hops.');
        }

        if ($resultLimit < 1) {
            throw new InvalidInput('Result limit must be at least one.');
        }

        $this->searchGuards = $searchGuards ?? SearchGuardConfig::defaults();

        [$tolerance, $source] = $this->resolvePathFinderTolerance($pathFinderToleranceOverride);

        $this->pathFinderTolerance = $tolerance;
        $this->pathFinderToleranceSource = $source;
        $this->throwOnGuardLimit = $throwOnGuardLimit;

        $scale = max($this->spendAmount->scale(), self::BOUND_SCALE);
        $lowerMultiplier = BcMath::sub('1', $this->toleranceWindow->minimum(), $scale);
        $upperMultiplier = BcMath::add('1', $this->toleranceWindow->maximum(), $scale);

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
     * Returns the tolerance window applied to the spend amount.
     */
    public function toleranceWindow(): ToleranceWindow
    {
        return $this->toleranceWindow;
    }

    /**
     * Returns the lower relative tolerance bound expressed as a fraction.
     *
     * @return numeric-string
     */
    public function minimumTolerance(): string
    {
        return $this->toleranceWindow->minimum();
    }

    /**
     * Returns the upper relative tolerance bound expressed as a fraction.
     *
     * @return numeric-string
     */
    public function maximumTolerance(): string
    {
        return $this->toleranceWindow->maximum();
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
        return $this->searchGuards->maxExpansions();
    }

    /**
     * Returns the maximum number of unique state signatures tracked during search.
     */
    public function pathFinderMaxVisitedStates(): int
    {
        return $this->searchGuards->maxVisitedStates();
    }

    public function pathFinderTimeBudgetMs(): ?int
    {
        return $this->searchGuards->timeBudgetMs();
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
     * Returns the origin of the path finder tolerance value.
     *
     * @return 'override'|'minimum'|'maximum'
     */
    public function pathFinderToleranceSource(): string
    {
        return $this->pathFinderToleranceSource;
    }

    public function throwOnGuardLimit(): bool
    {
        return $this->throwOnGuardLimit;
    }

    /**
     * @throws InvalidInput|PrecisionViolation when the override value does not represent a valid tolerance
     *
     * @return array{0: numeric-string, 1: 'override'|'minimum'|'maximum'}
     */
    private function resolvePathFinderTolerance(?string $override): array
    {
        if (null !== $override) {
            $normalized = ToleranceWindow::normalizeTolerance($override, 'Path finder tolerance');

            return [$normalized, 'override'];
        }

        return [
            $this->toleranceWindow->heuristicTolerance(),
            $this->toleranceWindow->heuristicSource(),
        ];
    }

    private const BOUND_SCALE = 8;
}
