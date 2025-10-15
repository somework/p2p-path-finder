<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Config;

use InvalidArgumentException;
use SomeWork\P2PPathFinder\Application\PathFinder\PathFinder;
use SomeWork\P2PPathFinder\Domain\ValueObject\Money;

use function is_float;
use function is_int;

/**
 * Fluent builder used to construct {@see PathSearchConfig} instances.
 */
final class PathSearchConfigBuilder
{
    private ?Money $spendAmount = null;

    private ?float $minimumTolerance = null;

    private ?float $maximumTolerance = null;

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
    public function withToleranceBounds(float $minimumTolerance, float $maximumTolerance): self
    {
        if ($minimumTolerance < 0.0 || $minimumTolerance >= 1.0) {
            throw new InvalidArgumentException('Minimum tolerance must be in the [0, 1) range.');
        }

        if ($maximumTolerance < 0.0 || $maximumTolerance >= 1.0) {
            throw new InvalidArgumentException('Maximum tolerance must be in the [0, 1) range.');
        }

        $this->minimumTolerance = $minimumTolerance;
        $this->maximumTolerance = $maximumTolerance;

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
        );
    }
}
