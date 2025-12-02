<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathSearch\Result;

use JsonSerializable;

/**
 * Immutable snapshot describing how the search interacted with its guard rails.
 *
 * @api
 */
final class SearchGuardReport implements JsonSerializable
{
    /**
     * @psalm-type SearchGuardReportJson = array{
     *     limits: array{expansions: int, visited_states: int, time_budget_ms: int|null},
     *     metrics: array{expansions: int, visited_states: int, elapsed_ms: float},
     *     breached: array{expansions: bool, visited_states: bool, time_budget: bool, any: bool}
     * }
     */
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

    public static function fromMetrics(
        int $expansions,
        int $visitedStates,
        float $elapsedMilliseconds,
        int $expansionLimit,
        int $visitedStateLimit,
        ?int $timeBudgetLimit,
        bool $expansionLimitReached = false,
        bool $visitedStatesReached = false,
        bool $timeBudgetReached = false,
    ): self {
        if ($expansions < 0) {
            $expansions = 0;
        }

        if ($visitedStates < 0) {
            $visitedStates = 0;
        }

        if ($elapsedMilliseconds < 0.0) {
            $elapsedMilliseconds = 0.0;
        }

        if ($expansionLimit < 0) {
            $expansionLimit = 0;
        }

        if ($visitedStateLimit < 0) {
            $visitedStateLimit = 0;
        }

        if (null !== $timeBudgetLimit && $timeBudgetLimit < 0) {
            $timeBudgetLimit = 0;
        }

        $expansionLimitReached = $expansionLimitReached || ($expansionLimit > 0 && $expansions >= $expansionLimit);
        $visitedStatesReached = $visitedStatesReached || ($visitedStateLimit > 0 && $visitedStates >= $visitedStateLimit);
        $timeBudgetReached = $timeBudgetReached || (null !== $timeBudgetLimit && $elapsedMilliseconds >= (float) $timeBudgetLimit);

        return new self(
            expansionsReached: $expansionLimitReached,
            visitedStatesReached: $visitedStatesReached,
            timeBudgetReached: $timeBudgetReached,
            expansions: $expansions,
            visitedStates: $visitedStates,
            elapsedMilliseconds: $elapsedMilliseconds,
            expansionLimit: $expansionLimit,
            visitedStateLimit: $visitedStateLimit,
            timeBudgetLimit: $timeBudgetLimit,
        );
    }

    public static function idle(int $maxVisitedStates, int $maxExpansions, ?int $timeBudgetMs = null): self
    {
        return self::fromMetrics(
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
        return self::fromMetrics(0, 0, 0.0, 0, 0, null);
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

    /**
     * @return array{
     *     limits: array{expansions: int, visited_states: int, time_budget_ms: int|null},
     *     metrics: array{expansions: int, visited_states: int, elapsed_ms: float},
     *     breached: array{expansions: bool, visited_states: bool, time_budget: bool, any: bool}
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'limits' => [
                'expansions' => $this->expansionLimit,
                'visited_states' => $this->visitedStateLimit,
                'time_budget_ms' => $this->timeBudgetLimit,
            ],
            'metrics' => [
                'expansions' => $this->expansions,
                'visited_states' => $this->visitedStates,
                'elapsed_ms' => $this->elapsedMilliseconds,
            ],
            'breached' => [
                'expansions' => $this->expansionsReached,
                'visited_states' => $this->visitedStatesReached,
                'time_budget' => $this->timeBudgetReached,
                'any' => $this->anyLimitReached(),
            ],
        ];
    }
}
