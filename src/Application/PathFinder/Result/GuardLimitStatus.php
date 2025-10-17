<?php

declare(strict_types=1);

namespace SomeWork\P2PPathFinder\Application\PathFinder\Result;

final class GuardLimitStatus
{
    private readonly bool $expansionsReached;
    private readonly bool $visitedStatesReached;

    public function __construct(bool $expansionsReached, bool $visitedStatesReached)
    {
        $this->expansionsReached = $expansionsReached;
        $this->visitedStatesReached = $visitedStatesReached;
    }

    public static function none(): self
    {
        return new self(false, false);
    }

    public function expansionsReached(): bool
    {
        return $this->expansionsReached;
    }

    public function visitedStatesReached(): bool
    {
        return $this->visitedStatesReached;
    }

    public function anyLimitReached(): bool
    {
        return $this->expansionsReached || $this->visitedStatesReached;
    }
}
