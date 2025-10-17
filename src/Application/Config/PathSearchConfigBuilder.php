<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Config;

use InvalidArgumentException;
use SomeWork\P2PPathFinder\Application\PathFinder\PathFinder;
use SomeWork\P2PPathFinder\Domain\ValueObject\BcMath;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

use function is_float;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Fluent builder used to construct {@see PathSearchConfig} instances.
 */
final class PathSearchConfigBuilder
{
    private ?Money $spendAmount = null;

    private ?float $minimumTolerance = null;

    private ?float $maximumTolerance = null;

    private ?string $pathFinderToleranceOverride = null;

    private ?int $minimumHops = null;

    private ?int $maximumHops = null;

    private int $resultLimit = 1;

    private ?int $maxVisitedStates = null;

    private ?int $maxExpansions = null;

    /**
     * Sets the amount of the source asset that will be spent during path search.
     */
    public function withSpendAmount(Money $amount): self
    {
        $this->spendAmount = $amount;

        return $this;
    }

    /**
     * Configures the acceptable relative deviation from the desired spend amount.
     */
    public function withToleranceBounds(float|string $minimumTolerance, float|string $maximumTolerance): self
    {
        [$minimumFloat, $minimumString] = $this->normalizeTolerance($minimumTolerance, 'Minimum tolerance');
        [$maximumFloat, $maximumString] = $this->normalizeTolerance($maximumTolerance, 'Maximum tolerance');

        $this->minimumTolerance = $minimumFloat;
        $this->maximumTolerance = $maximumFloat;

        if (is_string($minimumTolerance) || is_string($maximumTolerance)) {
            $this->pathFinderToleranceOverride = $this->resolvePathFinderTolerance($minimumString, $maximumString);
        } else {
            $this->pathFinderToleranceOverride = null;
        }

        return $this;
    }

    /**
     * Configures the minimum and maximum allowed number of hops in a resulting path.
     */
    public function withHopLimits(int $minimumHops, int $maximumHops): self
    {
        if ($minimumHops < 1) {
            throw new InvalidArgumentException('Minimum hops must be at least one.');
        }

        if ($maximumHops < $minimumHops) {
            throw new InvalidArgumentException('Maximum hops must be greater than or equal to minimum hops.');
        }

        $this->minimumHops = $minimumHops;
        $this->maximumHops = $maximumHops;

        return $this;
    }

    /**
     * Limits how many paths should be returned by the search service.
     */
    public function withResultLimit(int $limit): self
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Result limit must be at least one.');
        }

        $this->resultLimit = $limit;

        return $this;
    }

    /**
     * Configures limits that guard search explosion in dense graphs.
     */
    public function withSearchGuards(int $maxVisitedStates, int $maxExpansions): self
    {
        if ($maxVisitedStates < 1) {
            throw new InvalidArgumentException('Maximum visited states must be at least one.');
        }

        if ($maxExpansions < 1) {
            throw new InvalidArgumentException('Maximum expansions must be at least one.');
        }

        $this->maxVisitedStates = $maxVisitedStates;
        $this->maxExpansions = $maxExpansions;

        return $this;
    }

    /**
     * Builds a validated {@see PathSearchConfig} instance.
     */
    public function build(): PathSearchConfig
    {
        if (!$this->spendAmount instanceof Money) {
            throw new InvalidArgumentException('Spend amount must be provided.');
        }

        if (!is_float($this->minimumTolerance) || !is_float($this->maximumTolerance)) {
            throw new InvalidArgumentException('Tolerance bounds must be configured.');
        }

        if (!is_int($this->minimumHops) || !is_int($this->maximumHops)) {
            throw new InvalidArgumentException('Hop limits must be configured.');
        }

        $maxVisitedStates = $this->maxVisitedStates ?? PathFinder::DEFAULT_MAX_VISITED_STATES;
        $maxExpansions = $this->maxExpansions ?? PathFinder::DEFAULT_MAX_EXPANSIONS;

        return new PathSearchConfig(
            $this->spendAmount,
            $this->minimumTolerance,
            $this->maximumTolerance,
            $this->minimumHops,
            $this->maximumHops,
            $this->resultLimit,
            $maxExpansions,
            $maxVisitedStates,
            $this->pathFinderToleranceOverride,
        );
    }

    /**
     * @return array{0: float, 1: numeric-string}
     */
    private function normalizeTolerance(float|string $value, string $context): array
    {
        if (is_string($value)) {
            BcMath::ensureNumeric($value);
            $normalized = BcMath::normalize($value, self::PATH_FINDER_TOLERANCE_SCALE);

            if (BcMath::comp($normalized, '0', self::PATH_FINDER_TOLERANCE_SCALE) < 0 || BcMath::comp($normalized, '1', self::PATH_FINDER_TOLERANCE_SCALE) >= 0) {
                throw new InvalidArgumentException(sprintf('%s must be in the [0, 1) range.', $context));
            }

            $floatValue = (float) $value;
            if ($floatValue >= 1.0) {
                $floatValue = self::FLOAT_TOLERANCE_CAP;
            }

            return [$floatValue, $normalized];
        }

        if ($value < 0.0 || $value >= 1.0) {
            throw new InvalidArgumentException(sprintf('%s must be in the [0, 1) range.', $context));
        }

        $formatted = sprintf('%.'.self::PATH_FINDER_TOLERANCE_SCALE.'F', $value);
        $normalized = BcMath::normalize($formatted, self::PATH_FINDER_TOLERANCE_SCALE);

        return [$value, $normalized];
    }

    /**
     * @param numeric-string $minimum
     * @param numeric-string $maximum
     *
     * @return numeric-string
     */
    private function resolvePathFinderTolerance(string $minimum, string $maximum): string
    {
        if (BcMath::comp($minimum, $maximum, self::PATH_FINDER_TOLERANCE_SCALE) >= 0) {
            return $minimum;
        }

        return $maximum;
    }

    private const PATH_FINDER_TOLERANCE_SCALE = 18;

    private const FLOAT_TOLERANCE_CAP = 0.9999999999999999;
}
