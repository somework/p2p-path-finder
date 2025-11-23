<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Guard;

use Closure;
use SomeWork\P2PPathFinder\Application\PathFinder\Result\SearchGuardReport;

use function microtime;

/**
 * Coordinates wall-clock and expansion guard rails for the path search.
 *
 * @internal
 */
final class SearchGuards
{
    /**
     * @var Closure():float
     */
    private readonly Closure $clock;

    private int $expansions = 0;

    private bool $expansionLimitReached = false;

    private bool $timeBudgetReached = false;

    private float $startedAt;

    /**
     * @param Closure():float|callable():float|null $clock
     */
    public function __construct(
        private readonly int $maxExpansions,
        private readonly ?int $timeBudgetMs = null,
        ?callable $clock = null,
    ) {
        $clock ??= static fn (): float => microtime(true);

        $clockClosure = $clock instanceof Closure ? $clock : Closure::fromCallable($clock);
        $this->clock = $clockClosure;
        /** @var float $startTime */
        $startTime = $clockClosure();
        $this->startedAt = $startTime;
    }

    public function canExpand(): bool
    {
        if (null !== $this->timeBudgetMs && !$this->timeBudgetReached) {
            $clockClosure = $this->clock;
            /** @var float $now */
            $now = $clockClosure();
            $elapsedMilliseconds = ($now - $this->startedAt) * 1000.0;

            if ($elapsedMilliseconds >= (float) $this->timeBudgetMs) {
                $this->timeBudgetReached = true;

                return false;
            }
        }

        if ($this->expansionLimitReached) {
            return false;
        }

        if ($this->expansions >= $this->maxExpansions) {
            $this->expansionLimitReached = true;

            return false;
        }

        return true;
    }

    public function recordExpansion(): void
    {
        ++$this->expansions;
    }

    public function finalize(int $visitedStates, int $visitedStateLimit, bool $visitedGuardReached): SearchGuardReport
    {
        $clockClosure = $this->clock;
        /** @var float $now */
        $now = $clockClosure();
        $elapsedMilliseconds = ($now - $this->startedAt) * 1000.0;

        if (null !== $this->timeBudgetMs && !$this->timeBudgetReached && $elapsedMilliseconds >= (float) $this->timeBudgetMs) {
            $this->timeBudgetReached = true;
        }

        return SearchGuardReport::fromMetrics(
            expansions: $this->expansions,
            visitedStates: $visitedStates,
            elapsedMilliseconds: $elapsedMilliseconds,
            expansionLimit: $this->maxExpansions,
            visitedStateLimit: $visitedStateLimit,
            timeBudgetLimit: $this->timeBudgetMs,
            expansionLimitReached: $this->expansionLimitReached,
            visitedStatesReached: $visitedGuardReached,
            timeBudgetReached: $this->timeBudgetReached,
        );
    }
}
