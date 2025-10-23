<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Result;

/**
 * Immutable snapshot describing how the search interacted with its guard rails.
 */
final class SearchGuardReport
{
    private readonly bool $expansionsReached;

    private readonly bool $visitedStatesReached;

    private readonly bool $timeBudgetReached;

    private readonly int $expansions;

    private readonly int $visitedStates;

    private readonly float $elapsedMilliseconds;

    private readonly int $expansionLimit;

    private readonly int $visitedStateLimit;

    private readonly ?int $timeBudgetLimit;

    public function __construct(
        bool $expansionsReached,
        bool $visitedStatesReached,
        bool $timeBudgetReached,
        int $expansions,
        int $visitedStates,
        float $elapsedMilliseconds,
        int $expansionLimit,
        int $visitedStateLimit,
        ?int $timeBudgetLimit,
    ) {
        $this->expansionsReached = $expansionsReached;
        $this->visitedStatesReached = $visitedStatesReached;
        $this->timeBudgetReached = $timeBudgetReached;
        $this->expansions = $expansions;
        $this->visitedStates = $visitedStates;
        $this->elapsedMilliseconds = $elapsedMilliseconds;
        $this->expansionLimit = $expansionLimit;
        $this->visitedStateLimit = $visitedStateLimit;
        $this->timeBudgetLimit = $timeBudgetLimit;
    }

    public static function idle(int $maxVisitedStates, int $maxExpansions, ?int $timeBudgetMs = null): self
    {
        return new self(
            expansionsReached: false,
            visitedStatesReached: false,
            timeBudgetReached: false,
            expansions: 0,
            visitedStates: 0,
            elapsedMilliseconds: 0.0,
            expansionLimit: $maxExpansions,
            visitedStateLimit: $maxVisitedStates,
            timeBudgetLimit: $timeBudgetMs,
        );
    }

    public static function none(): self
    {
        return new self(false, false, false, 0, 0, 0.0, 0, 0, null);
    }

    public function expansionsReached(): bool
    {
        return $this->expansionsReached;
    }

    public function visitedStatesReached(): bool
    {
        return $this->visitedStatesReached;
    }

    public function timeBudgetReached(): bool
    {
        return $this->timeBudgetReached;
    }

    public function anyLimitReached(): bool
    {
        return $this->expansionsReached || $this->visitedStatesReached || $this->timeBudgetReached;
    }

    public function expansions(): int
    {
        return $this->expansions;
    }

    public function visitedStates(): int
    {
        return $this->visitedStates;
    }

    public function elapsedMilliseconds(): float
    {
        return $this->elapsedMilliseconds;
    }

    public function expansionLimit(): int
    {
        return $this->expansionLimit;
    }

    public function visitedStateLimit(): int
    {
        return $this->visitedStateLimit;
    }

    public function timeBudgetLimit(): ?int
    {
        return $this->timeBudgetLimit;
    }
}
