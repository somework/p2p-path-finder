<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Result;

final class GuardLimitStatus
{
    private readonly bool $expansionsReached;
    private readonly bool $visitedStatesReached;
    private readonly bool $timeBudgetReached;

    public function __construct(bool $expansionsReached, bool $visitedStatesReached, bool $timeBudgetReached)
    {
        $this->expansionsReached = $expansionsReached;
        $this->visitedStatesReached = $visitedStatesReached;
        $this->timeBudgetReached = $timeBudgetReached;
    }

    public static function none(): self
    {
        return new self(false, false, false);
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
}
