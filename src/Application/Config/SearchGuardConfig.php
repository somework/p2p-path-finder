<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\Config;

use SomeWork\P2PPathFinder\Exception\InvalidInput;

/**
 * Immutable guard limits used by the {@see \SomeWork\P2PPathFinder\Application\Service\PathFinderService} guard mechanism.
 */
final class SearchGuardConfig
{
    public const DEFAULT_MAX_VISITED_STATES = 250000;

    public const DEFAULT_MAX_EXPANSIONS = 250000;

    public function __construct(
        private readonly int $maxVisitedStates = self::DEFAULT_MAX_VISITED_STATES,
        private readonly int $maxExpansions = self::DEFAULT_MAX_EXPANSIONS,
        private readonly ?int $timeBudgetMs = null,
    ) {
        if ($this->maxVisitedStates < 1) {
            throw new InvalidInput('Maximum visited states must be at least one.');
        }

        if ($this->maxExpansions < 1) {
            throw new InvalidInput('Maximum expansions must be at least one.');
        }

        if (null !== $this->timeBudgetMs && $this->timeBudgetMs < 1) {
            throw new InvalidInput('Time budget must be at least one millisecond.');
        }
    }

    public static function defaults(): self
    {
        return new self();
    }

    public function withTimeBudget(?int $timeBudgetMs): self
    {
        return new self($this->maxVisitedStates, $this->maxExpansions, $timeBudgetMs);
    }

    public function maxVisitedStates(): int
    {
        return $this->maxVisitedStates;
    }

    public function maxExpansions(): int
    {
        return $this->maxExpansions;
    }

    public function timeBudgetMs(): ?int
    {
        return $this->timeBudgetMs;
    }
}
