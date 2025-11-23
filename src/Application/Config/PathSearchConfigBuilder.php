<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Config;

use SomeWork\P2PPathFinder\Domain\ValueObject\Money;
use SomeWork\P2PPathFinder\Domain\ValueObject\ToleranceWindow;
use SomeWork\P2PPathFinder\Exception\InvalidInput;
use SomeWork\P2PPathFinder\Exception\PrecisionViolation;

use function is_int;

/**
 * Fluent builder used to construct {@see PathSearchConfig} instances.
 *
 * @api
 */
final class PathSearchConfigBuilder
{
    private ?Money $spendAmount = null;

    private ?ToleranceWindow $toleranceWindow = null;

    private ?int $minimumHops = null;

    private ?int $maximumHops = null;

    private int $resultLimit = 1;

    private ?SearchGuardConfig $searchGuards = null;

    private bool $throwOnGuardLimit = false;

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
     *
     * @throws InvalidInput|PrecisionViolation when tolerance bounds are not valid numeric ratios
     */
    public function withToleranceBounds(string $minimumTolerance, string $maximumTolerance): self
    {
        $this->toleranceWindow = ToleranceWindow::fromStrings($minimumTolerance, $maximumTolerance);

        return $this;
    }

    /**
     * Configures the minimum and maximum allowed number of hops in a resulting path.
     *
     * @throws InvalidInput when the provided limits violate ordering constraints
     */
    public function withHopLimits(int $minimumHops, int $maximumHops): self
    {
        if ($minimumHops < 1) {
            throw new InvalidInput('Minimum hops must be at least one.');
        }

        if ($maximumHops < $minimumHops) {
            throw new InvalidInput('Maximum hops must be greater than or equal to minimum hops.');
        }

        $this->minimumHops = $minimumHops;
        $this->maximumHops = $maximumHops;

        return $this;
    }

    /**
     * Limits how many paths should be returned by the search service.
     *
     * @throws InvalidInput when the limit is less than one
     */
    public function withResultLimit(int $limit): self
    {
        if ($limit < 1) {
            throw new InvalidInput('Result limit must be at least one.');
        }

        $this->resultLimit = $limit;

        return $this;
    }

    /**
     * Configures limits that guard search explosion in dense graphs.
     *
     * @param int      $maxVisitedStates Maximum unique states to track (affects memory: ~1KB per state)
     * @param int      $maxExpansions    Maximum edge expansions (affects computation time)
     * @param int|null $timeBudgetMs     Optional wall-clock budget in milliseconds
     *
     * @throws InvalidInput when either guard limit is less than one
     */
    public function withSearchGuards(int $maxVisitedStates, int $maxExpansions, ?int $timeBudgetMs = null): self
    {
        $this->searchGuards = new SearchGuardConfig($maxVisitedStates, $maxExpansions, $timeBudgetMs);

        return $this;
    }

    /**
     * Configures an optional wall-clock budget (in milliseconds) for the path finder search.
     *
     * @throws InvalidInput when the provided budget is not positive
     */
    public function withSearchTimeBudget(?int $timeBudgetMs): self
    {
        $current = $this->searchGuards ?? SearchGuardConfig::defaults();
        $this->searchGuards = $current->withTimeBudget($timeBudgetMs);

        return $this;
    }

    public function withGuardLimitException(bool $shouldThrow = true): self
    {
        $this->throwOnGuardLimit = $shouldThrow;

        return $this;
    }

    /**
     * Builds a validated {@see PathSearchConfig} instance.
     *
     * @throws InvalidInput when required configuration pieces are missing or inconsistent
     */
    public function build(): PathSearchConfig
    {
        if (!$this->spendAmount instanceof Money) {
            throw new InvalidInput('Spend amount must be provided.');
        }

        if (!$this->toleranceWindow instanceof ToleranceWindow) {
            throw new InvalidInput('Tolerance bounds must be configured.');
        }

        if (!is_int($this->minimumHops) || !is_int($this->maximumHops)) {
            throw new InvalidInput('Hop limits must be configured.');
        }

        $searchGuards = $this->searchGuards ?? SearchGuardConfig::defaults();

        return new PathSearchConfig(
            $this->spendAmount,
            $this->toleranceWindow,
            $this->minimumHops,
            $this->maximumHops,
            $this->resultLimit,
            $searchGuards,
            throwOnGuardLimit: $this->throwOnGuardLimit,
        );
    }
}
